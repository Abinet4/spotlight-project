<?php

namespace Tests\Concerns;

use App\Models\OfflineSaleItem;
use App\Models\OfflineSalePayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\SaleReturn;
use App\Models\SellerProfile;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * A store with one of every ERP record, and the list of ERP addresses with a request each one accepts.
 * Needs PlacesOrders.
 */
trait BuildsErpWorld
{
    /** @var array<string, int> store A's records */
    private array $a;

    /** @var array<string, int> store B's records */
    private array $b;

    private SellerProfile $other;

    /** Store A is $this->seller (names start with "Alpha"), store B is $this->other ("Beta"). */
    private function setUpErpWorld(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->setUpOrders();
        $this->sofa->forceFill(['name' => 'Alpha sofa', 'cost_price_santim' => 500_000])->save();
        $this->a = $this->world($this->seller, $this->sofa, 'Alpha');

        $this->other = $this->approvedSeller();
        $sofa = Product::factory()->published()->create([
            'seller_profile_id' => $this->other->id, 'category_id' => $this->category()->id, 'name' => 'Beta sofa',
            'price_santim' => 800_000, 'cost_price_santim' => 400_000, 'stock_quantity' => 50, 'delivery_fee_santim' => 30_000, 'pickup_available' => true,
        ]);
        $this->b = $this->world($this->other, $sofa, 'Beta');
    }

    /**
     * One of every ERP record for a store, made the way the pages make them.
     *
     * @return array<string, int>
     */
    private function world(SellerProfile $store, Product $plain, string $tag): array
    {
        $options = Product::factory()->published()->create([
            'seller_profile_id' => $store->id, 'category_id' => $this->category()->id, 'name' => "{$tag} table",
            'price_santim' => 300_000, 'stock_quantity' => 20, 'delivery_fee_santim' => 10_000, 'pickup_available' => true,
        ]);
        $order = $this->deliver($this->placeOrder(3, product: $plain));
        $unpaid = $this->placeOrder(1, product: $plain);
        $unpaid->forceFill(['payment_method' => 'online', 'payment_status' => 'pending'])->save();

        $this->as($store->user);
        $this->postJson("/api/seller/products/{$options->id}/variants", ['color' => 'Grey'])->assertOk();
        $this->getJson('/api/seller/erp/warehouses')->assertOk();
        $default = Warehouse::where('default_for', $store->id)->value('id');
        $room = $this->postJson('/api/seller/erp/warehouses', ['name' => "{$tag} store room"])->assertCreated()->json('data.id');
        $spare = $this->postJson('/api/seller/erp/warehouses', ['name' => "{$tag} spare room"])->assertCreated()->json('data.id');
        $closed = $this->postJson('/api/seller/erp/warehouses', ['name' => "{$tag} old room"])->assertCreated()->json('data.id');
        $this->postJson("/api/seller/erp/warehouses/{$closed}/close")->assertOk();
        $transfer = $this->postJson('/api/seller/erp/transfers', ['from_warehouse_id' => $default, 'to_warehouse_id' => $room,
            'items' => [['product_id' => $plain->id, 'quantity' => 2]]])->assertCreated()->json('data.id');

        $supplier = $this->postJson('/api/seller/erp/suppliers', ['name' => "{$tag} Wood"])->assertCreated()->json('data.id');
        $spareSupplier = $this->postJson('/api/seller/erp/suppliers', ['name' => "{$tag} Metal"])->assertCreated()->json('data.id');
        $po = fn () => $this->postJson('/api/seller/erp/purchases', ['supplier_id' => $supplier, 'notes' => "{$tag} order",
            'items' => [['product_id' => $plain->id, 'quantity' => 5, 'unit_cost' => '1000']]])->assertCreated()->json('data.id');
        [$ordered, $plainPo, $partPo, $draftPo] = [$po(), $po(), $po(), $po()];
        foreach ([$ordered, $plainPo, $partPo] as $id) {
            $this->postJson("/api/seller/erp/purchases/{$id}/order")->assertOk();
        }
        $this->postJson("/api/seller/erp/purchases/{$partPo}/receive", ['items' => [['item_id' => PurchaseOrderItem::where('purchase_order_id', $partPo)->value('id'), 'quantity' => 2]]])->assertOk();
        $invoice = $this->postJson("/api/seller/erp/suppliers/{$supplier}/invoices", ['invoice_number' => "{$tag}-1", 'amount' => '1000', 'purchase_order_id' => $ordered])
            ->assertCreated()->json('data.id');
        $supplierPayment = $this->postJson("/api/seller/erp/suppliers/{$supplier}/payments", ['amount' => '100', 'method' => 'cash'])->assertCreated()->json('data.id');

        $sale = fn () => $this->postJson('/api/seller/erp/sales', ['customer_name' => "{$tag} buyer", 'paid_now' => '5000', 'payment_method' => 'cash',
            'items' => [['product_id' => $plain->id, 'quantity' => 2, 'unit_price' => '10000']]])->assertCreated()->json('data.id');
        [$sale1, $sale2] = [$sale(), $sale()];
        $saleItem = OfflineSaleItem::where('offline_sale_id', $sale1)->value('id');
        $shopReturn = $this->postJson("/api/seller/erp/sales/offline/{$sale1}/returns", ['reason' => 'Wrong colour',
            'items' => [['item_id' => $saleItem, 'quantity' => 1, 'restock' => true]]])->assertCreated();
        $orderItem = $order->items()->value('id');
        $this->postJson("/api/seller/erp/sales/orders/{$order->id}/returns", ['reason' => 'Scratched',
            'items' => [['item_id' => $orderItem, 'quantity' => 1, 'restock' => true]]])->assertCreated();

        $category = $this->postJson('/api/seller/erp/expense-categories', ['name' => "{$tag} only"])->assertCreated()->json('data.id');
        $expense = $this->post('/api/seller/erp/expenses', ['expense_category_id' => $category, 'amount' => '500', 'description' => "{$tag} fuel",
            'payment_method' => 'cash', 'attachment' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $plainExpense = $this->post('/api/seller/erp/expenses', ['expense_category_id' => $category, 'amount' => '200', 'description' => "{$tag} water",
            'payment_method' => 'cash'], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return [
            'store' => $store->id, 'plain' => $plain->id, 'options' => $options->id,
            'variant' => ProductVariant::where('product_id', $options->id)->value('id'),
            'default' => $default, 'room' => $room, 'spare' => $spare, 'closed' => $closed,
            'transfer' => $transfer, 'transfer_item' => StockTransferItem::where('stock_transfer_id', $transfer)->value('id'),
            'supplier' => $supplier, 'spare_supplier' => $spareSupplier,
            'po' => $ordered, 'po_item' => PurchaseOrderItem::where('purchase_order_id', $ordered)->value('id'),
            'plain_po' => $plainPo, 'plain_po_item' => PurchaseOrderItem::where('purchase_order_id', $plainPo)->value('id'),
            'part_po' => $partPo, 'draft_po' => $draftPo,
            'invoice' => $invoice, 'supplier_payment' => $supplierPayment,
            'sale' => $sale1, 'sale_item' => $saleItem, 'sale2' => $sale2, 'sale2_item' => OfflineSaleItem::where('offline_sale_id', $sale2)->value('id'),
            'sale_payment' => OfflineSalePayment::where('offline_sale_id', $sale1)->value('id'),
            'shop_return' => $shopReturn->json('data.returns.0.id') ?? SaleReturn::where('offline_sale_id', $sale1)->value('id'),
            'order' => $order->id, 'order_item' => $orderItem, 'unpaid_order' => $unpaid->id,
            'order_return' => SaleReturn::where('order_id', $order->id)->value('id'),
            'category' => $category, 'expense' => $expense, 'plain_expense' => $plainExpense,
        ];
    }

    /**
     * Every ERP address with a record id: which of the owner's records goes in the address, and a body
     * the owner could send successfully.
     *
     * @return array<string, array{0: string, 1?: Closure(array<string, int>): array<string, mixed>}>
     */
    private function routes(): array
    {
        $none = fn () => [];
        $reason = fn () => ['reason' => 'Checking access'];

        return [
            'GET inventory/{id}' => ['plain'],
            'PUT inventory/{id}' => ['plain', fn () => ['cost_price' => '700', 'min_stock_level' => 3]],
            'POST inventory/{id}/adjust' => ['plain', fn () => ['type' => 'damaged', 'quantity' => 1, 'note' => 'Dropped']],
            'POST inventory/{id}/sku' => ['plain', $none],
            'GET warehouses/{id}' => ['room'],
            'PUT warehouses/{id}' => ['room', fn () => ['name' => 'Renamed room']],
            'POST warehouses/{id}/close' => ['spare', $none],
            'POST warehouses/{id}/reopen' => ['closed', $none],
            'GET transfers/{id}' => ['transfer'],
            'POST transfers/{id}/receive' => ['transfer', fn ($w) => ['items' => [['id' => $w['transfer_item'], 'received_quantity' => 2]]]],
            'POST transfers/{id}/cancel' => ['transfer', $none],
            'GET suppliers/{id}' => ['supplier'],
            'PUT suppliers/{id}' => ['supplier', fn () => ['name' => 'Renamed Wood', 'payment_days' => 30]],
            'DELETE suppliers/{id}' => ['spare_supplier', $none],
            'POST suppliers/{id}/invoices' => ['supplier', fn () => ['invoice_number' => 'X-2', 'amount' => '100']],
            'POST supplier-invoices/{id}/void' => ['invoice', $reason],
            'POST suppliers/{id}/payments' => ['supplier', fn () => ['amount' => '50', 'method' => 'cash']],
            'POST supplier-payments/{id}/void' => ['supplier_payment', $reason],
            'GET purchases/{id}' => ['po'],
            'PUT purchases/{id}' => ['po', fn () => ['notes' => 'Changed', 'supplier_reference' => 'REF-9']],
            'POST purchases/{id}/order' => ['draft_po', $none],
            'POST purchases/{id}/cancel' => ['plain_po', $reason],
            'POST purchases/{id}/close' => ['part_po', $reason],
            'POST purchases/{id}/receive' => ['po', fn ($w) => ['items' => [['item_id' => $w['po_item'], 'quantity' => 1]]]],
            'GET sales/offline/{id}' => ['sale'],
            'PUT sales/offline/{id}' => ['sale', fn () => ['customer_name' => 'Changed', 'note' => 'Changed']],
            'POST sales/offline/{id}/void' => ['sale2', $reason],
            'POST sales/offline/{id}/payments' => ['sale', fn () => ['amount' => '100', 'method' => 'cash']],
            'POST sale-payments/{id}/void' => ['sale_payment', $reason],
            'POST sales/offline/{id}/returns' => ['sale', fn ($w) => ['reason' => 'Broken leg', 'items' => [['item_id' => $w['sale_item'], 'quantity' => 1, 'restock' => true]]]],
            'GET sales/orders/{id}' => ['order'],
            'POST sales/orders/{id}/returns' => ['order', fn ($w) => ['reason' => 'Broken leg', 'items' => [['item_id' => $w['order_item'], 'quantity' => 1, 'restock' => true]]]],
            'POST sale-returns/{id}/void' => ['shop_return', $reason],
            'GET expenses/{id}' => ['expense'],
            'PUT expenses/{id}' => ['expense', fn () => ['description' => 'Changed', 'paid_to' => 'Someone']],
            'GET expenses/{id}/attachment' => ['expense'],
            'POST expenses/{id}/attachment' => ['plain_expense', fn () => ['attachment' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')]],
            'POST expenses/{id}/void' => ['expense', $reason],
            'PUT expense-categories/{id}' => ['category', fn () => ['name' => 'Renamed', 'is_active' => false]],
        ];
    }

    /** @return list<array{0: string, 1: string}> method and path of every ERP address */
    private function erpRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/seller/erp')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $out[] = [$method, substr($route->uri(), strlen('api/seller/erp/'))];
                }
            }
        }

        return $out;
    }

    /** Every row of every table, and every stored file: what a refused request must not change. */
    private function fingerprint(): string
    {
        $driver = DB::getDriverName();
        $database = DB::connection()->getDatabaseName();
        $rows = [];
        foreach (Schema::getTables() as $table) {
            if ($driver !== 'sqlite' && ($table['schema'] ?? $database) !== $database) {
                continue;
            }
            if (in_array($table['name'], ['cache', 'cache_locks', 'sessions', 'migrations'], true)) {
                continue;
            }
            $rows[$table['name']] = DB::table($table['name'])->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all();
        }
        ksort($rows);
        $files = [Storage::disk('local')->allFiles(), Storage::disk('public')->allFiles()];

        return md5(serialize([$rows, $files]));
    }

    private function send(string $method, string $path, array $body = []): TestResponse
    {
        $url = '/api/seller/erp/'.$path;
        $hasFile = collect($body)->contains(fn ($v) => $v instanceof UploadedFile);
        if ($hasFile) {
            return $this->call($method, $url, array_filter($body, fn ($v) => ! $v instanceof UploadedFile),
                [], array_filter($body, fn ($v) => $v instanceof UploadedFile), ['HTTP_ACCEPT' => 'application/json']);
        }

        return $this->json($method, $url, $body);
    }

    /** Sends a request and undoes whatever it changed in the database. */
    private function sendAndUndo(string $method, string $path, array $body = []): TestResponse
    {
        DB::beginTransaction();
        try {
            return $this->send($method, $path, $body);
        } finally {
            DB::rollBack();
        }
    }

    private function path(string $uri, array $world, string $kind): string
    {
        return str_replace(['{id}', '{report}'], [$world[$kind] ?? 0, 'sales'], $uri);
    }

    /** A valid body for the ERP addresses that make a new record (no id in the address). */
    private function createBody(string $uri): Closure
    {
        return match ($uri) {
            'settings' => fn () => ['low_stock_level' => 4],
            'warehouses' => fn () => ['name' => 'New room'],
            'transfers' => fn ($w) => ['from_warehouse_id' => $w['default'], 'to_warehouse_id' => $w['spare'], 'items' => [['product_id' => $w['plain'], 'quantity' => 1]]],
            'suppliers' => fn () => ['name' => 'New supplier'],
            'purchases' => fn ($w) => ['supplier_id' => $w['supplier'], 'items' => [['product_id' => $w['plain'], 'quantity' => 1, 'unit_cost' => '10']]],
            'sales' => fn ($w) => ['items' => [['product_id' => $w['plain'], 'quantity' => 1, 'unit_price' => '10']]],
            'expenses' => fn ($w) => ['expense_category_id' => $w['category'], 'amount' => '10', 'description' => 'Tea', 'payment_method' => 'cash'],
            'expense-categories' => fn () => ['name' => 'New kind'],
            default => fn () => [],
        };
    }
}
