<?php

namespace App\Services;

use App\Models\SellerProfile;
use App\Models\Setting;

/**
 * Delivery fee from the distance between a store's pin and the customer's:
 * base fee + price per km, rounded up to whole tens of birr. Returns null when distance
 * pricing does not apply (turned off, or a pin is missing) so the zone fee takes over.
 */
class DistancePricing
{
    public function __construct(private readonly DistanceService $distance) {}

    public static function enabled(): bool
    {
        return Setting::deliveryPerKmSantim() > 0;
    }

    /**
     * @param  array{0: float, 1: float}|null  $pin  the customer's
     * @return array{fee_santim: int|null, meters: int, estimated: bool, too_far: bool}|null
     */
    public function quote(SellerProfile $seller, ?array $pin): ?array
    {
        $from = $seller->pin();
        if (! self::enabled() || ! $from || ! $pin) {
            return null;
        }
        $d = $this->distance->between($from, $pin);
        $maxKm = Setting::deliveryMaxKm();
        $tooFar = $maxKm > 0 && $d['meters'] > $maxKm * 1000;

        return [
            'fee_santim' => $tooFar ? null : self::fee($d['meters']),
            'meters' => $d['meters'],
            'estimated' => $d['estimated'],
            'too_far' => $tooFar,
        ];
    }

    public static function fee(int $meters): int
    {
        $raw = Setting::deliveryBaseFeeSantim() + intdiv(Setting::deliveryPerKmSantim() * $meters + 999, 1000);
        $step = max(1, (int) config('marketplace.distance.round_to_birr')) * 100;

        return (int) (ceil($raw / $step) * $step);
    }
}
