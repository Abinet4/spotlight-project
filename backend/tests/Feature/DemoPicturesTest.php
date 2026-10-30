<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SellerProfile;
use App\Services\ImageVariants;
use Database\Seeders\DemoCatalog;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoPicturesTest extends TestCase
{
    use RefreshDatabase;

    private function store(int $n): SellerProfile
    {
        return SellerProfile::whereHas('user', fn ($q) => $q->where('email', "seller{$n}@demo.local"))->sole();
    }

    private function listing(string $name): Product
    {
        return Product::withTrashed()->where('name', $name)->sole();
    }

    /** A published listing with exactly these pictures (the factory adds one of its own). */
    private function listingWith(SellerProfile $store, string $name, array $files): Product
    {
        $product = Product::factory()->published()->create(['seller_profile_id' => $store->id, 'category_id' => $this->category()->id, 'name' => $name]);
        $product->images()->delete();
        foreach ($files as $n => $file) {
            $product->images()->create(['path' => "products/{$product->id}/{$file}", 'sort_order' => $n]);
        }

        return $product;
    }

    public function test_every_demo_listing_gets_its_own_photos_and_every_photo_is_used_once(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(DemoSeeder::class);

        $products = Product::with('images', 'variants')->get();
        $this->assertCount(42, $products);
        $used = [];
        foreach ($products as $product) {
            $this->assertSame(ProductStatus::Published, $product->status, $product->name);
            $this->assertNotNull($product->category_id, $product->name);
            $this->assertNotEmpty($product->images, $product->name);
            foreach ($product->images as $image) {
                $this->assertMatchesRegularExpression("#^products/{$product->id}/demo-photo-[a-z0-9-]+-[0-9a-f]{8}\.jpg$#", $image->path);
                $this->assertTrue(DemoCatalog::generated($image->path));
                Storage::disk('local')->assertExists($image->path);
                $this->assertCount(2, $image->copies(), 'each photo gets its smaller copies');
                Storage::disk('public')->assertExists($image->copies());
                $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('local')->get($image->path))['mime']);
                $used[] = preg_replace('#^.*/demo-photo-(.+)-[0-9a-f]{8}\.jpg$#', '$1', $image->path);
            }
        }

        // Every photo in the folder is on exactly one listing; nothing is missing or shown twice.
        $files = array_map(fn ($f) => basename($f, '.jpg'), glob(DemoCatalog::PHOTO_DIR.'/*.jpg'));
        sort($files);
        sort($used);
        $this->assertSame($files, $used);

        // The same bed photographed in two colors is one listing with both photos and an option per color.
        $bed = $this->listing('Fluted Upholstered Bed');
        $this->assertSame(['Fluted Upholstered Bed in grey', 'Fluted Upholstered Bed in beige'], $bed->images()->orderBy('sort_order')->pluck('alt')->all());
        $this->assertSame(['Grey' => 2, 'Beige' => 3], $bed->variants()->orderBy('sort_order')->pluck('stock_quantity', 'color')->all());
        $this->assertSame(5, $bed->fresh()->stock_quantity);
        $this->getJson("/api/products/{$bed->slug}")->assertOk()->assertJsonCount(2, 'data.images')->assertJsonCount(2, 'data.variants');

        // Every category has demo listings except Other Furniture, and office pieces are sold by the office store.
        $this->assertSame(['beds-mattresses' => 8, 'cabinets-shelves' => 4, 'dining' => 3, 'office' => 6, 'sofas-living-room' => 13, 'wardrobes-storage' => 8],
            Product::join('categories', 'categories.id', '=', 'products.category_id')->groupBy('categories.slug')->orderBy('categories.slug')
                ->selectRaw('categories.slug, count(*) as n')->pluck('n', 'slug')->map(fn ($n) => (int) $n)->all());
        $office = $this->store(4);
        $this->assertSame(0, Product::whereHas('category', fn ($q) => $q->where('slug', 'office'))->where('seller_profile_id', '!=', $office->id)->count());

        $this->artisan('marketplace:demo-pictures')->expectsOutput('Demo listings and photos are up to date.')->assertSuccessful();
        $this->assertSame(43, ProductImage::count(), 'running again adds nothing');
    }

    public function test_older_demo_data_is_brought_up_to_date_without_touching_store_photos(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $one = $this->store(1);

        // An older demo listing with a drawn picture, one a store gave its own photo, and an odd path.
        $drawn = $this->listingWith($one, 'Mahogany Coffee Table', ['demo-art-abcd1234.jpg', 'demo-abcd5678.jpg']);
        $drawnImage = $drawn->images()->first();
        Storage::disk('local')->put($drawnImage->path, 'x');
        $kept = $this->listingWith($one, 'Used Leather Armchair', ['demo-art-efgh5678.jpg', 'store-photo.jpg']);
        $keptDrawn = $kept->images()->where('sort_order', 0)->sole();
        Storage::disk('local')->put($keptDrawn->path, 'x');
        $odd = $this->listingWith($one, 'Odd Path Table', ['demo-../../secret.jpg']);
        $bare = $this->listingWith($this->store(2), 'Listing Without Pictures', []);
        // Another store's listing, never touched even with a demo-looking file name.
        $other = $this->listingWith($this->approvedSeller(), 'Not Demo', ['demo-art-ijkl9012.jpg']);

        // A catalog listing still showing a drawn picture, one whose photo files were lost, and one the admin deleted.
        $bed = $this->listing('Winged Channel Bed');
        $bed->images()->sole()->deleteFiles();
        $bed->images()->delete();
        $bed->images()->create(['path' => "products/{$bed->id}/demo-art-mnop3456.jpg", 'sort_order' => 0]);
        $table = $this->listing('Marble-Look Coffee Table');
        $lost = $table->images()->sole();
        Storage::disk('local')->delete($lost->path);
        $desk = $this->listing('Desk with Five Drawers');
        $desk->delete();
        $shoes = $this->listing('Shoe Bench with Seat');
        $shoesImage = $shoes->images()->sole();
        Storage::disk('public')->delete($shoesImage->copies());
        // A store photo on a catalog listing wins over the demo photo.
        $wardrobe = $this->listing('Sage Green Wardrobe');
        $wardrobe->images()->create(['path' => "products/{$wardrobe->id}/my-own-photo.jpg", 'sort_order' => 5]);
        $wardrobeBefore = $wardrobe->images()->pluck('path')->all();

        $this->artisan('marketplace:demo-pictures')
            ->expectsOutput('Demo listings: 0 added, 4 with pictures updated, 1 old ones taken down.')
            ->assertSuccessful();

        $this->assertSame(ProductStatus::Archived, $drawn->fresh()->status, 'the old drawn listing leaves the shop');
        $this->assertSame(0, $drawn->images()->count());
        Storage::disk('local')->assertMissing($drawnImage->path);
        $this->assertSame(ProductStatus::Published, $kept->fresh()->status, 'a listing with a store photo stays');
        $this->assertSame(["products/{$kept->id}/store-photo.jpg"], $kept->images()->pluck('path')->all(), 'only the drawn picture beside it goes');
        Storage::disk('local')->assertMissing($keptDrawn->path);
        $this->assertSame(ProductStatus::Published, $odd->fresh()->status, 'odd paths are not demo pictures');
        $this->assertSame(ProductStatus::Published, $bare->fresh()->status, 'a listing without pictures is not the demo\'s to take down');
        $this->assertSame(ProductStatus::Published, $other->fresh()->status, 'other stores are never touched');
        $this->assertSame(1, $other->images()->count());

        $this->assertMatchesRegularExpression('#/demo-photo-winged-channel-bed-[0-9a-f]{8}\.jpg$#', $bed->images()->sole()->path);
        Storage::disk('local')->assertExists($table->images()->sole()->path);
        $this->assertTrue($desk->fresh()->trashed(), 'a deleted demo listing stays deleted');
        $this->assertSame($shoesImage->id, $shoes->images()->sole()->id, 'lost copies are made again on the same picture');
        Storage::disk('public')->assertExists($shoes->images()->sole()->copies());
        $this->assertSame($wardrobeBefore, $wardrobe->images()->pluck('path')->all());

        // Archived and stripped of its pictures, the old listing is left alone even if an admin publishes it again.
        $drawn->refresh();
        $drawn->status = ProductStatus::Published;
        $drawn->save();
        $this->artisan('marketplace:demo-pictures')->expectsOutput('Demo listings and photos are up to date.')->assertSuccessful();
        $this->assertSame(ProductStatus::Published, $drawn->fresh()->status);
        $this->getJson('/api/products/'.$bed->slug)->assertOk();
    }

    public function test_photos_are_not_redone_on_every_start_when_copies_cannot_be_made(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->app->instance(ImageVariants::class, new class extends ImageVariants
        {
            public function make(string $path): array
            {
                return [];
            }
        });
        $this->seed(DemoSeeder::class);
        $ids = ProductImage::orderBy('id')->pluck('id')->all();
        $this->assertCount(43, $ids);

        $this->artisan('marketplace:demo-pictures')->expectsOutput('Demo listings and photos are up to date.')->assertSuccessful();
        $this->assertSame($ids, ProductImage::orderBy('id')->pluck('id')->all());
    }

    public function test_never_runs_outside_a_test_site_or_without_demo_stores(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->seedReferenceData();

        $this->artisan('marketplace:demo-pictures')->expectsOutput('No demo stores here; nothing to do.')->assertSuccessful();

        $this->app['env'] = 'production';
        $this->artisan('marketplace:demo-pictures')->expectsOutput('Demo photos are for test sites only; nothing changed.')->assertSuccessful();
        $this->assertSame(0, Product::count());
    }
}
