<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Address $address;

    private SellerProfile $sellerA;

    private SellerProfile $sellerB;

    private Product $sofa;

    private Product $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->sellerA = $this->approvedSeller();
        $this->sellerB = $this->approvedSeller();
        // Sofa: in stock, listing fee 500. Table: other seller, no listing fee (zone decides).
        $this->sofa = $this->product($this->sellerA, ['price_santim' => 3_850_000, 'stock_quantity' => 3, 'delivery_fee_santim' => 50_000, 'pickup_available' => true]);
        $this->table = $this->product($this->sellerB, ['price_santim' => 1_450_050, 'stock_quantity' => 5, 'delivery_fee_santim' => null]);
        $this->buyer = $this->customer();
        $this->address = $this->buyer->addresses()->forceCreate([
            'user_id' => $this->buyer->id, 'recipient_name' => 'Abebe', 'phone' => '+251911000111',
            'area_id' => $this->area('bole')->id, 'address_line' => 'House 12', 'is_default' => true,
        ]);
        Sanctum::actingAs($this->buyer);
    }

    private function product(SellerProfile $seller, array $attrs = []): Product
    {
        return Product::factory()->published()->create(array_merge([
            'seller_profile_id' => $seller->id, 'category_id' => $this->category()->id,
        ], $attrs));
    }

    private function zone(int $feeSantim = 60_000): DeliveryZone
    {
        $zone = DeliveryZone::create(['name' => 'Inner', 'fee_santim' => $feeSantim, 'min_days' => 1, 'max_days' => 3, 'is_active' => true]);
        $this->area('bole')->update(['delivery_zone_id' => $zone->id]);

        return $zone;
    }

    private function add(Product $product, int $qty = 1): TestResponse
    {
        return $this->postJson('/api/cart/items', ['product_slug' => $product->slug, 'quantity' => $qty]);
    }

    private function preview(array $fulfillment = []): array
    {
        return $this->getJson('/api/cart?'.http_build_query(['address_id' => $this->address->id, 'fulfillment' => $fulfillment]))->assertOk()->json('data');
    }

    private function checkout(array $overrides = [], ?string $key = null): TestResponse
    {
        $fulfillment = $overrides['fulfillment'] ?? [];
        $expected = $overrides['expected_total_santim'] ?? $this->preview($fulfillment)['grand_total_santim'];

        return $this->postJson('/api/checkout', array_merge([
            'address_id' => $this->address->id,
            'payment_method' => 'cod',
            'expected_total_santim' => $expected,
        ], $overrides), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_cart_add_merges_lines_and_caps_at_stock(): void
    {
        $this->add($this->sofa)->assertCreated()->assertJsonPath('data.line_count', 1);
        $this->add($this->sofa, 2)->assertCreated()->assertJsonPath('data.groups.0.lines.0.quantity', 3);
        $this->add($this->sofa)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(1, CartItem::count());

        $id = CartItem::first()->id;
        $this->putJson("/api/cart/items/$id", ['quantity' => 4])->assertUnprocessable();
        $this->putJson("/api/cart/items/$id", ['quantity' => 0])->assertUnprocessable();
        $this->putJson("/api/cart/items/$id", ['quantity' => 2])->assertOk()->assertJsonPath('data.items_total_santim', 7_700_000);
        $this->getJson('/api/cart/count')->assertJsonPath('data.count', 1);
        $this->deleteJson("/api/cart/items/$id")->assertOk()->assertJsonPath('data.line_count', 0);
    }

    public function test_cannot_add_hidden_or_out_of_stock_items(): void
    {
        $draft = Product::factory()->create(['seller_profile_id' => $this->sellerA->id, 'category_id' => $this->category()->id]);
        $this->add($draft)->assertUnprocessable()->assertJsonValidationErrors('product_slug');

        $this->sofa->update(['stock_quantity' => 0]);
        $this->add($this->sofa)->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $mto = $this->product($this->sellerA, ['stock_type' => 'made_to_order', 'stock_quantity' => 0, 'production_days' => 14]);
        $this->add($mto, 5)->assertCreated();
        $this->add($mto, 99)->assertUnprocessable();
    }

    public function test_cart_line_limit(): void
    {
        config(['marketplace.cart.max_lines' => 2]);
        $this->add($this->sofa)->assertCreated();
        $this->add($this->table)->assertCreated();
        $this->add($this->product($this->sellerA))->assertUnprocessable()->assertJsonValidationErrors('product_slug');
    }

    public function test_cart_prices_delivery_per_seller(): void
    {
        $this->add($this->sofa);
        $this->add($this->table);

        // No zone for Bole: the table's seller must confirm the fee.
        $p = $this->preview();
        $this->assertSame(50_000, $p['groups'][0]['delivery_fee_santim']);
        $this->assertNull($p['groups'][1]['delivery_fee_santim']);
        $this->assertTrue($p['delivery_to_confirm']);
        $this->assertSame(3_850_000 + 1_450_050 + 50_000, $p['grand_total_santim']);

        $this->zone(60_000);
        $p = $this->preview();
        $this->assertSame(60_000, $p['groups'][1]['delivery_fee_santim']);
        $this->assertFalse($p['delivery_to_confirm']);

        // Pickup removes the fee; pickup is not offered for the table.
        $p = $this->preview([$this->sellerA->id => 'pickup']);
        $this->assertNull($p['groups'][0]['delivery_fee_santim']);
        $p = $this->preview([$this->sellerB->id => 'pickup']);
        $this->assertNotNull($p['groups'][1]['problem']);
        $this->assertTrue($p['has_problems']);
    }

    public function test_one_seller_delivery_is_one_trip_at_the_highest_fee(): void
    {
        $chair = $this->product($this->sellerA, ['price_santim' => 400_000, 'delivery_fee_santim' => 20_000]);
        $this->add($this->sofa);
        $this->add($chair);
        $this->assertSame(50_000, $this->preview()['groups'][0]['delivery_fee_santim']);
    }

    public function test_checkout_creates_one_order_per_seller_and_reserves_stock(): void
    {
        Setting::updateOrCreate(['key' => 'commission_rate_percent'], ['value' => '7.5']);
        $this->zone(60_000);
        $this->add($this->sofa, 2);
        $this->add($this->table);

        $res = $this->checkout(['note' => 'Call before coming'])->assertCreated()
            ->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.grand_total_santim', 7_700_000 + 50_000 + 1_450_050 + 60_000);

        $orders = Order::orderBy('id')->get();
        $this->assertSame(['pending_confirmation', 'pending_confirmation'], $orders->pluck('status')->map->value->all());
        $this->assertSame('ORD-'.str_pad((string) $orders[0]->id, 6, '0', STR_PAD_LEFT), $orders[0]->number);
        $this->assertSame(7_750_000, $orders[0]->total_santim);
        $this->assertSame(750, $orders[0]->commission_rate_bp);
        // 7.5% of 77,000.00 birr = 5,775.00; commission is on items only.
        $this->assertSame(577_500, $orders[0]->commission_santim);
        // 7.5% of 14,500.50 = 1,087.5375 -> 1,087.54 (half up).
        $this->assertSame(108_754, $orders[1]->commission_santim);
        $this->assertSame('Call before coming', $orders[0]->customer_note);
        $this->assertSame(1, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(4, $this->table->fresh()->stock_quantity);
        $this->assertSame(2, $orders[0]->items()->first()->reserved_quantity);
        $this->assertSame(0, CartItem::count());
        $this->assertSame(2, OrderStatusHistory::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkout.placed']);

        $checkout = Checkout::first();
        $this->assertSame('Bole', $checkout->area_name);
        $this->assertSame('House 12', $checkout->address_line);
        $res->assertJsonMissingPath('data.orders.0.commission_santim');
    }

    public function test_retry_with_same_key_returns_the_same_checkout(): void
    {
        $this->add($this->sofa);
        $expected = $this->preview()['grand_total_santim'];
        $key = (string) Str::uuid();

        $first = $this->checkout(['expected_total_santim' => $expected], $key)->assertCreated()->json('data.id');
        $second = $this->checkout(['expected_total_santim' => $expected], $key)->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::count());
        $this->assertSame(2, $this->sofa->fresh()->stock_quantity);

        // A new key with an empty cart is rejected.
        $this->checkout(['expected_total_santim' => $expected])->assertUnprocessable()->assertJsonValidationErrors('cart');
    }

    public function test_changed_total_is_refused_and_nothing_is_created(): void
    {
        $this->add($this->sofa);
        $expected = $this->preview()['grand_total_santim'];

        $this->sofa->update(['price_santim' => 3_900_000]);
        $this->checkout(['expected_total_santim' => $expected])->assertStatus(409)
            ->assertJsonPath('data.items_total_santim', 3_900_000);

        $this->checkout(['expected_total_santim' => 1])->assertStatus(409);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Checkout::count());
        $this->assertSame(3, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(1, CartItem::count());
    }

    public function test_stock_taken_by_someone_else_blocks_checkout(): void
    {
        $this->add($this->sofa, 3);
        $expected = $this->preview()['grand_total_santim'];
        $this->sofa->update(['stock_quantity' => 2]);

        $this->checkout(['expected_total_santim' => $expected])->assertStatus(409)
            ->assertJsonPath('data.has_problems', true)
            ->assertJsonPath('data.groups.0.lines.0.problem', 'Only 2 left. Lower the quantity.');
        $this->assertSame(2, $this->sofa->fresh()->stock_quantity);
    }

    public function test_hidden_listing_or_suspended_store_blocks_checkout(): void
    {
        $this->add($this->sofa);
        $this->sellerA->forceFill(['status' => 'suspended'])->save();
        $this->checkout(['expected_total_santim' => 3_900_000])->assertStatus(409)->assertJsonPath('data.has_problems', true);

        $this->sellerA->forceFill(['status' => 'approved'])->save();
        $this->sofa->forceFill(['status' => 'archived'])->save();
        $this->checkout(['expected_total_santim' => 3_900_000])->assertStatus(409)
            ->assertJsonPath('data.groups.0.lines.0.problem', 'This listing is no longer available.');
    }

    public function test_pickup_and_unconfirmed_delivery(): void
    {
        $this->add($this->sofa);
        $this->add($this->table);

        $this->checkout(['fulfillment' => [$this->sellerA->id => 'pickup']])->assertCreated();
        [$pickup, $toConfirm] = Order::orderBy('id')->get();
        $this->assertSame('pickup', $pickup->fulfillment);
        $this->assertNull($pickup->delivery_fee_santim);
        $this->assertSame(3_850_000, $pickup->total_santim);
        $this->assertNull($toConfirm->delivery_fee_santim);
        $this->assertFalse($toConfirm->deliveryFeeConfirmed());

        $this->add($this->table);
        $this->checkout(['fulfillment' => [$this->sellerB->id => 'pickup']])->assertStatus(409);
        $this->checkout(['fulfillment' => [$this->sellerB->id => 'teleport'], 'expected_total_santim' => 1])->assertUnprocessable()->assertJsonValidationErrors('fulfillment.'.$this->sellerB->id);
    }

    public function test_checkout_validation(): void
    {
        $this->add($this->sofa);
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->checkout(['payment_method' => 'card'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $other = $this->customer()->addresses()->forceCreate([
            'user_id' => User::latest('id')->first()->id, 'recipient_name' => 'X', 'phone' => '+251911000222',
            'area_id' => $this->area('bole')->id, 'address_line' => 'Elsewhere', 'is_default' => true,
        ]);
        $this->checkout(['address_id' => $other->id])->assertNotFound();

        Setting::updateOrCreate(['key' => 'cod_enabled'], ['value' => '0']);
        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, Order::count());
    }

    public function test_only_customers_use_cart_and_checkout(): void
    {
        foreach ([$this->sellerA->user, $this->admin()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/cart')->assertForbidden();
            $this->postJson('/api/checkout', [])->assertForbidden();
            $this->getJson('/api/orders')->assertForbidden();
        }
    }

    public function test_checkout_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/checkout', [], ['Idempotency-Key' => 'k-'.Str::random(10)])->assertUnprocessable();
        }
        $this->postJson('/api/checkout', [], ['Idempotency-Key' => 'k-'.Str::random(10)])->assertTooManyRequests();
    }
}
