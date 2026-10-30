<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Area;
use App\Models\DeliveryZone;
use App\Models\SellerProfile;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample sellers and listings for local development and demos.
 * Refuses to run in production. The listings and photos are in DemoCatalog.
 */
class DemoSeeder extends Seeder
{
    private const SELLERS = [
        ['Merkato Woodworks', 'workshop', 'addis-ketema'],
        ['Bole Home Interiors', 'shop', 'bole'],
        ['Entoto Craft Furniture', 'manufacturer', 'gulele'],
        ['Kazanchis Office Supply', 'shop', 'kirkos'],
    ];

    /** Rough map pins per sub-city for demo stores, so delivery by distance can be tried. */
    private const PINS = [
        'addis-ketema' => [9.0336, 38.7383],
        'bole' => [8.9963, 38.7889],
        'gulele' => [9.0625, 38.7356],
        'kirkos' => [9.0137, 38.7656],
    ];

    // [zone name, demo fee birr, min days, max days, area slugs]
    private const ZONES = [
        ['Central Addis (demo)', 400, 1, 2, ['kirkos', 'arada', 'lideta', 'addis-ketema']],
        ['Inner ring (demo)', 600, 1, 3, ['bole', 'yeka', 'gulele', 'kolfe-keranio', 'nifas-silk-lafto']],
        ['Outer sub-cities (demo)', 900, 2, 4, ['akaky-kaliti', 'lemi-kura']],
    ];

    public function run(): void
    {
        if (! AppServiceProvider::isTestSite()) {
            throw new RuntimeException('DemoSeeder only runs when APP_ENV is local, testing or staging.');
        }

        $this->call([LocationSeeder::class, CategorySeeder::class]);

        $password = env('DEMO_USER_PASSWORD') ?: Str::password(16);

        $sellers = collect(self::SELLERS)->map(function (array $row, int $i) use ($password) {
            [$store, $type, $areaSlug] = $row;
            $email = 'seller'.($i + 1).'@demo.local';

            $user = User::firstOrNew(['email' => $email]);
            if (! $user->exists) {
                $user->fill(['name' => $store.' Owner', 'password' => $password, 'phone' => '+25191100000'.$i]);
                $user->role = Role::Seller;
                $user->save();
            }

            $area = Area::where('slug', $areaSlug)->firstOrFail();

            $profile = SellerProfile::firstOrCreate(['user_id' => $user->id], [
                'store_name' => $store,
                'slug' => Slug::unique(SellerProfile::class, $store),
                'description' => "{$store} builds and sells quality furniture in Addis Ababa.",
                'business_type' => $type,
                'phone' => '+25191100000'.$i,
                'city_id' => $area->city_id,
                'area_id' => $area->id,
                'offers_delivery' => true,
                'status' => 'approved',
                'reviewed_at' => now(),
            ]);
            // Older demo installs get a pin too; a pin the store set itself is kept.
            if ($profile->latitude === null && isset(self::PINS[$areaSlug])) {
                $profile->forceFill(['latitude' => self::PINS[$areaSlug][0], 'longitude' => self::PINS[$areaSlug][1]])->save();
            }

            return $profile;
        });

        $customer = User::firstOrNew(['email' => 'customer@demo.local']);
        if (! $customer->exists) {
            $customer->fill(['name' => 'Demo Customer', 'password' => $password])->save();
        }

        // The listings and their photos; the same call brings older demo data up to date on each start.
        app(DemoCatalog::class)->sync($sellers->values());

        // Example zones so delivery estimates show something locally. The fees are made up;
        // real fees are set by an administrator under Admin > Delivery.
        foreach (self::ZONES as [$zoneName, $feeBirr, $min, $max, $areaSlugs]) {
            $zone = DeliveryZone::firstOrCreate(['name' => $zoneName], [
                'fee_santim' => $feeBirr * 100, 'min_days' => $min, 'max_days' => $max, 'is_active' => true,
            ]);
            Area::whereIn('slug', $areaSlugs)->whereNull('delivery_zone_id')->update(['delivery_zone_id' => $zone->id]);
        }

        if ($this->command) {
            $this->command->info('Demo users: seller1..4@demo.local and customer@demo.local');
            $this->command->info(env('DEMO_USER_PASSWORD')
                ? 'Password: the value of DEMO_USER_PASSWORD.'
                : "Generated password (shown once): {$password}");
        }
    }
}
