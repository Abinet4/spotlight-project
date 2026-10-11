<?php

namespace App\Services;

use App\Enums\StockType;
use App\Models\Area;
use App\Models\Product;

/**
 * Works out what a customer would pay and wait for delivery of a listing to an area.
 * The listing's own fee wins; otherwise the area's delivery zone supplies the default;
 * otherwise the seller must quote the fee after confirming the address.
 */
class DeliveryEstimator
{
    /**
     * @return array{
     *   delivery_available: bool, pickup_available: bool,
     *   fee_santim: int|null, fee_source: 'listing'|'zone'|'seller_quote'|null,
     *   min_days: int|null, max_days: int|null, zone: string|null, area: string
     * }
     */
    public function estimate(Product $product, Area $area): array
    {
        $zone = $area->deliveryZone?->is_active ? $area->deliveryZone : null;
        $production = $product->stock_type === StockType::MadeToOrder ? (int) $product->production_days : 0;

        $result = [
            'delivery_available' => $product->offers_delivery,
            'pickup_available' => $product->pickup_available,
            'fee_santim' => null,
            'fee_source' => null,
            'min_days' => null,
            'max_days' => null,
            'zone' => $zone?->name,
            'area' => $area->name,
        ];

        if (! $product->offers_delivery) {
            return $result;
        }

        if ($product->delivery_fee_santim !== null) {
            $result['fee_santim'] = $product->delivery_fee_santim;
            $result['fee_source'] = 'listing';
        } elseif ($zone) {
            $result['fee_santim'] = $zone->fee_santim;
            $result['fee_source'] = 'zone';
        } else {
            $result['fee_source'] = 'seller_quote';
        }

        if ($zone) {
            $result['min_days'] = $zone->min_days + $production;
            $result['max_days'] = $zone->max_days + $production;
        } elseif ($production > 0) {
            $result['min_days'] = $production;
        }

        return $result;
    }
}
