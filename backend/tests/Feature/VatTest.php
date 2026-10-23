<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * VAT: stores registered for VAT charge it inside their prices. Orders keep the rate and VAT number
 * from the day they were placed; stores not registered charge none.
 */
class VatTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    private function registerForVat(string $number = '1234567890'): void
    {
        $this->seller->forceFill(['vat_registered' => true, 'vat_number' => $number, 'tin_number' => '0012345678'])->save();
    }

    public function test_vat_inside_a_price_is_rounded_to_the_santim(): void
    {
        $this->assertSame(1500, Order::vatIncluded(11500, 1500));
        $this->assertSame(0, Order::vatIncluded(1, 1500));
        $this->assertSame(1, Order::vatIncluded(4, 1500)); // 0.52 santim rounds up
        $this->assertSame(0, Order::vatIncluded(100000, 0));
        $this->assertSame(0, Order::vatIncluded(-500, 1500));
        // Large totals do not overflow.
        $this->assertSame(1_200_000_000_000_000, Order::vatIncluded(9_200_000_000_000_000, 1500));
    }

    public function test_a_store_not_registered_for_vat_charges_none(): void
    {
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 1])->assertCreated();
        $this->getJson('/api/cart?address_id='.$this->address->id)->assertOk()
            ->assertJsonPath('data.groups.0.vat_rate_bp', 0)
            ->assertJsonPath('data.groups.0.vat_included_santim', 0)
            ->assertJsonPath('data.groups.0.seller.vat_number', null)
            ->assertJsonPath('data.vat_included_santim', 0);
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => 1_030_000], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $order = Order::latest('id')->first();
        $this->assertSame(0, $order->vat_rate_bp);
        $this->getJson("/api/orders/{$order->id}")->assertOk()->assertJsonPath('data.vat', null);
        $this->getJson("/api/products/{$this->sofa->slug}")->assertOk()->assertJsonPath('data.seller.vat', null);
    }

    public function test_a_registered_store_charges_vat_inside_the_same_total(): void
    {
        $this->registerForVat();
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 2])->assertCreated();
        // 2 x ETB 10,000 + ETB 300 delivery = ETB 20,300, of which 15/115 is VAT.
        $this->getJson('/api/cart?address_id='.$this->address->id)->assertOk()
            ->assertJsonPath('data.grand_total_santim', 2_030_000)
            ->assertJsonPath('data.groups.0.vat_rate_bp', 1500)
            ->assertJsonPath('data.groups.0.vat_included_santim', 264_783)
            ->assertJsonPath('data.groups.0.seller.vat_number', '1234567890')
            ->assertJsonPath('data.vat_included_santim', 264_783);
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => 2_030_000], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $order = Order::latest('id')->first();
        $this->assertSame(2_030_000, $order->total_santim);
        // Commission stays on the item prices as before.
        $this->assertSame(200_000, $order->commission_santim);

        $this->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.vat.rate_percent', 15)
            ->assertJsonPath('data.vat.included_santim', 264_783)
            ->assertJsonPath('data.vat.number', '1234567890')
            ->assertJsonPath('data.seller.tin_number', '0012345678');
        $this->as($this->seller->user)->getJson("/api/seller/orders/{$order->id}")->assertOk()->assertJsonPath('data.vat.included_santim', 264_783);
        $this->as($this->admin())->getJson("/api/admin/orders/{$order->id}")->assertOk()->assertJsonPath('data.vat.included_santim', 264_783);

        // A new rate applies to new orders only; the store leaving VAT does not rewrite the old order either.
        Setting::updateOrCreate(['key' => 'vat_rate_percent'], ['value' => '10']);
        $this->seller->forceFill(['vat_registered' => false, 'vat_number' => null])->save();
        $this->as($this->buyer)->getJson("/api/orders/{$order->id}")->assertJsonPath('data.vat.rate_percent', 15)->assertJsonPath('data.vat.number', '1234567890');
        $this->registerForVat('999');
        $second = $this->placeOrder(1);
        $this->assertSame(1000, $second->vat_rate_bp);
        $this->assertSame('999', $second->vat_number);
        $this->assertSame(Order::vatIncluded($second->total_santim, 1000), $second->vatSantim());
    }

    public function test_listing_and_store_pages_show_the_vat_number(): void
    {
        $this->registerForVat();
        $this->getJson("/api/products/{$this->sofa->slug}")->assertOk()
            ->assertJsonPath('data.seller.vat.rate_percent', 15)->assertJsonPath('data.seller.vat.number', '1234567890');
        // Lists load a few store columns; they leave VAT out instead of failing.
        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.seller.vat', null);
        $this->getJson("/api/sellers/{$this->seller->slug}")->assertOk()->assertJsonPath('data.vat_number', '1234567890');
        $this->getJson("/api/sellers/{$this->seller->slug}")->assertJsonMissingPath('data.tin_number');
    }

    public function test_stores_declare_vat_when_applying_and_admins_change_it_after(): void
    {
        $apply = fn (array $extra) => $this->postJson('/api/auth/register-seller', array_merge([
            'name' => 'Sara Bekele', 'email' => 'sara@example.com', 'password' => 'secret-123', 'password_confirmation' => 'secret-123',
            'store_name' => 'Sara Woodworks', 'business_type' => 'workshop', 'store_phone' => '0933 111 222', 'area_id' => $this->area()->id,
        ], $extra));
        $apply(['vat_registered' => true])->assertUnprocessable()->assertJsonValidationErrors(['vat_number', 'tin_number']);
        $apply(['vat_registered' => true, 'vat_number' => '<b>', 'tin_number' => '1'])->assertUnprocessable()->assertJsonValidationErrors('vat_number');
        $apply(['vat_registered' => true, 'vat_number' => ' 778899 ', 'tin_number' => '0099'])->assertCreated();
        $store = User::where('email', 'sara@example.com')->first()->sellerProfile;
        $this->assertTrue($store->vat_registered);
        $this->assertSame('778899', $store->vat_number);

        // While pending the store can still correct it.
        $this->as($store->user)->putJson('/api/seller/profile', ['vat_registered' => false])->assertOk()->assertJsonPath('data.vat_registered', false)->assertJsonPath('data.vat_number', null);

        // Once approved only an admin changes it, and it is logged.
        $admin = $this->admin();
        $this->as($admin)->postJson("/api/admin/sellers/{$store->id}/approve")->assertOk();
        $this->as($store->user)->putJson('/api/seller/profile', ['vat_registered' => true, 'vat_number' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['vat_registered', 'vat_number']);
        // Sending the same values back (a full form save) is fine.
        $this->putJson('/api/seller/profile', ['vat_registered' => false, 'vat_number' => '', 'description' => 'Hand made'])->assertOk();

        $this->as($admin)->putJson("/api/admin/sellers/{$store->id}", ['vat_registered' => true])->assertUnprocessable()->assertJsonValidationErrors('vat_number');
        $this->putJson("/api/admin/sellers/{$store->id}", ['vat_registered' => true, 'vat_number' => '5544'])->assertOk()
            ->assertJsonPath('data.vat_registered', true)->assertJsonPath('data.vat_number', '5544');
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.details_changed', 'subject_id' => $store->id]);
        $this->putJson("/api/admin/sellers/{$store->id}", ['vat_registered' => false])->assertOk()->assertJsonPath('data.vat_number', null);
        $this->as($store->user)->putJson("/api/admin/sellers/{$store->id}", ['vat_registered' => true, 'vat_number' => '1'])->assertForbidden();
    }

    public function test_admin_sets_the_rate_and_the_sales_export_shows_vat(): void
    {
        $this->as($this->admin());
        $this->getJson('/api/admin/settings')->assertJsonPath('data.vat_rate_percent', 15);
        $this->putJson('/api/admin/settings', ['vat_rate_percent' => 51])->assertUnprocessable()->assertJsonValidationErrors('vat_rate_percent');
        $this->putJson('/api/admin/settings', ['vat_rate_percent' => 'x'])->assertUnprocessable();
        $this->putJson('/api/admin/settings', ['vat_rate_percent' => 15.5])->assertOk()->assertJsonPath('data.vat_rate_percent', 15.5);
        $this->assertSame(1550, Setting::vatRateBp());

        $this->registerForVat();
        $order = $this->placeOrder(1);
        $csv = $this->as($this->admin())->get('/api/admin/reports/orders.csv?from='.now('Africa/Addis_Ababa')->toDateString())->streamedContent();
        $this->assertStringContainsString('vat_rate_percent,vat_included_etb,store_vat_number', $csv);
        $this->assertStringContainsString('15.50,'.number_format($order->vatSantim() / 100, 2, '.', '').',1234567890', $csv);
    }
}
