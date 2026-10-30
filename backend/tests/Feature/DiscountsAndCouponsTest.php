<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerLedgerEntry;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\CouponService;
use App\Services\LedgerService;
use App\Services\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Prices are fixed; customers pay less only through a store's sale (the store pays) or a Betoch coupon
 * (Betoch pays the store the difference on delivery).
 */
class DiscountsAndCouponsTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // VAT on, to check it stays on the full price.
        config(['marketplace.tax.vat_rate_percent' => 15]);
        $this->setUpOrders();
    }

    private function putOnSale(Product $product, array $sale): TestResponse
    {
        return $this->as($product->seller->user)->putJson("/api/seller/products/{$product->id}", $sale);
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + ['code' => 'SAVE15', 'percent_off' => 15]);
    }

    private function preview(?string $code = null, array $query = [], ?User $buyer = null): TestResponse
    {
        $buyer ??= $this->buyer;
        $address = $buyer->addresses()->first();

        return $this->as($buyer)->getJson('/api/cart?'.http_build_query(['address_id' => $address->id, 'coupon_code' => $code] + $query));
    }

    private function checkout(?string $code, ?int $expected = null, string $method = 'cod', ?User $buyer = null): TestResponse
    {
        $buyer ??= $this->buyer;
        $expected ??= $this->preview($code, [], $buyer)->json('data.grand_total_santim');

        return $this->as($buyer)->postJson('/api/checkout', [
            'address_id' => $buyer->addresses()->first()->id, 'payment_method' => $method, 'expected_total_santim' => $expected, 'coupon_code' => $code,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function addToCart(Product $product, int $qty = 1, ?User $buyer = null): void
    {
        $this->as($buyer ?? $this->buyer)->postJson('/api/cart/items', ['product_slug' => $product->slug, 'quantity' => $qty])->assertCreated();
    }

    private function store(int $priceSantim, int $feeSantim = 20_000): Product
    {
        $seller = SellerProfile::factory()->approved()->create(['area_id' => $this->area()->id]);

        return Product::factory()->published()->create([
            'seller_profile_id' => $seller->id, 'category_id' => $this->category()->id,
            'price_santim' => $priceSantim, 'stock_quantity' => 10, 'delivery_fee_santim' => $feeSantim,
        ]);
    }

    public function test_a_sale_lowers_the_price_everywhere_until_its_last_day(): void
    {
        $this->travelTo(now('Africa/Addis_Ababa')->setTime(10, 0)->utc());
        $plain = $this->store(900_000);
        $endsOn = now('Africa/Addis_Ababa')->addDays(3)->toDateString();
        $this->putOnSale($this->sofa, ['discount_percent' => 15, 'discount_ends_on' => $endsOn])->assertOk()
            ->assertJsonPath('data.discount_percent', 15)->assertJsonPath('data.discount_ends_on', $endsOn)
            ->assertJsonPath('data.price_now_santim', 850_000)->assertJsonPath('data.status', 'published');
        $option = ProductVariant::forceCreate(['product_id' => $this->sofa->id, 'seller_profile_id' => $this->seller->id, 'color' => 'Grey', 'price_santim' => 1_200_000, 'stock_quantity' => 3]);

        $this->getJson("/api/products/{$this->sofa->slug}")->assertOk()
            ->assertJsonPath('data.price_santim', 1_000_000)
            ->assertJsonPath('data.price_now_santim', 850_000)
            ->assertJsonPath('data.discount.percent', 15)
            ->assertJsonPath('data.discount.ends_at', now('Africa/Addis_Ababa')->addDays(3)->endOfDay()->utc()->toIso8601String())
            ->assertJsonPath('data.variants.0.price_santim', 1_200_000)
            ->assertJsonPath('data.variants.0.price_now_santim', 1_020_000)
            ->assertJsonMissingPath('data.discount_percent');
        $this->getJson("/api/products/{$plain->slug}")->assertJsonPath('data.discount', null)->assertJsonPath('data.price_now_santim', 900_000);

        // Search, filters and sorting go by the price customers pay now.
        $slugs = fn (string $query) => collect($this->getJson('/api/products?'.$query)->assertOk()->json('data'))->pluck('slug')->all();
        $this->assertSame([$this->sofa->slug], $slugs('on_sale=1'));
        $this->assertSame([$this->sofa->slug, $plain->slug], $slugs('sort=price_asc'));
        $this->assertSame([$plain->slug, $this->sofa->slug], $slugs('sort=price_desc'));
        $this->assertSame([$plain->slug], $slugs('min_price=8600'));
        $this->assertSame([$this->sofa->slug], $slugs('max_price=8500'));
        $this->getJson('/api/search/suggest?q='.urlencode(substr($this->sofa->name, 0, 5)))->assertOk()
            ->assertJsonPath('data.0.price_now_santim', 850_000);

        // The cart and the order charge the sale price and keep the price before it; commission is on the sale price.
        $this->sofa->variants()->delete();
        $this->addToCart($this->sofa, 2);
        $this->preview()->assertJsonPath('data.groups.0.lines.0.unit_price_santim', 850_000)
            ->assertJsonPath('data.groups.0.lines.0.list_unit_price_santim', 1_000_000)
            ->assertJsonPath('data.groups.0.lines.0.discount_percent', 15)
            ->assertJsonPath('data.items_total_santim', 1_700_000);
        $this->checkout(null)->assertCreated();
        $order = Order::sole();
        $this->assertSame(1_700_000, $order->items_total_santim);
        $this->assertSame(170_000, $order->commission_santim);
        $this->assertSame([850_000, 1_000_000], [$order->items()->sole()->unit_price_santim, $order->items()->sole()->list_unit_price_santim]);
        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.items.0.list_unit_price_santim', 1_000_000)->assertJsonPath('data.coupon', null);

        // The sale runs to the end of its last day in Addis Ababa, then the listing price is back.
        $this->travelTo(now('Africa/Addis_Ababa')->addDays(3)->setTime(23, 59, 0)->utc());
        $this->getJson("/api/products/{$this->sofa->slug}")->assertJsonPath('data.price_now_santim', 850_000);
        $this->travelTo(now('Africa/Addis_Ababa')->addDay()->setTime(0, 0, 30)->utc());
        $this->getJson("/api/products/{$this->sofa->slug}")->assertJsonPath('data.price_now_santim', 1_000_000)->assertJsonPath('data.discount', null);
        $this->assertSame([], $slugs('on_sale=1'));
        $this->assertSame([$plain->slug, $this->sofa->slug], $slugs('sort=price_asc'));
        $this->addToCart($this->sofa, 1);
        $this->preview()->assertJsonPath('data.groups.0.lines.0.unit_price_santim', 1_000_000)->assertJsonPath('data.groups.0.lines.0.list_unit_price_santim', null);
        // The store still sees the sale it set, to put it on again or clear it.
        $this->as($this->seller->user)->getJson("/api/seller/products/{$this->sofa->id}")
            ->assertJsonPath('data.discount_percent', 15)->assertJsonPath('data.discount_ends_on', $endsOn);
    }

    public function test_a_sale_is_checked_and_can_be_changed_or_taken_off(): void
    {
        $this->travelTo(now('Africa/Addis_Ababa')->setTime(10, 0)->utc());
        $today = now('Africa/Addis_Ababa')->toDateString();
        $yesterday = now('Africa/Addis_Ababa')->subDay()->toDateString();
        foreach ([
            [['discount_percent' => 0], 'discount_percent', 'at least 1%'],
            [['discount_percent' => 91], 'discount_percent', 'up to 90%'],
            [['discount_percent' => '12.5'], 'discount_percent', 'whole percent'],
            [['discount_percent' => 10, 'discount_ends_on' => $yesterday], 'discount_ends_on', 'cannot be in the past'],
            [['discount_percent' => 10, 'discount_ends_on' => '5/11/2026'], 'discount_ends_on', 'last day of the sale'],
            [['discount_percent' => '', 'discount_ends_on' => $today], 'discount_percent', 'Enter the percent off'],
        ] as [$sale, $field, $message]) {
            $this->putOnSale($this->sofa, $sale)->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
        }
        $this->assertNull($this->sofa->fresh()->discount_percent);

        // A sale ending today runs to the end of today.
        $this->putOnSale($this->sofa, ['discount_percent' => 90, 'discount_ends_on' => $today])->assertOk()->assertJsonPath('data.price_now_santim', 100_000);
        // Only the end date: the percent stays.
        $this->putOnSale($this->sofa, ['discount_ends_on' => now('Africa/Addis_Ababa')->addWeek()->toDateString()])->assertOk()->assertJsonPath('data.discount_percent', 90);
        // No end date: until taken off.
        $this->putOnSale($this->sofa, ['discount_percent' => 30, 'discount_ends_on' => null])->assertOk()->assertJsonPath('data.discount_ends_on', null);
        $this->travel(400)->days();
        $this->getJson("/api/products/{$this->sofa->slug}")->assertJsonPath('data.price_now_santim', 700_000);
        // Taken off: the end date goes with it.
        $this->putOnSale($this->sofa, ['discount_percent' => 25, 'discount_ends_on' => now('Africa/Addis_Ababa')->toDateString()])->assertOk();
        $this->putOnSale($this->sofa, ['discount_percent' => null])->assertOk()->assertJsonPath('data.discount_percent', null)->assertJsonPath('data.discount_ends_on', null)
            ->assertJsonPath('data.price_now_santim', 1_000_000);

        // A sale that ended may be saved again as it was (the store edits something else); a new past date may not.
        $endsOn = now('Africa/Addis_Ababa')->toDateString();
        $this->putOnSale($this->sofa, ['discount_percent' => 20, 'discount_ends_on' => $endsOn])->assertOk();
        $this->travel(2)->days();
        $this->putOnSale($this->sofa, ['discount_percent' => 20, 'discount_ends_on' => $endsOn, 'price' => '9000'])->assertOk()->assertJsonPath('data.price_now_santim', 900_000);
        $this->putOnSale($this->sofa, ['discount_percent' => 20, 'discount_ends_on' => now('Africa/Addis_Ababa')->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_ends_on');

        // A new listing can start on sale.
        $created = $this->as($this->seller->user)->post('/api/seller/products', $this->productPayload(['discount_percent' => '10']), ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(10, Product::findOrFail($created->json('data.id'))->discount_percent);
        // Another store cannot put this listing on sale.
        $this->as(SellerProfile::factory()->approved()->create()->user)->putJson("/api/seller/products/{$this->sofa->id}", ['discount_percent' => 50])->assertNotFound();
    }

    public function test_the_sale_price_in_the_database_matches_php_to_the_santim(): void
    {
        mt_srand(7);
        $rows = [];
        foreach (range(1, 300) as $i) {
            $price = match ($i % 4) {
                0 => mt_rand(100, 999), 1 => mt_rand(1_000, 99_999) * 100 + mt_rand(0, 99), 2 => mt_rand(1, 999_999_999) * 100, default => 999_999_999_999
            };
            $rows[] = Product::factory()->published()->create([
                'seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'price_santim' => $price,
                'discount_percent' => $i % 7 === 0 ? null : mt_rand(1, 90), 'discount_ends_at' => $i % 5 === 0 ? now()->subMinute() : ($i % 3 ? null : now()->addDay()),
            ]);
        }
        [$sql, $bindings] = Product::priceNowSql();
        $fromDb = Product::whereKey(collect($rows)->pluck('id'))->selectRaw("id, {$sql} as now_santim", $bindings)->pluck('now_santim', 'id');
        foreach ($rows as $p) {
            $this->assertSame($p->priceNowSantim(), (int) $fromDb[$p->id], "price {$p->price_santim} at {$p->discount_percent}%");
        }
        $this->assertSame(1, Product::discounted(100, 99) === 1 ? 1 : 0, 'rounded half up');
        $this->assertSame(888_889, Product::discounted(987_655, 10), '98,765.5 off rounds to 98,766');
    }

    public function test_admins_make_check_and_remove_coupons(): void
    {
        $admin = $this->admin();
        $this->as($this->buyer)->getJson('/api/admin/coupons')->assertForbidden();
        $this->as($admin);
        $base = ['kind' => 'percent', 'percent_off' => 10];
        foreach ([
            [['code' => 'ab'], 'code', '3 to 32 letters'],
            [['code' => 'SAVE 10%'], 'code', '3 to 32 letters'],
            [['code' => 'SAVE10', 'percent_off' => 95], 'percent_off', 'up to 90%'],
            [['code' => 'SAVE10', 'percent_off' => null], 'percent_off', 'percent off'],
            [['code' => 'SAVE10', 'kind' => 'amount', 'amount_off' => '500'], 'min_items', 'minimum spend above the amount'],
            [['code' => 'SAVE10', 'kind' => 'amount', 'amount_off' => '500', 'min_items' => '500'], 'min_items', 'minimum spend above the amount'],
            [['code' => 'SAVE10', 'kind' => 'amount', 'amount_off' => '0.5', 'min_items' => '100'], 'amount_off', 'at least 1 birr'],
            [['code' => 'SAVE10', 'starts_on' => '2026-12-10', 'ends_on' => '2026-12-01'], 'ends_on', 'before the first day'],
            [['code' => 'SAVE10', 'ends_on' => now('Africa/Addis_Ababa')->subDay()->toDateString()], 'ends_on', 'in the past'],
            [['code' => 'SAVE10', 'max_discount' => '0.5'], 'max_discount', 'at least 1 birr'],
        ] as [$input, $field, $message]) {
            $this->postJson('/api/admin/coupons', $input + $base)->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
        }

        $id = $this->postJson('/api/admin/coupons', ['code' => ' welcome 10 ', 'percent_off' => 10, 'max_discount' => '2000', 'kind' => 'percent'] + $base)->assertCreated()
            ->assertJsonPath('data.code', 'WELCOME10')->assertJsonPath('data.state', 'active')->assertJsonPath('data.max_uses_per_customer', 1)
            ->assertJsonPath('data.label', '10% off your items, up to ETB 2,000 off')->json('data.id');
        $this->postJson('/api/admin/coupons', ['code' => 'Welcome10'] + $base)->assertUnprocessable()->assertJsonValidationErrors(['code' => 'already uses']);
        $this->postJson('/api/admin/coupons', ['code' => 'BIG-500', 'kind' => 'amount', 'amount_off' => '500', 'min_items' => '5000', 'percent_off' => 30,
            'starts_on' => now('Africa/Addis_Ababa')->addDay()->toDateString(), 'max_uses' => 100, 'max_uses_per_customer' => null])->assertCreated()
            ->assertJsonPath('data.percent_off', null)->assertJsonPath('data.state', 'scheduled')->assertJsonPath('data.max_uses_per_customer', null)
            ->assertJsonPath('data.label', 'ETB 500 off your items, on items of ETB 5,000 or more');

        $this->putJson("/api/admin/coupons/{$id}", ['code' => 'WELCOME10', 'kind' => 'percent', 'percent_off' => 12, 'is_active' => false])->assertOk()
            ->assertJsonPath('data.percent_off', 12)->assertJsonPath('data.max_discount', null)->assertJsonPath('data.state', 'off');
        $this->getJson('/api/admin/coupons')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.code', 'WELCOME10')->assertJsonPath('data.1.deletable', true);

        // A code used on an order (even one later cancelled) is only turned off, never deleted.
        $used = $this->coupon(['code' => 'USED']);
        $this->addToCart($this->sofa);
        $this->checkout('USED')->assertCreated();
        $this->as($this->buyer)->postJson('/api/orders/'.Order::sole()->id.'/cancel', ['reason' => 'Changed my mind'])->assertOk();
        $this->as($admin)->deleteJson("/api/admin/coupons/{$used->id}")->assertUnprocessable()->assertJsonValidationErrors(['coupon' => 'Turn it off instead']);
        $this->getJson('/api/admin/coupons')->assertJsonPath('data.0.code', 'USED')->assertJsonPath('data.0.uses', 0)->assertJsonPath('data.0.deletable', false);
        $this->deleteJson("/api/admin/coupons/{$id}")->assertOk();
        $this->assertNull(Coupon::find($id));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'coupon.created')->exists());
    }

    public function test_betoch_pays_the_coupon_and_each_store_is_credited_its_share_on_delivery(): void
    {
        $table = $this->store(500_000);
        $this->addToCart($this->sofa);
        $this->addToCart($table);
        // Items 15,000; delivery 300 + 200; VAT on items and delivery of each order: 1,545 + 780.
        $this->preview()->assertJsonPath('data.grand_total_santim', 1_782_500)->assertJsonPath('data.coupon', null);
        $this->coupon();

        $res = $this->preview(' save15 ')->assertOk()
            ->assertJsonPath('data.coupon.code', 'SAVE15')
            ->assertJsonPath('data.coupon.error', null)
            ->assertJsonPath('data.coupon.discount_santim', 225_000)
            ->assertJsonPath('data.coupon.label', '15% off your items')
            ->assertJsonPath('data.items_total_santim', 1_500_000)
            ->assertJsonPath('data.vat_total_santim', 232_500)
            ->assertJsonPath('data.grand_total_santim', 1_557_500);
        $groups = collect($res->json('data.groups'))->keyBy('seller.id');
        $this->assertSame([150_000, 75_000], [$groups[$this->seller->id]['coupon_discount_santim'], $groups[$table->seller_profile_id]['coupon_discount_santim']]);
        $this->assertSame(1_184_500 - 150_000, $groups[$this->seller->id]['total_santim']);

        // Without the code the expected total no longer matches; with it the order goes through.
        $this->checkout(null, 1_557_500)->assertStatus(409);
        $this->checkout('save15', 1_557_500)->assertCreated()->assertJsonPath('data.coupon.code', 'SAVE15')->assertJsonPath('data.grand_total_santim', 1_557_500);
        $checkout = Checkout::sole();
        $this->assertSame(['SAVE15', 225_000, 1_557_500], [$checkout->coupon_code, $checkout->coupon_discount_santim, $checkout->grand_total_santim]);
        $sofaOrder = Order::where('seller_profile_id', $this->seller->id)->sole();
        $tableOrder = Order::where('seller_profile_id', $table->seller_profile_id)->sole();
        // VAT and commission are on the full price: the store's sale does not change.
        $this->assertSame([1_000_000, 154_500, 150_000, 1_034_500, 100_000], [$sofaOrder->items_total_santim, $sofaOrder->vat_santim, $sofaOrder->coupon_discount_santim, $sofaOrder->total_santim, $sofaOrder->commission_santim]);
        $this->assertFalse($sofaOrder->vatInsidePrice());
        $this->assertSame(523_000, $tableOrder->total_santim);

        $this->getJson("/api/orders/{$sofaOrder->id}")->assertJsonPath('data.coupon.code', 'SAVE15')->assertJsonPath('data.coupon.discount_santim', 150_000)
            ->assertJsonMissingPath('data.commission_santim');
        $this->as($this->seller->user)->getJson("/api/seller/orders/{$sofaOrder->id}")->assertOk()->assertJsonPath('data.coupon.discount_santim', 150_000);

        // Delivered: commission charged, coupon credited. Here the coupon is more than the commission, so Betoch owes the store.
        $this->deliver($sofaOrder);
        $this->assertSame(['commission' => 100_000, 'coupon' => -150_000], SellerLedgerEntry::where('order_id', $sofaOrder->id)->orderBy('type')->pluck('amount_santim', 'type')->all());
        $this->assertSame(-50_000, LedgerService::balance($this->seller->id));
        $this->as($this->seller->user)->getJson('/api/seller/balance')->assertJsonPath('data.coupons_total_santim', 150_000)->assertJsonPath('data.owed_to_seller_santim', 50_000);
        $this->getJson('/api/seller/balance/entries')->assertJsonPath('data.0.type', 'coupon')->assertJsonPath('data.0.note', "Coupon SAVE15 on {$sofaOrder->number}, paid by Betoch");
        // The store's sales count what Betoch pays it.
        $this->getJson('/api/seller/orders/summary')->assertJsonPath('data.delivered_sales_santim', 1_184_500);
        $this->getJson("/api/seller/erp/sales/orders/{$sofaOrder->id}")->assertOk()->assertJsonPath('data.coupon_santim', 150_000)->assertJsonPath('data.total_santim', 1_034_500);
        // Running delivery twice cannot credit twice.
        app(LedgerService::class)->creditCoupon($sofaOrder->fresh());
        $this->assertSame(1, SellerLedgerEntry::where('order_id', $sofaOrder->id)->where('type', 'coupon')->count());
        // Betoch can pay the store what it is owed.
        $this->as($this->admin())->postJson("/api/admin/settlements/{$this->seller->id}/payouts", ['amount' => '500', 'method' => 'bank_transfer', 'reference' => 'TX-1'])->assertCreated();
        $this->assertSame(0, LedgerService::balance($this->seller->id));

        $this->getJson('/api/admin/reports/summary')->assertOk()->assertJsonPath('data.delivered.coupons_santim', 150_000)->assertJsonPath('data.delivered.gross_santim', 1_034_500);
        $this->getJson('/api/admin/coupons')->assertJsonPath('data.0.uses', 1)->assertJsonPath('data.0.given_santim', 225_000);
    }

    public function test_coupon_rules(): void
    {
        $this->addToCart($this->sofa, 2);
        $error = fn (?string $code, ?User $buyer = null) => $this->preview($code, [], $buyer)->assertOk()->json('data.coupon.error');
        $this->assertSame('This code is not valid. Check the spelling.', $error('NOPE'));
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $this->assertSame('This code is not valid. Check the spelling.', $error('OFF'));
        $this->coupon(['code' => 'LATER', 'starts_at' => now('Africa/Addis_Ababa')->addDays(2)->startOfDay()->utc()]);
        $this->assertStringStartsWith('This code can be used from ', $error('LATER'));
        $this->coupon(['code' => 'OVER', 'ends_at' => now()->subSecond()]);
        $this->assertStringStartsWith('This code ended on ', $error('OVER'));
        $this->coupon(['code' => 'BIG', 'min_items_santim' => 2_000_100]);
        $this->assertSame('This code is for items of ETB 20,001 or more. Your items come to ETB 20,000.', $error('BIG'));
        // A code with an error takes nothing off.
        $this->preview('BIG')->assertJsonPath('data.coupon.discount_santim', 0)->assertJsonPath('data.grand_total_santim', 2_334_500)
            ->assertJsonPath('data.groups.0.coupon_discount_santim', 0);
        $this->checkout('BIG', 2_334_500)->assertUnprocessable()->assertJsonValidationErrors(['coupon_code' => 'ETB 20,001']);
        $this->assertSame(0, Checkout::count());

        // A cap on a percent; an amount; the minimum met exactly.
        $this->coupon(['code' => 'HALF', 'percent_off' => 50, 'max_discount_santim' => 100_000]);
        $this->preview('half')->assertJsonPath('data.coupon.discount_santim', 100_000)->assertJsonPath('data.coupon.label', '50% off your items, up to ETB 1,000 off');
        $this->coupon(['code' => 'FLAT', 'percent_off' => null, 'amount_off_santim' => 50_000, 'min_items_santim' => 2_000_000]);
        $this->preview('FLAT')->assertJsonPath('data.coupon.discount_santim', 50_000)->assertJsonPath('data.grand_total_santim', 2_284_500);

        // Once per customer by default; a use is given back when the order is cancelled.
        $once = $this->coupon(['code' => 'ONCE', 'max_uses' => 1]);
        $this->checkout('ONCE')->assertCreated();
        $order = Order::sole();
        $this->addToCart($this->sofa, 1);
        $this->assertSame('You have already used this code.', $error('ONCE'));
        $other = $this->customer();
        $this->addressFor($other);
        $this->addToCart($this->sofa, 1, $other);
        $this->assertSame('This code has been used up.', $error('ONCE', $other));
        $this->as($this->buyer)->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->assertNull($error('ONCE', $other));
        $this->checkout('ONCE', null, 'cod', $other)->assertCreated();
        $this->assertSame(1, $once->uses()->count());

        // A per-customer limit above one, and none at all.
        $this->coupon(['code' => 'TWICE', 'max_uses_per_customer' => 2]);
        $this->checkout('TWICE')->assertCreated();
        $this->addToCart($this->sofa, 1);
        $this->checkout('TWICE')->assertCreated();
        $this->addToCart($this->sofa, 1);
        $this->assertSame('You have used this code as many times as it allows.', $error('TWICE'));
        $this->coupon(['code' => 'ANY', 'max_uses_per_customer' => null]);
        foreach (range(1, 3) as $n) {
            $this->checkout('ANY')->assertCreated();
            $this->addToCart($this->sofa, 1);
        }
        $this->assertNull($error('ANY'));
    }

    public function test_a_code_that_stops_working_before_the_order_is_placed_is_refused(): void
    {
        $coupon = $this->coupon();
        $this->addToCart($this->sofa, 2);
        $expected = $this->preview('SAVE15')->json('data.grand_total_santim');
        $coupon->update(['is_active' => false]);
        $this->checkout('SAVE15', $expected)->assertUnprocessable()->assertJsonValidationErrors(['coupon_code' => 'not valid']);
        $this->assertSame(0, Order::count());
        $this->assertSame(1, $this->as($this->buyer)->getJson('/api/cart/count')->json('data.count'), 'the cart is kept');

        // A problem in the cart is reported before the code, with the totals as they stand.
        $coupon->update(['is_active' => true]);
        $this->sofa->forceFill(['stock_quantity' => 1])->save();
        $this->checkout('SAVE15', $expected)->assertStatus(409)->assertJsonPath('data.coupon.code', 'SAVE15');
    }

    public function test_online_payment_charges_the_total_after_the_coupon(): void
    {
        config(['marketplace.payments.gateway' => 'fake']);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->coupon(['code' => 'BETOCH500', 'percent_off' => null, 'amount_off_santim' => 50_000, 'min_items_santim' => 1_000_000]);
        $this->addToCart($this->sofa, 2);
        $this->checkout('BETOCH500', null, 'online')->assertCreated();
        $payment = Payment::sole();
        // Items 20,000 + delivery 300 + VAT 3,045 - coupon 500.
        $this->assertSame(2_284_500, $payment->amount_santim);
        $this->as($this->buyer)->postJson("/api/payments/test/{$payment->tx_ref}", ['outcome' => 'paid'])->assertOk();
        $order = $this->deliver(Order::sole());
        $this->assertSame(2_284_500, $order->paid_santim);
        // Betoch holds what the customer paid and owes the store that, plus the coupon, less the commission.
        $this->assertSame(['commission' => 200_000, 'coupon' => -50_000, 'sale' => -2_284_500], SellerLedgerEntry::where('order_id', $order->id)->orderBy('type')->pluck('amount_santim', 'type')->all());
        $this->assertSame(-(2_000_000 + 30_000 + 304_500 - 200_000), LedgerService::balance($this->seller->id));
    }

    public function test_a_delivery_fee_set_later_keeps_the_coupon(): void
    {
        $this->sofa->forceFill(['delivery_fee_santim' => null])->save();
        $this->coupon(['code' => 'TEN', 'percent_off' => 10]);
        $this->addToCart($this->sofa, 2);
        // No fee yet: VAT on the items for now, coupon off the items.
        $this->preview('TEN')->assertJsonPath('data.delivery_to_confirm', true)->assertJsonPath('data.grand_total_santim', 2_300_000 - 200_000);
        $this->checkout('TEN')->assertCreated();
        $order = Order::sole();
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '500'])->assertOk();
        $order->refresh();
        // ETB 20,000 + ETB 500, 15% VAT ETB 3,075, less the ETB 2,000 coupon.
        $this->assertSame([50_000, 307_500, 200_000, 2_157_500], [$order->delivery_fee_santim, $order->vat_santim, $order->coupon_discount_santim, $order->total_santim]);
        $this->assertFalse($order->vatInsidePrice());
    }

    public function test_an_order_whose_coupon_equals_its_vat_still_has_vat_on_top(): void
    {
        $order = new Order;
        $order->forceFill(['items_total_santim' => 100_000, 'delivery_fee_santim' => 0, 'vat_rate_bp' => 1500, 'vat_santim' => 15_000, 'coupon_discount_santim' => 15_000, 'total_santim' => 100_000]);
        $this->assertFalse($order->vatInsidePrice());
        $order->coupon_discount_santim = 0;
        $this->assertTrue($order->vatInsidePrice(), 'an older order with VAT inside its price');
    }

    public function test_the_coupon_is_shared_between_orders_to_the_santim(): void
    {
        $this->assertSame([34, 33, 33], CouponService::share(100, [100, 100, 100]));
        $this->assertSame([1, 1, 1], CouponService::share(100, [1, 1, 1]), 'never more than the items');
        $this->assertSame([1, 1, 0], CouponService::share(2, [1, 1, 1]));
        $this->assertSame([0, 1], CouponService::share(1, [1, 3]));
        $this->assertSame([5, 5], CouponService::share(10, [5, 5]));
        $this->assertSame([3, 4], CouponService::share(99, [3, 4]), 'never more than an order\'s items');
        $this->assertSame([0, 0], CouponService::share(0, [3, 4]));
        mt_srand(3);
        foreach (range(1, 2000) as $_) {
            $weights = array_map(fn () => mt_rand(1, mt_rand(0, 1) ? 1_000 : 100_000_000_000), range(1, mt_rand(1, 6)));
            $amount = mt_rand(0, array_sum($weights));
            $parts = CouponService::share($amount, $weights);
            $this->assertSame($amount, array_sum($parts));
            foreach ($parts as $i => $part) {
                $this->assertGreaterThanOrEqual(0, $part);
                $this->assertLessThanOrEqual($weights[$i], $part);
                $this->assertLessThan(1.0001, abs($part - $amount * $weights[$i] / array_sum($weights)));
            }
        }
    }
}
