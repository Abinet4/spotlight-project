<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Payments\PaymentGateway;
use App\Services\InventoryService;
use App\Support\Erp\StockFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Stock accuracy: long random runs of everything that moves stock (marketplace orders paid on
 * delivery and online, cancellations, unpaid online orders running out of time, deliveries,
 * returns, shop sales and their voids, purchase receipts, transfers, damage, found units and
 * counts) at two locations, on a listing without options and on two options of another listing.
 *
 * The test keeps its own count of where every unit should be, worked out from what each step
 * means, and after every step compares it with the database, the stock history and the ERP
 * figures. It also predicts which steps must be refused (not enough at that location, already
 * returned) and checks the server agrees. Runs are seeded, so a failure repeats exactly.
 */
class ErpStockAccuracyTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    private const D = 'default';

    private const R = 'room';

    /** @var array<string, array{0: Product, 1: ?ProductVariant}> */
    private array $items = [];

    /** @var array<string, Warehouse> */
    private array $loc = [];

    /** @var array<string, array<string, int>> units at each location, per item */
    private array $at = [];

    /** @var array<string, int> */
    private array $held = [];

    /** @var array<string, int> */
    private array $sold = [];

    /** @var array<string, int> stock and movement count before the run */
    private array $baseline = [];

    private int $baselineMovement = 0;

    private int $supplier;

    /** @var list<array<string, mixed>> */
    private array $orders = [];

    /** @var list<array<string, mixed>> */
    private array $sales = [];

    /** @var list<array<string, mixed>> */
    private array $returns = [];

    /** @var list<array<string, mixed>> */
    private array $transfers = [];

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.payments.gateway' => 'fake', 'marketplace.payments.window_minutes' => 30]);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->setUpOrders();
        Cache::flush();
        $this->sofa->forceFill(['stock_quantity' => 12, 'cost_price_santim' => 600_000])->save();

        $table = Product::factory()->published()->create([
            'seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'name' => 'Dining table',
            'price_santim' => 500_000, 'cost_price_santim' => 300_000, 'stock_quantity' => 8, 'delivery_fee_santim' => 20_000, 'pickup_available' => true,
        ]);
        $this->as($this->seller->user);
        $this->postJson("/api/seller/products/{$table->id}/variants", ['color' => 'Grey'])->assertOk();
        $this->postJson("/api/seller/products/{$table->id}/variants", ['color' => 'Brown', 'stock_quantity' => 5])->assertOk();
        $grey = ProductVariant::where('product_id', $table->id)->where('color', 'Grey')->sole();
        $brown = ProductVariant::where('product_id', $table->id)->where('color', 'Brown')->sole();
        $this->items = ['sofa' => [$this->sofa, null], 'grey' => [$table, $grey], 'brown' => [$table, $brown]];

        $this->getJson('/api/seller/erp/warehouses')->assertOk();
        $this->loc = [
            self::D => Warehouse::where('default_for', $this->seller->id)->sole(),
            self::R => Warehouse::findOrFail($this->postJson('/api/seller/erp/warehouses', ['name' => 'Bole store room'])->assertCreated()->json('data.id')),
        ];
        $this->supplier = $this->postJson('/api/seller/erp/suppliers', ['name' => 'Kality Wood'])->assertCreated()->json('data.id');

        foreach ($this->items as $key => [$product, $variant]) {
            $stock = $variant ? $variant->fresh()->stock_quantity : $product->fresh()->stock_quantity;
            $this->at[$key] = [self::D => $stock, self::R => 0];
            $this->held[$key] = 0;
            $this->sold[$key] = 0;
            $this->baseline[$key] = $stock;
        }
        $this->baselineMovement = (int) StockMovement::max('id');
        $this->assertSame(['sofa' => 12, 'grey' => 8, 'brown' => 5], $this->baseline);
    }

    /** @return array<string, array{int}> */
    public static function seeds(): array
    {
        return ['seed 7' => [7], 'seed 42' => [42], 'seed 2026' => [2026], 'seed 9001' => [9001]];
    }

    #[DataProvider('seeds')]
    public function test_stock_stays_right_through_a_long_random_run(int $seed): void
    {
        mt_srand($seed);
        $steps = ['order', 'order', 'online', 'cancel', 'deliver', 'deliver', 'orderReturn', 'sale', 'sale', 'voidSale',
            'saleReturn', 'voidReturn', 'receive', 'transfer', 'arrive', 'damaged', 'found', 'counted'];
        for ($i = 1; $i <= 140; $i++) {
            $step = $steps[mt_rand(0, count($steps) - 1)];
            $key = array_keys($this->items)[mt_rand(0, 2)];
            $this->{$step}($key);
            $this->check("seed {$seed}, step {$i} ({$step} {$key})");
        }
        $this->checkReports("seed {$seed}, end");
        $this->assertGreaterThan(60, count(array_filter($this->log, fn ($l) => ! str_contains($l, 'skipped'))), 'too few steps did anything');
    }

    // ---- what the store and its customers do ----

    private function avail(string $key): int
    {
        return $this->at[$key][self::D] + $this->at[$key][self::R];
    }

    private function line(string $key, array $extra = []): array
    {
        [$product, $variant] = $this->items[$key];

        return ['product_id' => $product->id, 'variant_id' => $variant?->id, ...$extra];
    }

    private function expect(bool $ok, TestResponse $res, string $what): bool
    {
        $this->log[] = "{$what}: {$res->getStatusCode()}";
        $this->assertSame($ok ? 'accepted' : 'refused', $res->isSuccessful() ? 'accepted' : 'refused',
            "{$what} should have been ".($ok ? 'accepted' : 'refused').": {$res->getStatusCode()} ".mb_substr($res->getContent(), 0, 300)."\n".implode("\n", array_slice($this->log, -15)));
        if (! $ok) {
            $this->assertContains($res->getStatusCode(), [422], $what);
        }

        return $ok;
    }

    /** Takes from the default location first, then the others (as checkout does). */
    private function reserve(string $key, int $qty): array
    {
        $fromD = min($qty, $this->at[$key][self::D]);
        $taken = [self::D => $fromD, self::R => $qty - $fromD];
        $this->at[$key][self::D] -= $taken[self::D];
        $this->at[$key][self::R] -= $taken[self::R];
        $this->held[$key] += $qty;

        return $taken;
    }

    private function checkout(string $key, int $qty, string $method): TestResponse
    {
        [$product, $variant] = $this->items[$key];
        CartItem::where('user_id', $this->buyer->id)->delete();
        $add = $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $product->slug, 'quantity' => $qty, 'variant_id' => $variant?->id]);
        if (! $add->isSuccessful()) {
            return $add;
        }
        $expected = $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.grand_total_santim');

        return $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => $method, 'expected_total_santim' => $expected],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function order(string $key): void
    {
        $qty = mt_rand(1, 4);
        $ok = $qty <= $this->avail($key);
        if ($this->expect($ok, $this->checkout($key, $qty, 'cod'), "order {$qty} {$key}")) {
            $this->orders[] = ['id' => Order::latest('id')->value('id'), 'key' => $key, 'qty' => $qty, 'state' => 'open', 'taken' => $this->reserve($key, $qty), 'returned' => 0];
        }
    }

    /** An online order: paid (then it is like any other order) or left unpaid until it runs out of time. */
    private function online(string $key): void
    {
        $qty = mt_rand(1, 3);
        $ok = $qty <= $this->avail($key);
        if (! $this->expect($ok, $this->checkout($key, $qty, 'online'), "online order {$qty} {$key}")) {
            return;
        }
        $order = Order::latest('id')->first();
        $taken = $this->reserve($key, $qty);
        if (mt_rand(0, 1)) {
            $payment = Payment::where('checkout_id', $order->checkout_id)->latest('id')->first();
            $this->as($this->buyer)->postJson("/api/payments/test/{$payment->tx_ref}", ['outcome' => 'paid'])->assertOk()->assertJsonPath('data.status', 'paid');
            $this->orders[] = ['id' => $order->id, 'key' => $key, 'qty' => $qty, 'state' => 'open', 'taken' => $taken, 'returned' => 0];
            $this->log[] = '  paid';

            return;
        }
        $this->check('online order waiting for payment');
        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-payments')->assertSuccessful();
        $this->assertSame('cancelled', $order->fresh()->status->value);
        CartItem::where('user_id', $this->buyer->id)->delete();
        $this->at[$key][self::D] += $taken[self::D];
        $this->at[$key][self::R] += $taken[self::R];
        $this->held[$key] -= $qty;
        $this->log[] = '  ran out of time';
    }

    private function pick(array $list, callable $filter): ?int
    {
        $keys = array_keys(array_filter($list, $filter));

        return $keys ? $keys[mt_rand(0, count($keys) - 1)] : null;
    }

    private function cancel(string $key): void
    {
        $i = $this->pick($this->orders, fn ($o) => $o['state'] === 'open');
        if ($i === null) {
            $this->log[] = 'cancel skipped';

            return;
        }
        $o = &$this->orders[$i];
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$o['id']}/cancel", ['reason' => 'Customer called'])->assertOk();
        $this->at[$o['key']][self::D] += $o['taken'][self::D];
        $this->at[$o['key']][self::R] += $o['taken'][self::R];
        $this->held[$o['key']] -= $o['qty'];
        $o['state'] = 'cancelled';
        $this->log[] = "cancel order {$o['id']}";
    }

    private function deliver(string $key): void
    {
        $i = $this->pick($this->orders, fn ($o) => $o['state'] === 'open');
        if ($i === null) {
            $this->log[] = 'deliver skipped';

            return;
        }
        $o = &$this->orders[$i];
        $this->deliverOrder(Order::findOrFail($o['id']));
        $this->held[$o['key']] -= $o['qty'];
        $this->sold[$o['key']] += $o['qty'];
        $o['state'] = 'delivered';
        $this->log[] = "deliver order {$o['id']}";
    }

    private function deliverOrder(Order $order): void
    {
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
    }

    private function orderReturn(string $key): void
    {
        $i = $this->pick($this->orders, fn ($o) => $o['state'] === 'delivered');
        if ($i === null) {
            $this->log[] = 'order return skipped';

            return;
        }
        $o = &$this->orders[$i];
        $left = $o['qty'] - $o['returned'];
        $qty = mt_rand(1, max(1, $left + 1)); // sometimes one more than is left
        $restock = (bool) mt_rand(0, 1);
        $where = mt_rand(0, 1) ? self::D : self::R;
        $item = Order::findOrFail($o['id'])->items()->value('id');
        $res = $this->as($this->seller->user)->postJson("/api/seller/erp/sales/orders/{$o['id']}/returns", ['reason' => 'Scratched',
            'warehouse_id' => $this->loc[$where]->id, 'items' => [['item_id' => $item, 'quantity' => $qty, 'restock' => $restock]]]);
        if ($this->expect($qty <= $left, $res, "return {$qty} of order {$o['id']} ".($restock ? "to {$where}" : 'not restocked'))) {
            $o['returned'] += $qty;
            $this->sold[$o['key']] -= $qty;
            if ($restock) {
                $this->at[$o['key']][$where] += $qty;
            }
            $this->returns[] = ['id' => SaleReturn::latest('id')->value('id'), 'key' => $o['key'], 'qty' => $qty, 'restock' => $restock,
                'where' => $where, 'voided' => false, 'order' => $i];
        }
    }

    private function sale(string $key): void
    {
        $qty = mt_rand(1, 4);
        $where = mt_rand(0, 3) ? self::D : self::R;
        $res = $this->as($this->seller->user)->postJson('/api/seller/erp/sales', ['warehouse_id' => $this->loc[$where]->id, 'paid_now' => '100', 'payment_method' => 'cash',
            'items' => [$this->line($key, ['quantity' => $qty, 'unit_price' => '9000'])]]);
        if ($this->expect($qty <= $this->at[$key][$where], $res, "shop sale {$qty} {$key} from {$where}")) {
            $this->at[$key][$where] -= $qty;
            $this->sold[$key] += $qty;
            $this->sales[] = ['id' => $res->json('data.id'), 'item' => $res->json('data.items.0.id'), 'key' => $key, 'qty' => $qty, 'where' => $where, 'returned' => 0, 'returns' => 0, 'voided' => false];
        }
    }

    private function voidSale(string $key): void
    {
        $i = $this->pick($this->sales, fn ($s) => ! $s['voided']);
        if ($i === null) {
            $this->log[] = 'void sale skipped';

            return;
        }
        $s = &$this->sales[$i];
        $res = $this->as($this->seller->user)->postJson("/api/seller/erp/sales/offline/{$s['id']}/void", ['reason' => 'Typed twice']);
        if ($this->expect($s['returns'] === 0, $res, "void sale {$s['id']}")) {
            $s['voided'] = true;
            $this->at[$s['key']][$s['where']] += $s['qty'];
            $this->sold[$s['key']] -= $s['qty'];
        }
    }

    private function saleReturn(string $key): void
    {
        $i = $this->pick($this->sales, fn ($s) => ! $s['voided']);
        if ($i === null) {
            $this->log[] = 'shop return skipped';

            return;
        }
        $s = &$this->sales[$i];
        $left = $s['qty'] - $s['returned'];
        $qty = mt_rand(1, max(1, $left + 1));
        $restock = (bool) mt_rand(0, 1);
        $where = mt_rand(0, 1) ? self::D : self::R;
        $res = $this->as($this->seller->user)->postJson("/api/seller/erp/sales/offline/{$s['id']}/returns", ['reason' => 'Wrong colour',
            'warehouse_id' => $this->loc[$where]->id, 'items' => [['item_id' => $s['item'], 'quantity' => $qty, 'restock' => $restock]]]);
        if ($this->expect($qty <= $left, $res, "return {$qty} of shop sale {$s['id']} ".($restock ? "to {$where}" : 'not restocked'))) {
            $s['returned'] += $qty;
            $s['returns']++;
            $this->sold[$s['key']] -= $qty;
            if ($restock) {
                $this->at[$s['key']][$where] += $qty;
            }
            $this->returns[] = ['id' => SaleReturn::latest('id')->value('id'), 'key' => $s['key'], 'qty' => $qty,
                'restock' => $restock, 'where' => $where, 'voided' => false, 'sale' => $i];
        }
    }

    private function voidReturn(string $key): void
    {
        $i = $this->pick($this->returns, fn ($r) => ! $r['voided']);
        if ($i === null) {
            $this->log[] = 'void return skipped';

            return;
        }
        $r = &$this->returns[$i];
        $ok = ! $r['restock'] || $this->at[$r['key']][$r['where']] >= $r['qty'];
        $res = $this->as($this->seller->user)->postJson("/api/seller/erp/sale-returns/{$r['id']}/void", ['reason' => 'Entered by mistake']);
        if ($this->expect($ok, $res, "void return {$r['id']} ({$r['qty']} ".($r['restock'] ? "at {$r['where']}" : 'not restocked').')')) {
            $r['voided'] = true;
            $this->sold[$r['key']] += $r['qty'];
            if ($r['restock']) {
                $this->at[$r['key']][$r['where']] -= $r['qty'];
            }
            if (isset($r['sale'])) {
                $this->sales[$r['sale']]['returned'] -= $r['qty'];
                $this->sales[$r['sale']]['returns']--;
            } else {
                $this->orders[$r['order']]['returned'] -= $r['qty'];
            }
        }
    }

    private function receive(string $key): void
    {
        $qty = mt_rand(1, 6);
        $where = mt_rand(0, 1) ? self::D : self::R;
        $this->as($this->seller->user);
        $po = $this->postJson('/api/seller/erp/purchases', ['supplier_id' => $this->supplier, 'warehouse_id' => $this->loc[$where]->id,
            'items' => [$this->line($key, ['quantity' => $qty + 2, 'unit_cost' => '4000'])]])->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/order")->assertOk();
        $receipt = ['items' => [['item_id' => $po['items'][0]['id'], 'quantity' => $qty]], 'request_id' => (string) Str::uuid()];
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/receive", $receipt)->assertOk();
        // The same receipt sent twice (a double click, a retry) adds nothing more.
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/receive", $receipt)->assertOk();
        // More than is still expected is refused.
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/receive", ['items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 3]]])->assertUnprocessable();
        $this->at[$key][$where] += $qty;
        $this->log[] = "receive {$qty} {$key} at {$where}";
    }

    private function transfer(string $key): void
    {
        $qty = mt_rand(1, 4);
        [$from, $to] = mt_rand(0, 2) ? [self::D, self::R] : [self::R, self::D];
        $res = $this->as($this->seller->user)->postJson('/api/seller/erp/transfers', ['from_warehouse_id' => $this->loc[$from]->id, 'to_warehouse_id' => $this->loc[$to]->id,
            'items' => [$this->line($key, ['quantity' => $qty])]]);
        if ($this->expect($qty <= $this->at[$key][$from], $res, "send {$qty} {$key} from {$from} to {$to}")) {
            $this->at[$key][$from] -= $qty;
            $this->transfers[] = ['id' => $res->json('data.id'), 'key' => $key, 'qty' => $qty, 'from' => $from, 'to' => $to, 'done' => false];
        }
    }

    /** A transfer arrives in full, or is called off and its units go back. */
    private function arrive(string $key): void
    {
        $i = $this->pick($this->transfers, fn ($t) => ! $t['done']);
        if ($i === null) {
            $this->log[] = 'transfer arrival skipped';

            return;
        }
        $t = &$this->transfers[$i];
        $cancel = mt_rand(0, 3) === 0;
        $this->as($this->seller->user)->postJson("/api/seller/erp/transfers/{$t['id']}/".($cancel ? 'cancel' : 'receive'))->assertOk();
        $this->postJson("/api/seller/erp/transfers/{$t['id']}/receive")->assertUnprocessable();
        $this->at[$t['key']][$cancel ? $t['from'] : $t['to']] += $t['qty'];
        $t['done'] = true;
        $this->log[] = ($cancel ? 'cancel' : 'receive')." transfer {$t['id']}";
    }

    private function adjust(string $key, string $type, int $qty, string $where): TestResponse
    {
        [$product, $variant] = $this->items[$key];

        return $this->as($this->seller->user)->postJson("/api/seller/erp/inventory/{$product->id}/adjust", ['variant_id' => $variant?->id,
            'warehouse_id' => $this->loc[$where]->id, 'type' => $type, 'quantity' => $qty, 'note' => 'Checked']);
    }

    private function damaged(string $key): void
    {
        $qty = mt_rand(1, 2);
        $where = mt_rand(0, 1) ? self::D : self::R;
        if ($this->expect($qty <= $this->at[$key][$where], $this->adjust($key, 'damaged', $qty, $where), "damaged {$qty} {$key} at {$where}")) {
            $this->at[$key][$where] -= $qty;
        }
    }

    private function found(string $key): void
    {
        $qty = mt_rand(1, 2);
        $where = mt_rand(0, 1) ? self::D : self::R;
        $this->expect(true, $this->adjust($key, 'found', $qty, $where), "found {$qty} {$key} at {$where}");
        $this->at[$key][$where] += $qty;
    }

    private function counted(string $key): void
    {
        $where = mt_rand(0, 1) ? self::D : self::R;
        $counted = max(0, $this->at[$key][$where] + mt_rand(-2, 2));
        $this->expect(true, $this->adjust($key, 'count', $counted, $where), "count {$key} at {$where}: {$counted}");
        $this->at[$key][$where] = $counted;
    }

    // ---- the checks ----

    private function check(string $when): void
    {
        $inventory = app(InventoryService::class);
        $context = $when."\n".implode("\n", array_slice($this->log, -12));
        $table = $this->items['grey'][0]->fresh();
        $figures = new StockFigures(Product::whereIn('id', [$this->sofa->id, $table->id])->with('variants')->get(), $inventory);
        $byKey = [];
        foreach (Product::whereIn('id', [$this->sofa->id, $table->id])->with('variants')->get() as $p) {
            foreach ($figures->items($p) as $it) {
                $byKey[$p->id.':'.(int) ($it['variant_id'] ?? 0)] = $it;
            }
        }

        foreach ($this->items as $key => [$product, $variant]) {
            $product = $product->fresh();
            $variant = $variant?->fresh();
            $expected = $this->avail($key);
            $this->assertSame($expected, $variant ? $variant->stock_quantity : $product->stock_quantity, "{$key} available, {$context}");
            $this->assertSame($expected, $inventory->available($product, $variant), "{$key} available (service), {$context}");
            $this->assertSame($this->at[$key][self::D], $inventory->quantityAt($product, $variant, $this->loc[self::D]), "{$key} at the default location, {$context}");
            $this->assertSame($this->at[$key][self::R], $inventory->quantityAt($product, $variant, $this->loc[self::R]), "{$key} at the store room, {$context}");

            // The stock history adds up to the same: overall and at the store room.
            $moves = StockMovement::where('id', '>', $this->baselineMovement)->where('product_id', $product->id)
                ->when($variant, fn ($q) => $q->where('variant_id', $variant->id), fn ($q) => $q->whereNull('variant_id'));
            $this->assertSame($expected - $this->baseline[$key], (int) (clone $moves)->sum('quantity'), "{$key} history total, {$context}");
            $this->assertSame($this->at[$key][self::R], (int) (clone $moves)->where('warehouse_id', $this->loc[self::R]->id)->sum('quantity'), "{$key} history at the store room, {$context}");
            $last = (clone $moves)->latest('id')->first();
            if ($last) {
                $this->assertSame($expected, (int) $last->available_after, "{$key} last history line's 'available after', {$context}");
            }

            $it = $byKey[$product->id.':'.(int) $variant?->id];
            $this->assertSame($this->held[$key], (int) $it['held'], "{$key} held for open orders, {$context}");
            $this->assertSame($this->sold[$key], (int) $it['sold'], "{$key} sold, {$context}");
        }
        // A listing with options holds exactly what its options hold.
        $this->assertSame($this->avail('grey') + $this->avail('brown'), $table->stock_quantity, "table total, {$context}");
        $this->assertSame(0, WarehouseStock::where('quantity', '<', 0)->count(), "no location below zero, {$context}");
        $inTransit = collect($this->transfers)->where('done', false)->sum('qty');
        $this->assertSame($inTransit, (int) StockTransferItem::whereHas('transfer', fn ($q) => $q->where('status', 'in_transit'))->sum('quantity'), "units on the way, {$context}");
    }

    private function checkReports(string $when): void
    {
        $this->as($this->seller->user);
        $valuation = collect($this->getJson('/api/seller/erp/reports/valuation?all=1')->assertOk()->json('data'));
        foreach ($this->items as $key => [$product, $variant]) {
            $row = $valuation->first(fn ($r) => $r['name'] === $product->name && ($variant ? $r['option'] === $variant->label() : true));
            $this->assertNotNull($row, "{$key} in the valuation report");
            $this->assertSame($this->avail($key) + $this->held[$key], $row['on_hand'], "{$key} on hand in the valuation report, {$when}");
            $this->assertSame($this->held[$key], $row['held'], "{$key} held in the valuation report, {$when}");

            $shown = $this->getJson("/api/seller/erp/inventory/{$product->id}")->assertOk()->json('data');
            $item = $variant ? collect($shown['items'] ?? $shown['variants'] ?? [])->firstWhere('variant_id', $variant->id) : ($shown['items'][0] ?? $shown);
            if ($item && array_key_exists('available', $item)) {
                $this->assertSame($this->avail($key), $item['available'], "{$key} on the inventory page, {$when}");
            }
        }
        $sales = collect($this->getJson('/api/seller/erp/reports/products?period=year&all=1')->assertOk()->json('data'));
        foreach ($this->items as $key => [$product, $variant]) {
            $row = $sales->first(fn ($r) => $r['name'] === $product->name && ($variant ? $r['option'] === $variant->label() : true));
            $net = $row ? $row['units'] - ($row['returned_units'] ?? 0) : 0;
            $this->assertSame($this->sold[$key], $net, "{$key} units sold less returned in the product report, {$when}: ".json_encode($row));
        }
    }
}
