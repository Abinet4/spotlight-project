<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Support\Erp\Period;
use App\Support\Erp\StockFigures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The ERP dashboard. Stock figures are as of now; sales figures cover the chosen period and
 * count marketplace orders once, on the day they were delivered. Profit here is an estimate
 * from the cost prices the store entered, not accounting.
 */
class OverviewController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly InventoryService $inventory) {}

    public function __invoke(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $period = Period::fromRequest($request);
        [$start, $end] = $period->bounds();

        return response()->json(['data' => [
            'range' => $period->toArray(),
            'stock' => $this->stock($store->id),
            'sales' => $this->sales($store->id, $period, $start, $end),
            'orders' => [
                'open' => Order::where('seller_profile_id', $store->id)->visibleToSeller()
                    ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])->count(),
                'pending_confirmation' => Order::where('seller_profile_id', $store->id)->visibleToSeller()
                    ->where('status', OrderStatus::PendingConfirmation->value)->count(),
            ],
            'recent_movements' => StockMovement::where('seller_profile_id', $store->id)
                ->with(['product:id,name,slug', 'warehouse:id,name', 'variant:id,size,color,material', 'order:id,number', 'user:id,name'])
                ->latest('id')->limit(8)->get()->map(fn ($m) => InventoryController::movement($m, withProduct: true)),
            'activity' => AuditLog::where('user_id', $request->user()->id)
                ->where(fn ($q) => $q->where('action', 'like', 'inventory.%')->orWhere('action', 'like', 'erp.%')->orWhere('action', 'like', 'product.%'))
                ->latest('id')->limit(8)->get(['id', 'action', 'meta', 'created_at'])
                ->map(fn ($a) => ['id' => $a->id, 'action' => $a->action, 'created_at' => $a->created_at?->toIso8601String()]),
        ]]);
    }

    /** @return array<string, mixed> */
    private function stock(int $storeId): array
    {
        $products = Product::where('seller_profile_id', $storeId)->where('status', '!=', ProductStatus::Archived->value)
            ->with('variants')->get();
        $figures = new StockFigures($products, $this->inventory);
        $items = [];
        foreach ($products as $p) {
            foreach ($figures->items($p) as $it) {
                $items[] = $it + ['product_id' => $p->id, 'name' => $p->name];
            }
        }
        $tracked = array_filter($items, fn ($i) => $i['tracked']);
        $alerts = array_values(array_filter($tracked, fn ($i) => in_array($i['state'], ['low', 'out'], true)));
        usort($alerts, fn ($a, $b) => $a['available'] <=> $b['available'] ?: strcmp($a['name'], $b['name']));

        return [
            'listings' => $products->count(),
            'items' => count($items),
            'available' => array_sum(array_column($tracked, 'available')),
            'held' => array_sum(array_column($tracked, 'held')),
            'value_cost_santim' => array_sum(array_map(fn ($i) => (int) $i['value_cost_santim'], $tracked)),
            'value_price_santim' => array_sum(array_column($tracked, 'value_price_santim')),
            'missing_cost' => count(array_filter($tracked, fn ($i) => $i['cost_price_santim'] === null)),
            'low' => count(array_filter($tracked, fn ($i) => $i['state'] === 'low')),
            'out' => count(array_filter($tracked, fn ($i) => $i['state'] === 'out')),
            'alerts' => array_map(fn ($i) => array_intersect_key($i, array_flip(['product_id', 'variant_id', 'name', 'label', 'sku', 'available', 'held', 'low_level', 'reorder_quantity', 'state'])), array_slice($alerts, 0, 8)),
        ];
    }

    /** @return array<string, mixed> */
    private function sales(int $storeId, Period $period, $start, $end): array
    {
        $delivered = Order::where('seller_profile_id', $storeId)->where('status', OrderStatus::Delivered->value)
            ->whereBetween('delivered_at', [$start, $end]);
        $orderIds = (clone $delivered)->pluck('id');
        $lines = OrderItem::whereIn('order_id', $orderIds);

        $days = array_fill_keys($period->days(), ['orders' => 0, 'items_santim' => 0]);
        foreach ((clone $delivered)->get(['id', 'delivered_at', 'items_total_santim']) as $o) {
            $day = $period->dayOf($o->delivered_at);
            if (isset($days[$day])) {
                $days[$day]['orders']++;
                $days[$day]['items_santim'] += $o->items_total_santim;
            }
        }

        $items = (int) (clone $delivered)->sum('items_total_santim');
        $costed = (clone $lines)->whereNotNull('unit_cost_santim')->selectRaw('sum(unit_cost_santim * quantity) as c, sum(line_total_santim) as r')->first();
        $cogs = (int) $costed->c;
        $withoutCost = (clone $lines)->whereNull('unit_cost_santim')->count();
        $uncostedSales = (int) (clone $lines)->whereNull('unit_cost_santim')->sum('line_total_santim');
        $commission = (int) (clone $delivered)->sum('commission_santim');
        $delivery = (int) (clone $delivered)->sum('delivery_fee_santim');

        $top = (clone $lines)->whereNull('quote_id')->whereNotNull('product_id')
            ->selectRaw('product_id, variant_id, max(name) as name, max(variant_label) as variant_label, sum(quantity) as units, sum(line_total_santim) as revenue')
            ->groupBy('product_id', 'variant_id')->orderByDesc('units')->orderByDesc('revenue')->limit(5)->get()
            ->map(fn ($r) => ['product_id' => (int) $r->product_id, 'variant_id' => $r->variant_id ? (int) $r->variant_id : null, 'name' => $r->name,
                'label' => $r->variant_label, 'units' => (int) $r->units, 'revenue_santim' => (int) $r->revenue]);

        return [
            'marketplace' => [
                'orders' => $orderIds->count(),
                'items_santim' => $items,
                'delivery_santim' => $delivery,
                'commission_santim' => $commission,
                'cost_of_goods_santim' => $cogs,
                'lines_without_cost' => $withoutCost,
                'sales_without_cost_santim' => $uncostedSales,
                // Only lines with a known cost: sales of items without a cost price are left out, not counted as profit.
                'gross_profit_santim' => (int) $costed->r - $cogs,
            ],
            'days' => collect($days)->map(fn ($d, $date) => ['date' => $date] + $d)->values(),
            'top_products' => $top,
        ];
    }
}
