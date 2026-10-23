<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SellerProfile;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\ImageVariants;
use App\Services\InventoryService;
use App\Support\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample sellers and listings for local development and demos.
 * Refuses to run in production. Images are generated placeholders, not real photos.
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

    // [category slug, name, price birr, material, color, condition, units in stock, w, d, h]
    private const PRODUCTS = [
        ['sofas-living-room', 'Three-Seater Fabric Sofa', 38500, 'Fabric, Eucalyptus', 'Grey', 'new', 4, 210, 90, 85],
        ['sofas-living-room', 'L-Shaped Sectional Sofa', 72000, 'Velvet, Hardwood', 'Emerald', 'new', 2, 280, 180, 88],
        ['sofas-living-room', 'Mahogany Coffee Table', 14500, 'Mahogany', 'Dark Brown', 'new', 6, 120, 60, 45],
        ['sofas-living-room', 'Used Leather Armchair', 9800, 'Leather', 'Tan', 'used', 1, 85, 90, 95],
        ['beds-mattresses', 'Queen Bed Frame with Headboard', 42000, 'Wanza Wood', 'Walnut', 'new', 3, 165, 210, 110],
        ['beds-mattresses', 'Orthopedic Spring Mattress 160x200', 26000, 'Foam, Springs', 'White', 'new', 10, 160, 200, 28],
        ['beds-mattresses', 'Kids Bunk Bed', 31000, 'Pine', 'Natural', 'new', 3, 100, 200, 160],
        ['wardrobes-storage', 'Four-Door Wardrobe with Mirror', 54000, 'MDF, Mirror', 'White', 'new', 2, 180, 60, 210],
        ['wardrobes-storage', 'Six-Drawer Dresser', 19500, 'Zigba Wood', 'Honey', 'new', 5, 120, 45, 90],
        ['dining', 'Six-Seat Dining Set', 48000, 'Hardwood, Fabric', 'Espresso', 'new', 2, 180, 90, 76],
        ['dining', 'Round Four-Seat Dining Table', 22500, 'Oak Veneer', 'Light Oak', 'new', 4, 110, 110, 75],
        ['dining', 'Upholstered Dining Chair (each)', 4200, 'Fabric, Steel', 'Beige', 'new', 24, 48, 55, 92],
        ['office', 'Executive Office Desk', 36000, 'Mahogany Veneer', 'Mahogany', 'new', 3, 180, 85, 76],
        ['office', 'Ergonomic Mesh Office Chair', 12800, 'Mesh, Nylon', 'Black', 'new', 15, 65, 65, 120],
        ['office', 'Used Steel Filing Cabinet', 5200, 'Steel', 'Grey', 'used', 3, 47, 62, 132],
        ['cabinets-shelves', 'Five-Tier Bookshelf', 11500, 'Pine', 'White', 'new', 7, 80, 30, 180],
        ['cabinets-shelves', 'Glass Display Cabinet', 27500, 'Wood, Glass', 'Black', 'new', 2, 90, 40, 190],
        ['cabinets-shelves', 'Kitchen Wall Cabinet Set', 46000, 'MDF, Laminate', 'Cream', 'new', 2, 240, 35, 70],
        ['wardrobes-storage', 'Two-Door Pine Wardrobe', 28000, 'Pine', 'Natural', 'new', 4, 100, 55, 190],
        ['dining', 'Carved Traditional Bench', 18000, 'Wanza Wood', 'Natural', 'new', 3, 160, 45, 85],
        ['other-furniture', 'Outdoor Patio Set', 24500, 'Rattan, Steel', 'Brown', 'new', 3, 120, 120, 75],
        ['other-furniture', 'Kids Study Desk and Chair', 8900, 'MDF', 'Blue', 'new', 6, 90, 50, 70],
    ];

    // [zone name, demo fee birr, min days, max days, area slugs]
    private const ZONES = [
        ['Central Addis (demo)', 400, 1, 2, ['kirkos', 'arada', 'lideta', 'addis-ketema']],
        ['Inner ring (demo)', 600, 1, 3, ['bole', 'yeka', 'gulele', 'kolfe-keranio', 'nifas-silk-lafto']],
        ['Outer sub-cities (demo)', 900, 2, 4, ['akaky-kaliti', 'lemi-kura']],
    ];

    /** File name prefix of drawn demo pictures; older demo installs used plain "demo-". */
    public const ART_PREFIX = 'demo-art-';

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

        foreach (self::PRODUCTS as $i => $row) {
            [$cat, $name, $price, $material, $color, $condition, $stock, $w, $d, $h] = $row;
            $seller = $sellers[$i % $sellers->count()];

            if (Product::where('seller_profile_id', $seller->id)->where('name', $name)->exists()) {
                continue;
            }

            $product = new Product([
                'category_id' => Category::where('slug', $cat)->value('id'),
                'name' => $name,
                'description' => "{$name} from {$seller->store_name}. Solid construction, finished by hand in Addis Ababa and ready to deliver.",
                'price_santim' => $price * 100,
                'price_negotiable' => $i % 3 === 0,
                'condition' => $condition,
                'stock_type' => 'in_stock',
                'stock_quantity' => 0,
                'width_cm' => $w, 'depth_cm' => $d, 'height_cm' => $h,
                'material' => $material,
                'color' => $color,
                'area_id' => $seller->area_id,
                'offers_delivery' => true,
                'pickup_available' => $i % 2 === 0,
                'delivery_fee_santim' => $i % 4 === 0 ? null : (300 + ($i % 3) * 200) * 100,
                'delivery_notes' => 'Delivery within Addis Ababa in 2 to 5 days after confirmation.',
            ]);
            $product->seller_profile_id = $seller->id;
            $product->slug = Slug::unique(Product::class, $name);
            $product->status = 'published';
            $product->published_at = now()->subDays(count(self::PRODUCTS) - $i);
            $product->is_featured = $i % 5 === 0;
            $product->save();
            // Through the stock service, so the demo store's stock history starts at its stock.
            app(InventoryService::class)->setTotal($product, null, $stock, null, 'opening');

            $path = "products/{$product->id}/".self::ART_PREFIX.Str::random(8).'.jpg';
            Storage::disk(ProductImage::ORIGINALS_DISK)->put($path, (new DemoFurnitureArt)->jpeg($name, $color, $i));
            $product->images()->create(['path' => $path, ...app(ImageVariants::class)->make($path), 'alt' => $name, 'sort_order' => 0]);
        }

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
