<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SellerLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * VAT is added on top at checkout on every order, on the items and the delivery fee, and shown as
 * its own line. Orders keep the rate from the day they were placed. A store's VAT number is recorded
 * on the order only when the store is registered for VAT.
 */
class VatTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.tax.vat_rate_percent' => 15, 'marketplace.payments.gateway' => 'fake', 'marketplace.payments.window_minutes' => 30]);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->setUpOrders();
        Cache::flush();
    }

    private function registerForVat(string $number = '1234567890'): void
    {
        $this->seller->forceFill(['vat_registered' => true, 'vat_number' => $number, 'tin_number' => '0012345678'])->save();
    }

    private function cart(int $qty = 2, array $fulfillment = []): TestResponse
    {
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => $qty])->assertCreated();

        return $this->getJson('/api/cart?address_id='.$this->address->id.'&'.http_build_query(['fulfillment' => $fulfillment]))->assertOk();
    }

    private function checkout(int $expected, string $method = 'cod', array $fulfillment = []): TestResponse
    {
        return $this->as($this->buyer)->postJson('/api/checkout', [
            'address_id' => $this->address->id, 'payment_method' => $method, 'fulfillment' => $fulfillment, 'expected_total_santim' => $expected,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_vat_on_top_is_rounded_half_up_to_the_santim(): void
    {
        $this->assertSame(1500, Order::vatOn(10000, 1500));
        $this->assertSame(0, Order::vatOn(3, 1500)); // 0.45 santim
        $this->assertSame(1, Order::vatOn(4, 1500)); // 0.6 santim
        $this->assertSame(2, Order::vatOn(10, 1500)); // 1.5 santim rounds up
        $this->assertSame(0, Order::vatOn(100000, 0));
        $this->assertSame(0, Order::vatOn(-500, 1500));
        $this->assertSame(1550, Order::vatOn(10001, 1550));
        $this->assertSame(304_500, Order::vatOn(2_030_000, 1500));
        // Large totals do not overflow.
        $this->assertSame(1_350_000_000_000_000, Order::vatOn(9_000_000_000_000_000, 1500));

        // The part inside a price, for orders placed before VAT moved to checkout.
        $this->assertSame(1500, Order::vatInside(11500, 1500));
        $this->assertSame(264_783, Order::vatInside(2_030_000, 1500));
        $this->assertSame(0, Order::vatInside(0, 1500));
        $this->assertSame(1_200_000_000_000_000, Order::vatInside(9_200_000_000_000_000, 1500));
    }

    public function test_every_order_gets_vat_on_items_and_delivery_even_from_a_store_not_registered(): void
    {
        // 2 x ETB 10,000 + ETB 300 delivery = ETB 20,300; 15% VAT = ETB 3,045; total ETB 23,345.
        $this->cart(2)
            ->assertJsonPath('data.items_total_santim', 2_000_000)
            ->assertJsonPath('data.delivery_total_santim', 30_000)
            ->assertJsonPath('data.vat_total_santim', 304_500)
            ->assertJsonPath('data.vat_rate_percent', 15)
            ->assertJsonPath('data.grand_total_santim', 2_334_500)
            ->assertJsonPath('data.groups.0.vat_rate_bp', 1500)
            ->assertJsonPath('data.groups.0.vat_santim', 304_500)
            ->assertJsonPath('data.groups.0.total_santim', 2_334_500)
            ->assertJsonPath('data.groups.0.seller.vat_number', null);

        // The total without VAT is no longer what the customer agreed to.
        $this->checkout(2_030_000)->assertStatus(409);
        $this->checkout(2_334_500)->assertCreated();

        $order = Order::sole();
        $this->assertSame(1500, $order->vat_rate_bp);
        $this->assertSame(304_500, $order->vat_santim);
        $this->assertSame(2_334_500, $order->total_santim);
        $this->assertNull($order->vat_number);
        // Commission stays on the item prices only.
        $this->assertSame(200_000, $order->commission_santim);

        $vat = ['rate_percent' => 15, 'amount_santim' => 304_500, 'included' => false, 'number' => null];
        $this->getJson("/api/orders/{$order->id}")->assertOk()->assertJsonPath('data.vat', $vat)->assertJsonPath('data.total_santim', 2_334_500);
        $this->as($this->seller->user)->getJson("/api/seller/orders/{$order->id}")->assertOk()->assertJsonPath('data.vat', $vat);
        $this->as($this->admin())->getJson("/api/admin/orders/{$order->id}")->assertOk()->assertJsonPath('data.vat', $vat);
    }

    public function test_a_registered_store_has_its_vat_number_on_the_order_and_the_same_vat(): void
    {
        $this->registerForVat();
        $this->cart(2)->assertJsonPath('data.groups.0.seller.vat_number', '1234567890')->assertJsonPath('data.grand_total_santim', 2_334_500);
        $this->checkout(2_334_500)->assertCreated();
        $order = Order::sole();
        $this->assertSame('1234567890', $order->vat_number);
        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.vat.number', '1234567890')->assertJsonPath('data.seller.tin_number', '0012345678');

        // The store's receipt shows the same VAT once confirmed.
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $this->getJson("/api/seller/erp/sales/orders/{$order->id}")->assertOk()->assertJsonPath('data.vat.amount_santim', 304_500);
    }

    public function test_pickup_pays_vat_on_the_items_only(): void
    {
        $this->cart(1, [$this->seller->id => 'pickup'])
            ->assertJsonPath('data.delivery_total_santim', 0)
            ->assertJsonPath('data.vat_total_santim', 150_000)
            ->assertJsonPath('data.grand_total_santim', 1_150_000);
    }

    public function test_vat_is_worked_out_again_when_the_store_sets_the_delivery_fee(): void
    {
        $this->sofa->forceFill(['delivery_fee_santim' => null])->save();
        // No fee yet: VAT on the items for now.
        $this->cart(2)
            ->assertJsonPath('data.delivery_to_confirm', true)
            ->assertJsonPath('data.vat_total_santim', 300_000)
            ->assertJsonPath('data.grand_total_santim', 2_300_000);
        // Cannot be paid online before the fee is known.
        $this->checkout(2_300_000, 'online')->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $mailLines = [];
        Event::listen(NotificationSent::class, function (NotificationSent $e) use (&$mailLines) {
            if ($e->notification instanceof Alert && $e->notification->kind === 'checkout.placed') {
                $mailLines = $e->notification->mailLines;
            }
        });
        $this->checkout(2_300_000)->assertCreated();
        $order = Order::sole();
        $this->assertSame(300_000, $order->vat_santim);
        $this->assertStringContainsString(': ETB 23,000 plus delivery and its VAT', implode("\n", $mailLines));

        // A new rate after the order was placed does not change it.
        Setting::updateOrCreate(['key' => 'vat_rate_percent'], ['value' => '10']);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '500'])->assertOk();
        $order->refresh();
        // ETB 20,000 + ETB 500 = ETB 20,500; 15% = ETB 3,075.
        $this->assertSame(50_000, $order->delivery_fee_santim);
        $this->assertSame(307_500, $order->vat_santim);
        $this->assertSame(2_357_500, $order->total_santim);
        $this->assertSame(1500, $order->vat_rate_bp);
        $this->assertSame(200_000, $order->commission_santim);

        // The next order uses the new rate.
        $this->sofa->forceFill(['delivery_fee_santim' => 30_000])->save();
        $this->cart(1)->assertJsonPath('data.vat_rate_percent', 10)->assertJsonPath('data.vat_total_santim', 103_000)->assertJsonPath('data.grand_total_santim', 1_133_000);
    }

    public function test_online_payment_refunds_and_the_store_balance_include_vat(): void
    {
        $this->cart(2);
        $this->checkout(2_334_500, 'online')->assertCreated();
        $this->assertSame(2_334_500, Payment::sole()->amount_santim);
        $this->as($this->buyer)->postJson('/api/payments/test/'.Payment::sole()->tx_ref, ['outcome' => 'paid'])->assertOk();
        $order = Order::sole();
        $this->assertSame(2_334_500, $order->paid_santim);

        // Delivered: the store is owed what the customer paid, VAT included, less commission on the items.
        $this->deliver($order);
        $this->assertSame(['commission' => 200_000, 'sale' => -2_334_500], SellerLedgerEntry::where('order_id', $order->id)->pluck('amount_santim', 'type')->all());
        $this->as($this->seller->user)->getJson('/api/seller/balance')->assertOk()->assertJsonPath('data.owed_to_seller_santim', 2_134_500);

        // A paid order cancelled before delivery refunds everything paid, VAT included.
        $this->cart(1);
        $this->checkout(1_184_500, 'online')->assertCreated();
        $second = Order::latest('id')->first();
        $this->postJson('/api/payments/test/'.Payment::latest('id')->first()->tx_ref, ['outcome' => 'paid'])->assertOk();
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$second->id}/cancel", ['reason' => 'Damaged in the workshop'])->assertOk();
        $this->assertSame(1_184_500, Refund::sole()->amount_santim);
    }

    public function test_store_finance_counts_vat_as_cash_in_but_not_as_sales(): void
    {
        $this->deliver($this->placeOrder(2));
        $t = $this->as($this->seller->user)->getJson('/api/seller/erp/finance?period=30d')->assertOk()->json('data.total');
        // VAT belongs to the tax office, not to the store's sales.
        $this->assertSame(2_030_000, $t['marketplace_items_santim'] + $t['marketplace_delivery_santim']);
        $this->assertSame(2_334_500, $t['in_cash_on_delivery_santim']);
    }

    public function test_a_zero_rate_means_no_vat_line(): void
    {
        Setting::updateOrCreate(['key' => 'vat_rate_percent'], ['value' => '0']);
        $this->cart(2)->assertJsonPath('data.vat_total_santim', 0)->assertJsonPath('data.grand_total_santim', 2_030_000);
        $this->checkout(2_030_000)->assertCreated();
        $order = Order::sole();
        $this->assertSame(0, $order->vat_santim);
        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.vat', null);
    }

    public function test_orders_placed_with_vat_inside_the_price_keep_their_totals(): void
    {
        $migration = require database_path('migrations/2026_10_24_300000_add_vat_amount_to_orders.php');
        $this->sofa->forceFill(['delivery_fee_santim' => null])->save();
        $old = $this->placeOrder(2);
        $this->sofa->forceFill(['delivery_fee_santim' => 30_000])->save();
        $oldDelivered = $this->placeOrder(2);
        $noVat = $this->placeOrder(1);

        // As they were before this change: VAT inside the total, no amount column.
        $migration->down();
        DB::table('orders')->where('id', $old->id)->update(['total_santim' => 2_000_000]);
        DB::table('orders')->where('id', $oldDelivered->id)->update(['total_santim' => 2_030_000]);
        DB::table('orders')->where('id', $noVat->id)->update(['vat_rate_bp' => 0, 'total_santim' => 1_030_000]);
        $migration->up();

        $this->assertSame(264_783, $oldDelivered->fresh()->vat_santim);
        $this->assertSame(260_870, $old->fresh()->vat_santim);
        $this->assertSame(0, $noVat->fresh()->vat_santim);
        $this->as($this->buyer)->getJson("/api/orders/{$oldDelivered->id}")->assertJsonPath('data.vat.included', true)
            ->assertJsonPath('data.vat.amount_santim', 264_783)->assertJsonPath('data.total_santim', 2_030_000);
        $this->getJson("/api/orders/{$noVat->id}")->assertJsonPath('data.vat', null);

        // Setting the delivery fee on an old order keeps VAT inside, so the customer pays no more than agreed plus delivery.
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$old->id}/confirm", ['delivery_fee' => '300'])->assertOk();
        $old->refresh();
        $this->assertSame(2_030_000, $old->total_santim);
        $this->assertSame(264_783, $old->vat_santim);
        $this->assertTrue($old->vatSummary()['included']);
    }

    public function test_store_pages_show_the_vat_number_and_listings_no_longer_carry_vat(): void
    {
        $this->registerForVat();
        $this->getJson("/api/products/{$this->sofa->slug}")->assertOk()->assertJsonMissingPath('data.seller.vat');
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
        $this->putJson('/api/admin/settings', ['vat_rate_percent' => -1])->assertUnprocessable()->assertJsonValidationErrors('vat_rate_percent');
        $this->putJson('/api/admin/settings', ['vat_rate_percent' => 'x'])->assertUnprocessable();
        $this->putJson('/api/admin/settings', ['vat_rate_percent' => 15.5])->assertOk()->assertJsonPath('data.vat_rate_percent', 15.5);
        $this->assertSame(1550, Setting::vatRateBp());

        $this->registerForVat();
        $order = $this->placeOrder(1);
        // ETB 10,300 x 15.5% = ETB 1,596.50.
        $this->assertSame(159_650, $order->vat_santim);
        $this->assertSame(1_189_650, $order->total_santim);
        $csv = $this->as($this->admin())->get('/api/admin/reports/orders.csv?from='.now('Africa/Addis_Ababa')->toDateString())->streamedContent();
        $this->assertStringContainsString('total_etb,vat_rate_percent,vat_etb,store_vat_number', $csv);
        $this->assertStringContainsString('11896.50,15.50,1596.50,1234567890', $csv);
    }
}
