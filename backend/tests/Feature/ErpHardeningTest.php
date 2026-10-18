<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfflineSaleItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Phase 5 fixes: each test fails on the code before the fix.
 */
class ErpHardeningTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $this->sofa->forceFill(['cost_price_santim' => 600_000])->save();
    }

    private function madeToOrder(): Product
    {
        return Product::factory()->published()->create([
            'seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'name' => 'Custom bed',
            'price_santim' => 2_000_000, 'stock_quantity' => 0, 'stock_type' => 'made_to_order', 'production_days' => 14,
            'delivery_fee_santim' => 30_000, 'pickup_available' => true,
        ]);
    }

    /** The listing's count must equal the sum of its options' counts. */
    private function assertOptionsAddUp(Product $product): void
    {
        $product->refresh();
        $this->assertSame((int) $product->stock_quantity, (int) ProductVariant::where('product_id', $product->id)->sum('stock_quantity'));
    }

    public function test_a_return_cannot_put_back_into_stock_units_that_never_left_it(): void
    {
        // A marketplace order of a made-to-order bed takes no stock; switching the listing to in stock
        // later must not let a return add those units.
        $bed = $this->madeToOrder();
        $order = $this->deliver($this->placeOrder(2, product: $bed));
        $line = $order->items()->first();
        $this->assertSame(0, (int) $line->reserved_quantity);
        $bed->forceFill(['stock_type' => 'in_stock'])->save();

        $this->as($this->seller->user);
        $shown = $this->getJson("/api/seller/erp/sales/orders/{$order->id}")->assertOk()->json('data.items.0');
        $this->assertFalse($shown['stock_item']);
        $this->postJson("/api/seller/erp/sales/orders/{$order->id}/returns", ['reason' => 'Changed mind',
            'items' => [['item_id' => $line->id, 'quantity' => 2, 'restock' => true]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(0, $bed->fresh()->stock_quantity);
        // The same return without restocking is fine.
        $this->postJson("/api/seller/erp/sales/orders/{$order->id}/returns", ['reason' => 'Changed mind',
            'items' => [['item_id' => $line->id, 'quantity' => 2, 'restock' => false]]])->assertCreated();

        // A shop sale of it while made to order: same.
        $bed->forceFill(['stock_type' => 'made_to_order'])->save();
        $sale = $this->postJson('/api/seller/erp/sales', ['items' => [['product_id' => $bed->id, 'quantity' => 1, 'unit_price' => '20000']]])->assertCreated()->json('data');
        $this->assertFalse((bool) OfflineSaleItem::find($sale['items'][0]['id'])->stock_taken);
        $bed->forceFill(['stock_type' => 'in_stock'])->save();
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/returns", ['reason' => 'Changed mind',
            'items' => [['item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'restock' => true]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(0, $bed->fresh()->stock_quantity);

        // A line that did take stock still goes back.
        $sofaOrder = $this->deliver($this->placeOrder(1));
        $this->as($this->seller->user)->postJson("/api/seller/erp/sales/orders/{$sofaOrder->id}/returns", ['reason' => 'Scratched',
            'items' => [['item_id' => $sofaOrder->items()->value('id'), 'quantity' => 1, 'restock' => true]]])->assertCreated();
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);
    }

    public function test_voiding_a_return_after_the_listing_got_options_takes_the_units_from_its_first_option(): void
    {
        $this->as($this->seller->user);
        $sale = $this->postJson('/api/seller/erp/sales', ['items' => [['product_id' => $this->sofa->id, 'quantity' => 2, 'unit_price' => '10000']]])->assertCreated()->json('data');
        $return = $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/returns", ['reason' => 'Wrong colour',
            'items' => [['item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'restock' => true]]])->assertCreated()->json('data.returns.0.id');
        $this->assertSame(49, $this->sofa->fresh()->stock_quantity);

        $this->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Grey'])->assertOk();
        $this->assertOptionsAddUp($this->sofa);

        $this->postJson("/api/seller/erp/sale-returns/{$return}/void", ['reason' => 'Entered by mistake'])->assertOk();
        $this->assertSame(48, $this->sofa->fresh()->stock_quantity);
        $this->assertOptionsAddUp($this->sofa);
    }

    public function test_a_payment_form_used_for_one_supplier_is_not_taken_as_a_payment_to_another(): void
    {
        $this->as($this->seller->user);
        $first = $this->postJson('/api/seller/erp/suppliers', ['name' => 'Kality Wood'])->assertCreated()->json('data.id');
        $second = $this->postJson('/api/seller/erp/suppliers', ['name' => 'Merkato Metal'])->assertCreated()->json('data.id');
        foreach ([$first, $second] as $id) {
            $this->postJson("/api/seller/erp/suppliers/{$id}/invoices", ['invoice_number' => "INV-{$id}", 'amount' => '1000'])->assertCreated();
        }
        $form = (string) Str::uuid();
        $paid = $this->postJson("/api/seller/erp/suppliers/{$first}/payments", ['amount' => '100', 'method' => 'cash', 'request_id' => $form])->assertCreated()->json('data.id');
        // The same form sent again is the same payment, not a second one.
        $this->postJson("/api/seller/erp/suppliers/{$first}/payments", ['amount' => '100', 'method' => 'cash', 'request_id' => $form])->assertCreated()->assertJsonPath('data.id', $paid);
        $this->postJson("/api/seller/erp/suppliers/{$second}/payments", ['amount' => '100', 'method' => 'cash', 'request_id' => $form])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(1, SupplierPayment::count());
    }

    public function test_dates_before_2000_or_after_2099_are_refused_with_a_message(): void
    {
        $this->as($this->seller->user);
        $supplier = $this->postJson('/api/seller/erp/suppliers', ['name' => 'Kality Wood'])->assertCreated()->json('data.id');
        $line = [['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_cost' => '100']];
        $this->postJson('/api/seller/erp/purchases', ['supplier_id' => $supplier, 'order_date' => '1999-12-31', 'items' => $line])->assertUnprocessable()->assertJsonValidationErrors('order_date');
        $this->postJson('/api/seller/erp/purchases', ['supplier_id' => $supplier, 'expected_date' => '2100-01-01', 'items' => $line])->assertUnprocessable()->assertJsonValidationErrors('expected_date');
        $this->postJson("/api/seller/erp/suppliers/{$supplier}/invoices", ['invoice_number' => 'A', 'amount' => '10', 'invoice_date' => '1900-01-01'])->assertUnprocessable()->assertJsonValidationErrors('invoice_date');
        $this->postJson("/api/seller/erp/suppliers/{$supplier}/invoices", ['invoice_number' => 'A', 'amount' => '10', 'due_date' => '9999-12-31'])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->postJson("/api/seller/erp/suppliers/{$supplier}/payments", ['amount' => '10', 'method' => 'cash', 'paid_on' => '0001-01-01'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->postJson('/api/seller/erp/sales', ['due_date' => '3000-01-01', 'items' => [['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => '10']]])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        // A number or a list where a date goes gets a message, not a crash.
        $this->postJson('/api/seller/erp/sales', ['sale_date' => PHP_INT_MAX, 'items' => [['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => '10']]])->assertUnprocessable()->assertJsonValidationErrors('sale_date');
        $this->getJson('/api/seller/erp/movements?from[]=x&to=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/seller/erp/purchases?from=2026-01-01&to[]=x')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/seller/erp/purchases?from=2026-02-01&to=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->as($this->admin())->getJson('/api/admin/reports/summary?from[]=x&to=2026-01-01')->assertUnprocessable();
    }

    public function test_cost_price_changes_and_voided_sale_payments_are_in_the_activity_log_with_amounts(): void
    {
        $this->as($this->seller->user);
        $this->putJson("/api/seller/erp/inventory/{$this->sofa->id}", ['cost_price' => '6500'])->assertOk();
        $log = AuditLog::where('action', 'inventory.item_updated')->latest('id')->first();
        // assertEquals: MySQL keeps JSON keys in its own order.
        $this->assertEquals(['from' => 600_000, 'to' => 650_000], $log->meta['changes']['cost_price_santim']);

        $supplier = $this->postJson('/api/seller/erp/suppliers', ['name' => 'Kality Wood'])->assertCreated()->json('data.id');
        $po = $this->postJson('/api/seller/erp/purchases', ['supplier_id' => $supplier, 'items' => [['product_id' => $this->sofa->id, 'quantity' => 50, 'unit_cost' => '5500']]])->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/order")->assertOk();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/receive", ['items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 50]]])->assertOk();
        // 50 at 6,500 and 50 at 5,500 average to 6,000.
        $this->assertSame(600_000, $this->sofa->fresh()->cost_price_santim);
        $received = AuditLog::where('action', 'erp.purchase_received')->latest('id')->first();
        $this->assertEquals([['product_id' => $this->sofa->id, 'variant_id' => null, 'from' => 650_000, 'to' => 600_000]], $received->meta['cost_prices_changed']);

        $sale = $this->postJson('/api/seller/erp/sales', ['paid_now' => '4000', 'payment_method' => 'cash',
            'items' => [['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => '10000']]])->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/payments", ['amount' => '1000', 'method' => 'telebirr'])->assertCreated();
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/void", ['reason' => 'Typed twice'])->assertOk();
        $voided = AuditLog::where('action', 'erp.sale_voided')->latest('id')->first();
        $this->assertSame([400_000, 100_000], collect($voided->meta['payments_voided'])->pluck('amount_santim')->all());
    }

    public function test_every_erp_change_is_rate_limited_and_every_spreadsheet_download_too(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/seller/erp')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            if (str_ends_with($route->uri(), '.csv')) {
                $this->assertContains('throttle:erp-exports', $middleware, $route->uri());
            } elseif (array_diff($route->methods(), ['GET', 'HEAD'])) {
                $this->assertContains('throttle:erp-writes', $middleware, implode('|', $route->methods()).' '.$route->uri());
            }
        }
    }
}
