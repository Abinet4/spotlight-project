<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * What stays with the marketplace team rather than the store: the store's checked identity
 * (name, TIN), public review replies from a store that is not active, and delivery prices.
 */
class SellerScopeTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        // The production default: the marketplace prices delivery.
        config(['marketplace.distance.stores_set_fee' => false]);
    }

    public function test_an_approved_store_cannot_rename_itself_or_change_its_tin(): void
    {
        $this->seller->forceFill(['tin_number' => '0012345678'])->save();
        $this->as($this->seller->user);

        $this->putJson('/api/seller/profile', ['store_name' => 'Totally Different Store', 'tin_number' => '999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'store_name' => 'Your store name was checked when the store was approved. Ask the marketplace team to change it.',
                'tin_number' => 'Your TIN number was checked',
            ]);
        $this->assertNotSame('Totally Different Store', $this->seller->fresh()->store_name);

        // The form sends every field back: the same name and TIN, with other edits, are fine.
        $this->putJson('/api/seller/profile', [
            'store_name' => $this->seller->store_name, 'tin_number' => '0012345678',
            'description' => 'Hand-made beds.', 'phone' => '+251911999888',
        ])->assertOk()->assertJsonPath('data.description', 'Hand-made beds.')->assertJsonPath('data.phone', '+251911999888');

        // Clearing the TIN counts as a change too.
        $this->putJson('/api/seller/profile', ['tin_number' => null])->assertUnprocessable()->assertJsonValidationErrors('tin_number');
        $this->assertSame('0012345678', $this->seller->fresh()->tin_number);
    }

    public function test_a_suspended_store_is_locked_too_but_an_application_can_still_be_fixed(): void
    {
        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->as($this->seller->user)->putJson('/api/seller/profile', ['store_name' => 'New Name'])
            ->assertUnprocessable()->assertJsonValidationErrors('store_name');

        foreach (['pending', 'rejected'] as $status) {
            $applicant = $this->pendingSeller();
            $applicant->forceFill(['status' => $status])->save();
            $this->as($applicant->user)->putJson('/api/seller/profile', ['store_name' => "Fixed $status name", 'tin_number' => '0099'])
                ->assertOk()->assertJsonPath('data.store_name', "Fixed $status name")->assertJsonPath('data.tin_number', '0099');
        }
    }

    public function test_admin_changes_the_store_identity_and_it_is_logged(): void
    {
        $slug = $this->seller->slug;
        $admin = $this->admin();

        // Stores and customers cannot use the admin route.
        $this->as($this->seller->user)->putJson("/api/admin/sellers/{$this->seller->id}", ['store_name' => 'Mine'])->assertForbidden();
        $this->as($this->buyer)->putJson("/api/admin/sellers/{$this->seller->id}", ['store_name' => 'Mine'])->assertForbidden();

        $this->as($admin)->putJson("/api/admin/sellers/{$this->seller->id}", ['store_name' => ' ', 'business_type' => 'castle'])
            ->assertUnprocessable()->assertJsonValidationErrors(['store_name', 'business_type']);

        $this->putJson("/api/admin/sellers/{$this->seller->id}", ['store_name' => '  Merkato Beds  ', 'tin_number' => '0055', 'business_type' => 'manufacturer'])
            ->assertOk()
            ->assertJsonPath('data.store_name', 'Merkato Beds')
            ->assertJsonPath('data.tin_number', '0055')
            ->assertJsonPath('data.business_type', 'manufacturer')
            ->assertJsonPath('data.slug', $slug);

        $log = AuditLog::where('action', 'seller.details_changed')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Merkato Beds', $log->meta['after']['store_name']);
        $this->assertSame('manufacturer', $log->meta['after']['business_type']);

        // Saving the same values changes nothing and logs nothing. An empty TIN is stored as none.
        $this->putJson("/api/admin/sellers/{$this->seller->id}", ['store_name' => 'Merkato Beds'])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'seller.details_changed')->count());
        $this->putJson("/api/admin/sellers/{$this->seller->id}", ['tin_number' => ''])->assertOk()->assertJsonPath('data.tin_number', null);

        // The store page shows the new name at the same address.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/sellers/$slug")->assertOk()->assertJsonPath('data.store_name', 'Merkato Beds');
    }

    public function test_a_store_that_is_not_active_cannot_reply_to_reviews(): void
    {
        config(['marketplace.distance.stores_set_fee' => true]);
        $order = $this->deliver($this->placeOrder(1));
        $this->as($this->buyer)->postJson("/api/orders/{$order->id}/items/{$order->items->first()->id}/review", ['rating' => 2, 'body' => 'Wobbly legs on arrival.'])->assertCreated();
        $id = Review::sole()->id;

        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->as($this->seller->user)->postJson("/api/seller/reviews/$id/reply", ['reply' => 'Liar!'])
            ->assertUnprocessable()->assertJsonValidationErrors('reply');
        $this->assertNull(Review::sole()->seller_reply);

        $this->seller->forceFill(['status' => 'approved'])->save();
        $this->postJson("/api/seller/reviews/$id/reply", ['reply' => 'Sorry, we will fix them.'])->assertOk();
    }

    public function test_stores_cannot_charge_their_own_delivery_fee_unless_admins_allow_it(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->as($this->seller->user);

        $this->postJson('/api/seller/products', $this->productPayload(['delivery_fee' => '450']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_fee' => 'The marketplace sets delivery prices. Leave this empty, or offer free delivery.']);
        // Free delivery, or leaving it to the marketplace, is always fine.
        $free = $this->postJson('/api/seller/products', $this->productPayload(['delivery_fee' => '0']))->assertCreated()->json('data.id');
        $this->postJson('/api/seller/products', $this->productPayload(['delivery_fee' => '']))->assertCreated()->assertJsonPath('data.delivery.fee_santim', null);
        $this->putJson("/api/seller/products/$free", ['delivery_fee' => '0.00'])->assertOk()->assertJsonPath('data.delivery.fee_santim', 0);
        $this->putJson("/api/seller/products/$free", ['delivery_fee' => '10'])->assertUnprocessable()->assertJsonValidationErrors('delivery_fee');

        // Allowed by admins: the same fee is accepted.
        Setting::create(['key' => 'stores_set_delivery_fee', 'value' => '1']);
        $this->putJson("/api/seller/products/$free", ['delivery_fee' => '450'])->assertOk()->assertJsonPath('data.delivery.fee_santim', 45_000);
    }

    public function test_a_fee_set_earlier_is_ignored_while_switched_off_and_free_delivery_still_counts(): void
    {
        // The sofa has a 300 birr listing fee from before; Bole's zone charges 600.
        $zone = DeliveryZone::create(['name' => 'Inner', 'fee_santim' => 60_000, 'min_days' => 1, 'max_days' => 2, 'is_active' => true]);
        $this->area('bole')->update(['delivery_zone_id' => $zone->id]);
        $cart = fn () => $this->as($this->buyer)->getJson('/api/cart?address_id='.$this->address->id)->json('data.groups.0.delivery_fee_santim');
        $estimate = fn () => $this->getJson("/api/products/{$this->sofa->slug}/delivery-estimate?area_id=".$this->area('bole')->id)->json('data');
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 1])->assertCreated();

        $this->assertSame(60_000, $cart(), 'zone fee, not the old listing fee');
        $this->assertSame(['zone', 60_000], [$estimate()['fee_source'], $estimate()['fee_santim']]);
        $this->getJson("/api/products/{$this->sofa->slug}")->assertJsonPath('data.delivery.fee_santim', null);
        // The store still sees what it entered, marked as not in use.
        $this->as($this->seller->user)->getJson("/api/seller/products/{$this->sofa->id}")
            ->assertJsonPath('data.delivery.fee_santim', 30_000)->assertJsonPath('data.delivery.fee_ignored', true);

        // Switched on: the listing fee is back everywhere.
        Setting::create(['key' => 'stores_set_delivery_fee', 'value' => '1']);
        $this->assertSame(30_000, $cart());
        $this->assertSame(['listing', 30_000], [$estimate()['fee_source'], $estimate()['fee_santim']]);
        $this->as($this->seller->user)->getJson("/api/seller/products/{$this->sofa->id}")->assertJsonPath('data.delivery.fee_ignored', false);

        // Free delivery counts either way.
        Setting::where('key', 'stores_set_delivery_fee')->update(['value' => '0']);
        $this->sofa->forceFill(['delivery_fee_santim' => 0])->save();
        $this->assertSame(0, $cart());
        $this->assertSame(['listing', 0], [$estimate()['fee_source'], $estimate()['fee_santim']]);
    }

    public function test_admin_switches_it_in_settings_and_the_shop_is_told(): void
    {
        $this->getJson('/api/config')->assertJsonPath('delivery.stores_set_fee', false);
        $this->as($this->admin())->getJson('/api/admin/settings')
            ->assertJsonPath('data.stores_set_delivery_fee', false)
            ->assertJsonPath('meta.listings_with_own_fee', 1);
        $this->putJson('/api/admin/settings', ['stores_set_delivery_fee' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('stores_set_delivery_fee');
        $this->putJson('/api/admin/settings', ['stores_set_delivery_fee' => true])->assertOk()->assertJsonPath('data.stores_set_delivery_fee', true);
        $this->assertTrue(AuditLog::where('action', 'settings.updated')->exists());
        $this->getJson('/api/config')->assertJsonPath('delivery.stores_set_fee', true);
        $this->as($this->seller->user)->putJson('/api/admin/settings', ['stores_set_delivery_fee' => false])->assertForbidden();
    }

    public function test_listing_counts_ignore_free_and_unset_fees(): void
    {
        Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'delivery_fee_santim' => 0]);
        Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'delivery_fee_santim' => null]);
        $this->as($this->admin())->getJson('/api/admin/settings')->assertJsonPath('meta.listings_with_own_fee', 1);
    }
}
