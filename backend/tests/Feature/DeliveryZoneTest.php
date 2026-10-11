<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DeliveryZone;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function zonePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Central',
            'fee' => '450.50',
            'min_days' => 1,
            'max_days' => 3,
            'area_ids' => [$this->area('bole')->id, $this->area('kirkos')->id],
        ], $overrides);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->published()->create(array_merge([
            'seller_profile_id' => $this->approvedSeller()->id,
            'category_id' => $this->category()->id,
            'delivery_fee_santim' => null,
        ], $attributes));
    }

    public function test_admin_creates_updates_and_deletes_zone(): void
    {
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/admin/delivery-zones', $this->zonePayload())->assertCreated()
            ->assertJsonPath('data.fee_santim', 45050)
            ->assertJsonCount(2, 'data.areas')
            ->json('data.id');
        $this->assertSame($id, $this->area('bole')->delivery_zone_id);

        // Replacing the area list releases areas that are no longer listed.
        $this->putJson("/api/admin/delivery-zones/$id", ['area_ids' => [$this->area('yeka')->id]])->assertOk()
            ->assertJsonCount(1, 'data.areas');
        $this->assertNull($this->area('bole')->delivery_zone_id);
        $this->assertSame($id, $this->area('yeka')->delivery_zone_id);

        // Updating other fields without area_ids leaves areas alone.
        $this->putJson("/api/admin/delivery-zones/$id", ['fee' => '500'])->assertOk()->assertJsonPath('data.fee_santim', 50000);
        $this->assertSame($id, $this->area('yeka')->delivery_zone_id);

        $this->deleteJson("/api/admin/delivery-zones/$id")->assertOk();
        $this->assertNull($this->area('yeka')->delivery_zone_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delivery_zone.deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delivery_zone.created']);
    }

    public function test_moving_an_area_between_zones(): void
    {
        Sanctum::actingAs($this->admin());
        $a = $this->postJson('/api/admin/delivery-zones', $this->zonePayload())->json('data.id');
        $b = $this->postJson('/api/admin/delivery-zones', $this->zonePayload(['name' => 'Outer', 'area_ids' => [$this->area('bole')->id]]))->json('data.id');

        $this->assertSame($b, $this->area('bole')->delivery_zone_id);
        $this->assertSame($a, $this->area('kirkos')->delivery_zone_id);
    }

    public function test_zone_validation(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/admin/delivery-zones', $this->zonePayload(['min_days' => 5, 'max_days' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('max_days');
        $this->postJson('/api/admin/delivery-zones', $this->zonePayload(['fee' => '-1']))->assertUnprocessable()->assertJsonValidationErrors('fee');
        $this->postJson('/api/admin/delivery-zones', $this->zonePayload(['area_ids' => [999999]]))->assertUnprocessable()->assertJsonValidationErrors('area_ids.0');
        $id = $this->postJson('/api/admin/delivery-zones', $this->zonePayload())->assertCreated()->json('data.id');
        $this->postJson('/api/admin/delivery-zones', $this->zonePayload())->assertUnprocessable()->assertJsonValidationErrors('name');

        // Partial update is checked against stored values.
        $this->putJson("/api/admin/delivery-zones/$id", ['min_days' => 9])->assertUnprocessable()->assertJsonValidationErrors('max_days');
        $this->putJson("/api/admin/delivery-zones/$id", ['name' => 'Central'])->assertOk();
        $this->assertSame(1, DeliveryZone::count());
    }

    public function test_only_admins_manage_zones(): void
    {
        foreach ([$this->customer(), $this->approvedSeller()->user] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/admin/delivery-zones')->assertForbidden();
            $this->postJson('/api/admin/delivery-zones', $this->zonePayload())->assertForbidden();
        }
        $this->assertSame(0, DeliveryZone::count());
    }

    public function test_public_zone_list_hides_inactive_zones(): void
    {
        $active = DeliveryZone::create(['name' => 'Central', 'fee_santim' => 40000, 'min_days' => 1, 'max_days' => 2, 'is_active' => true]);
        DeliveryZone::create(['name' => 'Paused', 'fee_santim' => 90000, 'min_days' => 1, 'max_days' => 2, 'is_active' => false]);
        $this->area('bole')->update(['delivery_zone_id' => $active->id]);

        $this->getJson('/api/delivery-zones')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Central')
            ->assertJsonPath('data.0.areas', ['Bole']);
    }

    public function test_estimate_uses_listing_fee_then_zone_then_seller_quote(): void
    {
        $zone = DeliveryZone::create(['name' => 'Central', 'fee_santim' => 40000, 'min_days' => 1, 'max_days' => 2, 'is_active' => true]);
        $bole = $this->area('bole');
        $bole->update(['delivery_zone_id' => $zone->id]);
        $yeka = $this->area('yeka');

        $zoneFee = $this->product();
        $this->getJson("/api/products/{$zoneFee->slug}/delivery-estimate?area_id={$bole->id}")->assertOk()
            ->assertJsonPath('data.fee_santim', 40000)->assertJsonPath('data.fee_source', 'zone')
            ->assertJsonPath('data.min_days', 1)->assertJsonPath('data.max_days', 2)
            ->assertJsonPath('data.zone', 'Central');

        // Area outside any zone: seller quotes the fee.
        $this->getJson("/api/products/{$zoneFee->slug}/delivery-estimate?area_id={$yeka->id}")
            ->assertJsonPath('data.fee_santim', null)->assertJsonPath('data.fee_source', 'seller_quote');

        // Listing's own fee wins over the zone; a free delivery (0) is still a listing fee.
        $free = $this->product(['delivery_fee_santim' => 0]);
        $this->getJson("/api/products/{$free->slug}/delivery-estimate?area_id={$bole->id}")
            ->assertJsonPath('data.fee_santim', 0)->assertJsonPath('data.fee_source', 'listing');

        // Made-to-order adds production time.
        $mto = $this->product(['stock_type' => 'made_to_order', 'stock_quantity' => 0, 'production_days' => 14]);
        $this->getJson("/api/products/{$mto->slug}/delivery-estimate?area_id={$bole->id}")
            ->assertJsonPath('data.min_days', 15)->assertJsonPath('data.max_days', 16);
        $this->getJson("/api/products/{$mto->slug}/delivery-estimate?area_id={$yeka->id}")
            ->assertJsonPath('data.min_days', 14)->assertJsonPath('data.max_days', null);

        // Inactive zone is ignored.
        $zone->update(['is_active' => false]);
        $this->getJson("/api/products/{$zoneFee->slug}/delivery-estimate?area_id={$bole->id}")
            ->assertJsonPath('data.fee_source', 'seller_quote')->assertJsonPath('data.zone', null);

        // Pickup only.
        $pickup = $this->product(['offers_delivery' => false, 'pickup_available' => true]);
        $this->getJson("/api/products/{$pickup->slug}/delivery-estimate?area_id={$bole->id}")
            ->assertJsonPath('data.delivery_available', false)->assertJsonPath('data.pickup_available', true)
            ->assertJsonPath('data.fee_source', null);
    }

    public function test_estimate_rejects_hidden_listings_and_bad_areas(): void
    {
        $product = $this->product();
        $this->getJson("/api/products/{$product->slug}/delivery-estimate")->assertUnprocessable()->assertJsonValidationErrors('area_id');
        $this->getJson("/api/products/{$product->slug}/delivery-estimate?area_id=abc")->assertUnprocessable();
        Area::whereKey($this->area('bole')->id)->update(['is_active' => false]);
        $this->getJson("/api/products/{$product->slug}/delivery-estimate?area_id={$this->area('bole')->id}")->assertUnprocessable();

        $draft = Product::factory()->create(['seller_profile_id' => $product->seller_profile_id, 'category_id' => $this->category()->id]);
        $this->getJson("/api/products/{$draft->slug}/delivery-estimate?area_id={$this->area('yeka')->id}")->assertNotFound();
    }
}
