<?php

namespace App\Services;

use App\Enums\StockType;
use App\Models\Address;
use App\Models\Area;
use App\Models\Product;
use App\Models\Setting;

/**
 * Works out what a customer would pay and wait for delivery of a listing to an area.
 * The listing's own fee wins (free delivery always; a charged fee only while admins allow it); otherwise the distance from the store's pin to the customer's
 * (when both are known); otherwise the area's delivery zone; otherwise the seller quotes the
 * fee after confirming the address. Same order as CheckoutCalculator.
 */
class DeliveryEstimator
{
    public function __construct(private readonly DistancePricing $distance) {}

    /**
     * @param  Address|null  $address  the customer's saved address (pin, floor), when known
     * @return array{
     *   delivery_available: bool, pickup_available: bool,
     *   fee_santim: int|null, fee_source: 'listing'|'distance'|'zone'|'seller_quote'|null,
     *   distance_km: float|null, too_far: bool, carry_fee_santim: int|null,
     *   min_days: int|null, max_days: int|null, zone: string|null, area: string
     * }
     */
    public function estimate(Product $product, Area $area, ?Address $address = null): array
    {
        $pin = $address?->pin();
        $carry = CheckoutCalculator::carryFee($address);
        $zone = $area->deliveryZone?->is_active ? $area->deliveryZone : null;
        $production = $product->stock_type === StockType::MadeToOrder ? (int) $product->production_days : 0;

        $result = [
            'delivery_available' => $product->offers_delivery,
            'pickup_available' => $product->pickup_available,
            'fee_santim' => null,
            'fee_source' => null,
            'distance_km' => null,
            'too_far' => false,
            'carry_fee_santim' => null,
            'min_days' => null,
            'max_days' => null,
            'zone' => $zone?->name,
            'area' => $area->name,
        ];

        if (! $product->offers_delivery) {
            return $result;
        }

        $own = $product->ownDeliveryFeeSantim(Setting::storesSetDeliveryFee());
        $trip = $own === null ? $this->distance->quote($product->seller, $pin) : null;
        if ($own !== null) {
            $result['fee_santim'] = $own;
            $result['fee_source'] = 'listing';
        } elseif ($trip) {
            $result['distance_km'] = round($trip['meters'] / 1000, 1);
            $result['too_far'] = $trip['too_far'];
            $result['fee_santim'] = $trip['fee_santim'];
            $result['fee_source'] = $trip['too_far'] ? 'seller_quote' : 'distance';
        } elseif ($zone) {
            $result['fee_santim'] = $zone->fee_santim;
            $result['fee_source'] = 'zone';
        } else {
            $result['fee_source'] = 'seller_quote';
        }

        // Carrying up stairs is part of the fee, as at checkout.
        if ($carry > 0) {
            $result['carry_fee_santim'] = $carry;
            if ($result['fee_santim'] !== null) {
                $result['fee_santim'] += $carry;
            }
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
