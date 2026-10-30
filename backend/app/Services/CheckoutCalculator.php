<?php

namespace App\Services;

use App\Enums\StockType;
use App\Models\Address;
use App\Models\Area;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Prices a cart the same way for the cart page, the checkout preview and order placement,
 * so what the customer sees is exactly what is charged.
 *
 * Lines are grouped by seller; each group becomes one order. Delivery is one trip per seller:
 * the group fee is the highest line fee. A line's fee comes from the listing, else the distance from the store's map pin to the customer's (when both are set), else the
 * delivery zone of the chosen sub-city. If any line has no fee, the seller confirms the group
 * fee before the order goes ahead. A trip longer than the distance limit always needs the
 * seller to confirm; the zone fee would undercharge it.
 *
 * Carrying up stairs (an address above the ground floor with no lift) adds a fee per floor to
 * every delivery, whatever set the transport fee. When the transport fee is still to be
 * confirmed, the carrying part is known now and added when the store confirms.
 */
class CheckoutCalculator
{
    /** Read once per calculation: whether listings' own delivery fees above 0 count. */
    private bool $storesSetFee = false;

    public function __construct(private readonly DistancePricing $distance) {}

    /**
     * @param  Collection<int, CartItem>  $items  with product.seller loaded
     * @param  array<int|string, string>  $fulfillment  seller_profile_id => 'delivery'|'pickup'
     * @param  Address|null  $address  the delivery address (map pin, floor, lift), when one is chosen
     */
    public function calculate(Collection $items, ?Area $area = null, array $fulfillment = [], ?Address $address = null): array
    {
        $pin = $address?->pin();
        $carry = self::carryFee($address);
        $this->storesSetFee = Setting::storesSetDeliveryFee();
        $vatBp = Setting::vatRateBp();
        $visibleProductIds = Product::visible()->whereIn('products.id', $items->pluck('product_id')->filter()->all())->pluck('products.id')->flip();
        $zone = $area?->deliveryZone?->is_active ? $area->deliveryZone : null;

        $groups = $items->groupBy(fn (CartItem $i) => $i->product->seller_profile_id)
            ->map(function (Collection $lines, $sellerId) use ($visibleProductIds, $zone, $area, $fulfillment, $pin, $carry, $vatBp) {
                $seller = $lines->first()->product->seller;
                // One trip per store, so one distance; it stands in for the zone fee on every line.
                $trip = $this->distance->quote($seller, $pin);
                $defaultFee = $trip ? $trip['fee_santim'] : $zone?->fee_santim;
                $rows = $lines->map(fn (CartItem $i) => $this->line($i, $visibleProductIds, $defaultFee));

                $deliveryAvailable = $rows->every(fn ($r) => $r['offers_delivery']);
                $pickupAvailable = $rows->every(fn ($r) => $r['pickup_available']);
                $mode = $fulfillment[$sellerId] ?? ($deliveryAvailable ? 'delivery' : 'pickup');
                $problem = null;
                if ($mode === 'delivery' && ! $deliveryAvailable) {
                    $problem = 'This seller does not deliver every item here. Choose pickup or remove the item.';
                } elseif ($mode === 'pickup' && ! $pickupAvailable) {
                    $problem = 'Pickup is not available for every item from this seller.';
                }

                $itemsTotal = $rows->sum('line_total_santim');
                $fee = null;
                $feeConfirmed = true;
                if ($mode === 'delivery') {
                    $fees = $rows->pluck('delivery_fee_santim');
                    $feeConfirmed = $area !== null && $fees->every(fn ($f) => $f !== null);
                    $fee = $feeConfirmed ? (int) $fees->max() + $carry : null;
                }

                // VAT is added on top of items and delivery for every order. Until the store confirms a
                // delivery fee it covers the items only, and is worked out again when the fee is set.
                $vat = Order::vatOn($itemsTotal + (int) $fee, $vatBp);

                return [
                    'seller' => [
                        'id' => $seller->id, 'store_name' => $seller->store_name, 'slug' => $seller->slug, 'area' => $seller->relationLoaded('area') ? $seller->area?->name : null,
                        'vat_number' => $seller->vat_registered ? $seller->vat_number : null,
                    ],
                    'fulfillment' => $mode,
                    'delivery_available' => $deliveryAvailable,
                    'pickup_available' => $pickupAvailable,
                    'lines' => $rows->map(fn ($r) => collect($r)->except(['offers_delivery', 'pickup_available', 'delivery_fee_santim'])->all())->values()->all(),
                    'items_total_santim' => $itemsTotal,
                    'delivery_fee_santim' => $fee,
                    'delivery_fee_confirmed' => $feeConfirmed,
                    // Included in delivery_fee_santim when that is known.
                    'carry_fee_santim' => $mode === 'delivery' && $carry > 0 ? $carry : null,
                    'delivery_distance' => $mode === 'delivery' && $trip ? [
                        'km' => round($trip['meters'] / 1000, 1),
                        'meters' => $trip['meters'],
                        'estimated' => $trip['estimated'],
                        'too_far' => $trip['too_far'],
                    ] : null,
                    'vat_rate_bp' => $vatBp,
                    'vat_santim' => $vat,
                    'total_santim' => $itemsTotal + (int) $fee + $vat,
                    'problem' => $problem,
                ];
            })->values();

        $itemsTotal = $groups->sum('items_total_santim');
        $deliveryTotal = $groups->sum(fn ($g) => (int) $g['delivery_fee_santim']);
        $hasProblems = $groups->contains(fn ($g) => $g['problem'] !== null || collect($g['lines'])->contains(fn ($l) => $l['problem'] !== null));

        return [
            'groups' => $groups->all(),
            'line_count' => $items->count(),
            'items_total_santim' => $itemsTotal,
            'delivery_total_santim' => $deliveryTotal,
            'vat_total_santim' => $groups->sum('vat_santim'),
            'vat_rate_percent' => $vatBp / 100,
            'grand_total_santim' => $groups->sum('total_santim'),
            'delivery_to_confirm' => $groups->contains(fn ($g) => ! $g['delivery_fee_confirmed']),
            'area' => $area ? ['id' => $area->id, 'name' => $area->name] : null,
            'has_pin' => $pin !== null,
            'carry' => $carry > 0 ? ['floor' => (int) $address->floor, 'fee_santim' => $carry] : null,
            'has_problems' => $hasProblems,
        ];
    }

    /** Carrying fee for an address above the ground floor without a lift. */
    public static function carryFee(?Address $address): int
    {
        if (! $address || $address->has_lift !== false || (int) $address->floor < 1) {
            return 0;
        }

        return (int) $address->floor * Setting::deliveryPerFloorSantim();
    }

    /** @param  int|null  $defaultFee  from the distance, or the zone when distance does not apply */
    private function line(CartItem $item, Collection $visibleProductIds, ?int $defaultFee): array
    {
        $p = $item->product;
        $v = $item->variant_id ? $item->variant : null;
        $problem = null;
        $stock = (int) ($v ? $v->stock_quantity : $p->stock_quantity);
        $max = $p->stock_type === StockType::InStock ? $stock : (int) config('marketplace.cart.max_quantity');
        $listPrice = $v ? $v->priceSantim($p) : (int) $p->price_santim;
        // The store's sale, while it runs, lowers the price; the store pays for it.
        $unitPrice = $p->priceNowSantim($listPrice);
        if (! $visibleProductIds->has($p->id)) {
            $problem = 'This listing is no longer available.';
        } elseif ($item->variant_id && (! $v || $v->trashed())) {
            $problem = 'This option is no longer available. Remove it and pick another.';
        } elseif (! $item->variant_id && $p->relationLoaded('variants') && $p->variants->isNotEmpty()) {
            // Added before the store gave the listing options.
            $problem = 'Choose a size, color or material for this item: remove it and add it again from the listing.';
        } elseif ($p->stock_type === StockType::InStock && $stock < 1) {
            $problem = 'Out of stock.';
        } elseif ($item->quantity > $max) {
            $problem = "Only {$max} left. Lower the quantity.";
        }

        $fee = $p->ownDeliveryFeeSantim($this->storesSetFee) ?? $defaultFee;

        return [
            'id' => $item->id,
            'product' => [
                'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name,
                'image' => $p->relationLoaded('primaryImage') ? $p->primaryImage?->thumbUrl() : null,
                'stock_type' => $p->stock_type->value, 'production_days' => $p->production_days,
                // For the "will it fit" check at checkout.
                'dimensions' => ['width_cm' => $p->width_cm, 'depth_cm' => $p->depth_cm, 'height_cm' => $p->height_cm],
            ],
            'variant' => $v ? ['id' => $v->id, 'label' => $v->label()] : null,
            'name' => $p->name,
            'unit_price_santim' => $unitPrice,
            'list_unit_price_santim' => $unitPrice < $listPrice ? $listPrice : null,
            'discount_percent' => $unitPrice < $listPrice ? $p->activeDiscountPercent() : null,
            'quantity' => $item->quantity,
            'max_quantity' => max(0, min($max, (int) config('marketplace.cart.max_quantity'))),
            'line_total_santim' => $unitPrice * $item->quantity,
            'problem' => $problem,
            'offers_delivery' => $p->offers_delivery,
            'pickup_available' => $p->pickup_available,
            'delivery_fee_santim' => $p->offers_delivery ? $fee : null,
        ];
    }
}
