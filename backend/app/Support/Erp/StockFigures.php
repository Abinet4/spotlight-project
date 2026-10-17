<?php

namespace App\Support\Erp;

use App\Enums\OrderStatus;
use App\Enums\StockType;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Support\Collection;

/**
 * Per stock item numbers for a set of listings: available, held by open orders, sold, value and
 * low-stock state. Keyed "productId:variantId" (variant 0 for a listing without options).
 */
class StockFigures
{
    /** @var Collection<string, int> */
    private Collection $held;

    /** @var Collection<string, int> */
    private Collection $sold;

    /**
     * @param  Collection<int, Product>  $products  with variants loaded
     */
    public function __construct(private readonly Collection $products, private readonly InventoryService $inventory)
    {
        $ids = $products->pluck('id')->all();
        $key = fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->product_id.':'.(int) $r->variant_id => (int) $r->n]);

        // Units taken from stock by orders that are not delivered or cancelled yet.
        $this->held = $key(OrderItem::whereIn('product_id', $ids)->where('reserved_quantity', '>', 0)
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value]))
            ->selectRaw('product_id, variant_id, sum(reserved_quantity) as n')->groupBy('product_id', 'variant_id')->get());
        $this->sold = $key(OrderItem::whereIn('product_id', $ids)->whereNull('quote_id')
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Delivered->value))
            ->selectRaw('product_id, variant_id, sum(quantity) as n')->groupBy('product_id', 'variant_id')->get());
    }

    /**
     * Every stock item of one listing: the listing itself, or each of its options.
     *
     * @return list<array<string, mixed>>
     */
    public function items(Product $product): array
    {
        if ($product->variants->isEmpty()) {
            return [$this->item($product, null)];
        }

        return $product->variants->map(fn (ProductVariant $v) => $this->item($product, $v))->values()->all();
    }

    /** @return array<string, mixed> */
    public function item(Product $product, ?ProductVariant $variant): array
    {
        $key = $product->id.':'.(int) $variant?->id;
        $tracked = $product->stock_type === StockType::InStock;
        $available = $tracked ? $this->inventory->available($product, $variant) : 0;
        $held = (int) ($this->held[$key] ?? 0);
        $cost = InventoryService::unitCost($product, $variant);
        $price = $variant ? $variant->priceSantim($product) : (int) $product->price_santim;
        $level = $this->inventory->lowLevel($product, $variant);
        $onHand = $available + $held;

        return [
            'variant_id' => $variant?->id,
            'label' => $variant?->label(),
            'sku' => $variant ? $variant->sku : $product->sku,
            'tracked' => $tracked,
            'available' => $available,
            'held' => $held,
            'sold' => (int) ($this->sold[$key] ?? 0),
            'price_santim' => $price,
            'cost_price_santim' => $cost,
            'own_cost_price_santim' => $variant ? $variant->cost_price_santim : $product->cost_price_santim,
            'min_stock_level' => $variant ? $variant->min_stock_level : $product->min_stock_level,
            'reorder_quantity' => $variant ? $variant->reorder_quantity : $product->reorder_quantity,
            'low_level' => $level,
            'state' => ! $tracked ? 'made_to_order' : ($available === 0 ? 'out' : ($available <= $level ? 'low' : 'ok')),
            'value_cost_santim' => $tracked && $cost !== null ? $onHand * $cost : null,
            'value_price_santim' => $tracked ? $onHand * $price : 0,
        ];
    }
}
