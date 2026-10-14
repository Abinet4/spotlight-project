<?php

namespace App\Services;

use App\Support\MapPin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Driving distance between two pins. Asks Google's Routes API when a server key is set and
 * remembers the answer, so the cart, the checkout preview and the order see the same number
 * and each trip is paid for once. Without a key, or when Google fails, it estimates from the
 * straight line times a road factor (and remembers that only briefly, to try Google again).
 */
class DistanceService
{
    /**
     * @param  array{0: float, 1: float}  $from
     * @param  array{0: float, 1: float}  $to
     * @return array{meters: int, estimated: bool}
     */
    public function between(array $from, array $to): array
    {
        // Pins are rounded to about 10 m: nudging a pin by a hair is the same trip, not a new paid lookup.
        $from = [round((float) $from[0], 4), round((float) $from[1], 4)];
        $to = [round((float) $to[0], 4), round((float) $to[1], 4)];
        $key = 'distance:'.implode(',', array_map(fn ($v) => number_format($v, 4, '.', ''), [...$from, ...$to]));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $road = $this->roadMeters($from, $to);
        if ($road !== null) {
            $result = ['meters' => $road, 'estimated' => false];
            Cache::put($key, $result, now()->addDays(30));

            return $result;
        }

        $straight = MapPin::straightMeters($from[0], $from[1], $to[0], $to[1]);
        $result = ['meters' => (int) round($straight * (float) config('marketplace.distance.road_factor')), 'estimated' => true];
        // Brief, so a working key is tried again soon; long enough that preview and order agree.
        Cache::put($key, $result, now()->addMinutes(config('services.google_maps.server_key') ? 10 : 60 * 24));

        return $result;
    }

    private function roadMeters(array $from, array $to): ?int
    {
        $key = config('services.google_maps.server_key');
        if (! $key) {
            return null;
        }
        if (! RateLimiter::attempt('google-routes', (int) config('services.google_maps.routes_per_hour'), fn () => true, 3600)) {
            Log::warning('Google Routes hourly limit reached; estimating distances from the straight line.');

            return null;
        }
        $point = fn (array $p) => ['location' => ['latLng' => ['latitude' => $p[0], 'longitude' => $p[1]]]];
        try {
            $response = Http::timeout((int) config('services.google_maps.timeout'))
                ->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'routes.distanceMeters'])
                ->post(config('services.google_maps.routes_url'), [
                    'origin' => $point($from),
                    'destination' => $point($to),
                    'travelMode' => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_UNAWARE',
                ]);
            $meters = $response->successful() ? $response->json('routes.0.distanceMeters') : null;
            if (is_int($meters) && $meters >= 0) {
                return $meters;
            }
            // Same place: Google returns a route that leaves distanceMeters out.
            $route = $response->successful() ? $response->json('routes.0') : null;
            if (is_array($route) && ! array_key_exists('distanceMeters', $route)) {
                return 0;
            }
            report(new \RuntimeException('Google Routes gave no distance: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300)));
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }
}
