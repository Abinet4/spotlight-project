<?php

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * A map pin (latitude, longitude). Both or neither; inside the service area so a swapped pair or
 * a pin dropped in the wrong country is caught before it prices a delivery.
 */
class MapPin
{
    /** Validation rules for the `latitude` and `longitude` request fields. */
    public static function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    /** Field names people read in error messages. */
    public static function attributes(): array
    {
        return ['latitude' => 'map pin', 'longitude' => 'map pin'];
    }

    /** Validator hook: reject pins outside the service area. */
    public static function checkArea(Validator $validator): void
    {
        $data = $validator->getData();
        if ($validator->errors()->hasAny(['latitude', 'longitude']) || ! is_numeric($data['latitude'] ?? null) || ! is_numeric($data['longitude'] ?? null)) {
            return;
        }
        [$lat, $lng] = config('marketplace.distance.service_center');
        $km = self::straightMeters((float) $data['latitude'], (float) $data['longitude'], $lat, $lng) / 1000;
        if ($km > config('marketplace.distance.service_radius_km')) {
            $validator->errors()->add('latitude', 'This pin is outside the area we deliver to. Move it to the right place.');
        }
    }

    /** Great-circle distance in metres. */
    public static function straightMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($a))));
    }

    /** Six decimals (about 10 cm) as stored, or null when either half is missing. */
    public static function from(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }

        return [round((float) $lat, 6), round((float) $lng, 6)];
    }
}
