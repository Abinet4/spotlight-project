<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Services\ImageVariants;
use App\Services\InventoryService;
use App\Support\Slug;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The demo listings and their photos (database/seeders/photos). Photos of the same piece go on one
 * listing; a piece shown in two colors gets one option per color.
 *
 * sync() is safe to run on every start: it adds listings that never existed, puts the photos on
 * listings whose photos are missing or out of date, and takes down older demo listings that only
 * had generated pictures. A listing an admin deleted stays deleted, and photos a store uploaded
 * are never touched.
 */
final class DemoCatalog
{
    public const PHOTO_DIR = __DIR__.'/photos';

    /** File names of pictures the demo created (photos and the older drawn ones), never a store's own. */
    private const GENERATED = '#^products/\d+/demo-[A-Za-z0-9-]+\.jpg$#';

    /** The current demo photos; other generated pictures are the older plain (demo-*) or drawn (demo-art-*) ones. */
    private const PHOTO_PREFIX = 'demo-photo-';

    /**
     * Row i belongs to demo store i % 4. photos: file name in PHOTO_DIR => words for the alt text.
     * options: [color, units] each; the listing's stock is then the sum.
     */
    private const LISTINGS = [
        ['cat' => 'beds-mattresses', 'name' => 'Fluted Upholstered Bed', 'price' => 64000, 'material' => 'Velvet, hardwood frame', 'color' => 'Grey or beige', 'size' => [175, 220, 125],
            'options' => [['Grey', 2], ['Beige', 3]], 'photos' => ['fluted-bed-grey' => 'in grey', 'fluted-bed-beige' => 'in beige'],
            'about' => 'Upholstered bed with a fluted headboard and footboard. Takes a 160 x 200 cm mattress (not included). Choose grey or beige.'],
        ['cat' => 'sofas-living-room', 'name' => 'Glass-Top Walnut Coffee Table', 'price' => 21500, 'material' => 'Walnut, tempered glass', 'color' => 'Walnut', 'stock' => 3, 'size' => [120, 60, 45],
            'photos' => ['walnut-glass-coffee-table' => null],
            'about' => 'Curved walnut base under a clear tempered glass top.'],
        ['cat' => 'dining', 'name' => 'Six-Seat Wooden Dining Set', 'price' => 58000, 'material' => 'Hardwood, fabric', 'color' => 'Espresso and grey', 'stock' => 2, 'size' => [180, 90, 76],
            'photos' => ['six-seat-dining-set' => null],
            'about' => 'Dark wood dining table with six chairs upholstered in grey fabric.'],
        ['cat' => 'office', 'name' => 'Desk with Five Drawers', 'price' => 14500, 'material' => 'MDF', 'color' => 'White and black', 'stock' => 6, 'size' => [120, 60, 75],
            'photos' => ['desk-with-drawers' => null],
            'about' => 'Work desk with a built-in stack of five drawers on the right.'],
        ['cat' => 'wardrobes-storage', 'name' => 'Four-Door Wardrobe with Drawers', 'price' => 46000, 'material' => 'MDF', 'color' => 'Dark grey', 'stock' => 2, 'size' => [200, 58, 210],
            'photos' => ['four-door-wardrobe-drawers' => null],
            'about' => 'Four hanging and shelf sections with three wide drawers below.'],
        ['cat' => 'sofas-living-room', 'name' => 'Velvet Shell Armchairs with Pouf (pair)', 'price' => 36000, 'material' => 'Velvet, wood legs', 'color' => 'Grey', 'stock' => 2, 'size' => [78, 75, 85],
            'photos' => ['shell-armchairs' => null],
            'about' => 'Two shell-back velvet armchairs and a matching round pouf. Size is for one chair.'],
        ['cat' => 'beds-mattresses', 'name' => 'Black Velvet Bed with Gold Trim', 'price' => 82000, 'material' => 'Velvet, steel trim', 'color' => 'Black', 'stock' => 2, 'size' => [175, 220, 140],
            'photos' => ['black-velvet-bed' => null],
            'about' => 'Channel-tufted black velvet bed framed in gold-colored steel. Takes a 160 x 200 cm mattress.'],
        ['cat' => 'wardrobes-storage', 'name' => 'Two-Drawer Bedside Table', 'price' => 6500, 'material' => 'MDF, metal handles', 'color' => 'White', 'stock' => 10, 'size' => [48, 40, 55],
            'photos' => ['two-drawer-nightstand' => null],
            'about' => 'Bedside table with two drawers and gold-colored handles.'],
        ['cat' => 'sofas-living-room', 'name' => 'Marble-Look Coffee Table', 'price' => 16500, 'material' => 'Sintered stone top, MDF', 'color' => 'White and grey', 'stock' => 4, 'size' => [120, 60, 42],
            'photos' => ['marble-look-coffee-table' => null],
            'about' => 'Coffee table with a marble-look top, an open shelf and a drawer.'],
        ['cat' => 'dining', 'name' => 'Oval Dining Table with Six Chairs', 'price' => 64000, 'material' => 'Walnut veneer, boucle fabric', 'color' => 'Walnut and cream', 'stock' => 2, 'size' => [200, 100, 75],
            'photos' => ['oval-dining-set' => null],
            'about' => 'Oval walnut table with six curved chairs in cream boucle.'],
        ['cat' => 'beds-mattresses', 'name' => 'Winged Channel Bed', 'price' => 68000, 'material' => 'Velvet, hardwood frame', 'color' => 'Charcoal', 'stock' => 3, 'size' => [180, 220, 140],
            'photos' => ['winged-channel-bed' => null],
            'about' => 'Tall winged headboard and a matching footboard, both channel-tufted.'],
        ['cat' => 'wardrobes-storage', 'name' => 'Shoe Bench with Seat', 'price' => 7800, 'material' => 'Wood, faux leather', 'color' => 'Walnut and black', 'stock' => 8, 'size' => [100, 35, 50],
            'photos' => ['shoe-bench' => null],
            'about' => 'Padded seat over two open shoe shelves, for the entrance.'],
        ['cat' => 'wardrobes-storage', 'name' => 'Sage Green Wardrobe', 'price' => 42000, 'material' => 'MDF, brass handles', 'color' => 'Sage green', 'stock' => 2, 'size' => [180, 58, 200],
            'photos' => ['sage-green-wardrobe' => null],
            'about' => 'Four doors and two drawers in sage green with brass-colored handles.'],
        ['cat' => 'sofas-living-room', 'name' => 'Gloss Coffee Table with Drawer', 'price' => 12500, 'material' => 'High-gloss MDF', 'color' => 'White and black', 'stock' => 5, 'size' => [110, 55, 40],
            'photos' => ['gloss-coffee-table' => null],
            'about' => 'High-gloss coffee table with a black top, a drawer and an open shelf.'],
        ['cat' => 'beds-mattresses', 'name' => 'Channel Bed with Storage Ottoman', 'price' => 79000, 'material' => 'Fabric, hardwood frame', 'color' => 'Grey', 'stock' => 2, 'size' => [175, 220, 150],
            'photos' => ['channel-bed-with-ottoman' => null],
            'about' => 'Channel-tufted bed with a matching storage ottoman for the foot of the bed.'],
        ['cat' => 'dining', 'name' => 'Round Dining Table with Four Chairs', 'price' => 46000, 'material' => 'Oak, boucle fabric', 'color' => 'Oak and cream', 'stock' => 3, 'size' => [110, 110, 75],
            'photos' => ['round-dining-set' => null],
            'about' => 'Round oak table with four curved chairs in cream boucle.'],
        ['cat' => 'beds-mattresses', 'name' => 'Gold-Trim Tufted Bed', 'price' => 72000, 'material' => 'Fabric, steel trim', 'color' => 'Charcoal', 'stock' => 2, 'size' => [170, 215, 120],
            'photos' => ['gold-trim-tufted-bed' => null],
            'about' => 'Square-tufted headboard and footboard outlined in gold-colored trim.'],
        ['cat' => 'wardrobes-storage', 'name' => 'Four-Door White Wardrobe', 'price' => 38000, 'material' => 'MDF', 'color' => 'White', 'stock' => 3, 'size' => [160, 55, 200],
            'photos' => ['four-door-wardrobe-white' => null],
            'about' => 'White four-door wardrobe with two drawers and black handles.'],
        ['cat' => 'beds-mattresses', 'name' => 'Leather Panel Bed with Bench', 'price' => 95000, 'material' => 'Faux leather, steel legs', 'color' => 'Dark grey', 'stock' => 1, 'size' => [185, 220, 120],
            'photos' => ['panel-bed-with-bench' => null],
            'about' => 'Panelled faux leather bed with gold accents and a matching storage bench.'],
        ['cat' => 'wardrobes-storage', 'name' => 'Dressing Table with Mirror', 'price' => 18500, 'material' => 'MDF, mirror', 'color' => 'Grey', 'stock' => 4, 'size' => [100, 40, 150],
            'photos' => ['dressing-table' => null],
            'about' => 'Dressing table with three drawers, a knee space and a wall mirror.'],
        ['cat' => 'beds-mattresses', 'name' => 'Five-Piece Grey Bedroom Set', 'price' => 165000, 'material' => 'MDF', 'color' => 'Grey', 'stock' => 1, 'size' => [175, 215, 110],
            'photos' => ['grey-bedroom-set' => null],
            'about' => 'Bed, four-door wardrobe, two bedside tables and a chest of drawers. Size is for the bed.'],
        ['cat' => 'sofas-living-room', 'name' => 'Two-Tone Coffee Table with Shelf', 'price' => 13500, 'material' => 'Tempered glass, MDF', 'color' => 'Black and cherry', 'stock' => 4, 'size' => [110, 60, 45],
            'photos' => ['two-tone-coffee-table' => null],
            'about' => 'Black glass top over a cherry-colored base with a drawer and an open shelf.'],
        ['cat' => 'beds-mattresses', 'name' => 'Low Wingback Bed', 'price' => 54000, 'material' => 'Linen-look fabric', 'color' => 'Light grey', 'stock' => 3, 'size' => [170, 215, 110],
            'photos' => ['low-wingback-bed' => null],
            'about' => 'Soft wingback headboard with horizontal channels on a low upholstered base.'],
        ['cat' => 'wardrobes-storage', 'name' => 'Four-Door Grey Wardrobe', 'price' => 36000, 'material' => 'MDF', 'color' => 'Grey', 'stock' => 3, 'size' => [180, 60, 210],
            'photos' => ['four-door-wardrobe-grey' => null],
            'about' => 'Plain grey four-door wardrobe with long black handles.'],
        ['cat' => 'wardrobes-storage', 'name' => 'Three-Door Wardrobe with Drawers', 'price' => 34000, 'material' => 'MDF', 'color' => 'Anthracite', 'stock' => 4, 'size' => [150, 55, 200],
            'photos' => ['three-door-wardrobe' => null],
            'about' => 'Three doors with three drawers on the right-hand side.'],
    ];

    public function __construct(
        private readonly ImageVariants $variants,
        private readonly InventoryService $inventory,
    ) {}

    /** Whether a picture was made by the demo (a photo from here or an older drawing), not uploaded by a store. */
    public static function generated(string $path): bool
    {
        return preg_match(self::GENERATED, $path) === 1;
    }

    /** An older plain or drawn demo picture, from before the demo had photos. */
    private static function older(string $path): bool
    {
        return self::generated($path) && ! str_starts_with(basename($path), self::PHOTO_PREFIX);
    }

    /**
     * @param  Collection<int, SellerProfile|null>  $stores  the demo stores in order (seller1 first); null when one is gone
     * @return array{added: int, photographed: int, retired: int}
     */
    public function sync(Collection $stores): array
    {
        $done = ['added' => 0, 'photographed' => 0, 'retired' => 0];
        $names = [];

        foreach (self::LISTINGS as $i => $row) {
            $store = $stores[$i % 4] ?? null;
            if (! $store) {
                continue;
            }
            $names[$store->id][] = $row['name'];
            $product = Product::withTrashed()->where('seller_profile_id', $store->id)->where('name', $row['name'])->first();
            if (! $product) {
                $product = $this->create($row, $store, $i);
                $done['added']++;
            }
            if (! $product->trashed() && $this->photograph($product, $row)) {
                $done['photographed']++;
            }
        }

        $demo = Product::whereIn('seller_profile_id', $stores->filter()->pluck('id'))->with('images')->get();

        // A listing a store gave its own photos keeps them, but older generated pictures beside them go.
        foreach ($demo as $product) {
            $older = $product->images->filter(fn (ProductImage $img) => self::older($img->path));
            if ($older->isNotEmpty() && $product->images->contains(fn (ProductImage $img) => ! self::generated($img->path))) {
                foreach ($older as $image) {
                    $image->deleteFiles();
                    $image->delete();
                }
                $done['photographed']++;
            }
        }

        // Older demo listings that are not in this catalog and only have generated pictures leave the
        // shop. They are archived (orders keep pointing at them) and lose their pictures, so a listing
        // an admin publishes again later is not taken down on the next start.
        $old = $demo
            ->reject(fn (Product $p) => in_array($p->name, $names[$p->seller_profile_id] ?? [], true))
            ->filter(fn (Product $p) => $p->images->isNotEmpty() && $p->images->every(fn (ProductImage $img) => self::generated($img->path)));
        foreach ($old as $product) {
            $product->status = ProductStatus::Archived;
            $product->save();
            foreach ($product->images as $image) {
                $image->deleteFiles();
                $image->delete();
            }
            $done['retired']++;
        }

        return $done;
    }

    private function create(array $row, SellerProfile $store, int $i): Product
    {
        [$w, $d, $h] = $row['size'];
        $product = new Product([
            'category_id' => Category::where('slug', $row['cat'])->value('id'),
            'name' => $row['name'],
            'description' => $row['about'],
            'price_santim' => $row['price'] * 100,
            'price_negotiable' => $i % 3 === 0,
            'condition' => 'new',
            'stock_type' => 'in_stock',
            'stock_quantity' => 0,
            'width_cm' => $w, 'depth_cm' => $d, 'height_cm' => $h,
            'material' => $row['material'],
            'color' => $row['color'],
            'area_id' => $store->area_id,
            'offers_delivery' => true,
            'pickup_available' => $i % 2 === 0,
            'delivery_fee_santim' => $i % 4 === 0 ? null : (300 + ($i % 3) * 200) * 100,
            'delivery_notes' => 'Delivery within Addis Ababa in 2 to 5 days after confirmation.',
        ]);
        $product->seller_profile_id = $store->id;
        $product->slug = Slug::unique(Product::class, $row['name']);
        $product->status = ProductStatus::Published;
        $product->published_at = now()->subDays(count(self::LISTINGS) - $i);
        $product->is_featured = $i % 5 === 0;
        $product->save();

        // Through the stock service, so the demo store's stock history starts at its stock.
        if (isset($row['options'])) {
            foreach ($row['options'] as $k => [$color, $units]) {
                $option = new ProductVariant;
                $option->product_id = $product->id;
                $option->seller_profile_id = $store->id;
                $option->color = $color;
                $option->sort_order = $k + 1;
                $option->stock_quantity = 0;
                $option->save();
                if ($k === 0) {
                    $this->inventory->moveToFirstVariant($product, $option, null);
                }
                $this->inventory->setTotal($product, $option, $units, null, 'opening');
            }
        } else {
            $this->inventory->setTotal($product, null, $row['stock'], null, 'opening');
        }

        return $product;
    }

    /** Puts the row's photos on the listing unless they are already there, files and all. */
    private function photograph(Product $product, array $row): bool
    {
        $images = $product->images()->orderBy('sort_order')->get();
        if ($images->contains(fn (ProductImage $img) => ! self::generated($img->path))) {
            return false; // the store's own photos win
        }

        // The file's hash is in the name, so a changed photo replaces the old one on the next run.
        $wanted = collect($row['photos'])->map(function (?string $words, string $file) use ($product, $row) {
            $source = self::PHOTO_DIR."/{$file}.jpg";

            return [
                'source' => $source,
                'path' => "products/{$product->id}/".self::PHOTO_PREFIX."{$file}-".substr(sha1_file($source), 0, 8).'.jpg',
                'alt' => trim($row['name'].' '.$words),
            ];
        })->values();

        // The same photos are there: only make smaller copies that are missing (a photo without them is
        // not shown). When copies cannot be made, the rows stay as they are rather than being redone on every start.
        if ($images->pluck('path')->all() === $wanted->pluck('path')->all()
            && $images->every(fn (ProductImage $img) => Storage::disk(ProductImage::ORIGINALS_DISK)->exists($img->path))) {
            $fixed = false;
            foreach ($images as $image) {
                $copies = $image->copies();
                if (count($copies) < 2 || ! collect($copies)->every(fn (string $copy) => Storage::disk('public')->exists($copy))) {
                    $fixed = $this->variants->refresh($image) || $fixed;
                }
            }

            return $fixed;
        }

        foreach ($images as $image) {
            $image->deleteFiles();
            $image->delete();
        }
        foreach ($wanted as $n => $photo) {
            Storage::disk(ProductImage::ORIGINALS_DISK)->put($photo['path'], file_get_contents($photo['source']));
            $product->images()->create(['path' => $photo['path'], ...$this->variants->make($photo['path']), 'alt' => $photo['alt'], 'sort_order' => $n]);
        }

        return true;
    }
}
