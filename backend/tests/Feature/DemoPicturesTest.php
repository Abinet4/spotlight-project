<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\DemoFurnitureArt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoPicturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_redraws_only_old_demo_pictures_and_is_safe_to_rerun(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->seedReferenceData();
        $seller = $this->approvedSeller();
        $sofa = Product::factory()->published()->create(['seller_profile_id' => $seller->id, 'category_id' => $this->category()->id, 'name' => 'Grey Sofa', 'color' => 'Grey']);

        $old = $sofa->images()->create(['path' => "products/{$sofa->id}/demo-abcd1234.jpg", 'sort_order' => 0]);
        $photo = $sofa->images()->create(['path' => "products/{$sofa->id}/real-photo.jpg", 'sort_order' => 1]);
        $tricky = $sofa->images()->create(['path' => "products/{$sofa->id}/demo-../../secret.jpg", 'sort_order' => 2]);
        foreach ([$old, $photo] as $img) {
            Storage::disk('local')->put($img->path, 'x');
        }

        $before = ProductImage::count();
        $this->artisan('marketplace:demo-pictures')->expectsOutput('Redrew 1 demo picture.')->assertSuccessful();

        $redrawn = $old->fresh()->path;
        $this->assertMatchesRegularExpression("#^products/{$sofa->id}/demo-art-[A-Za-z0-9]{8}\.jpg$#", $redrawn);
        Storage::disk('local')->assertExists($redrawn);
        Storage::disk('public')->assertMissing($redrawn);
        Storage::disk('local')->assertMissing("products/{$sofa->id}/demo-abcd1234.jpg");
        $this->assertSame("products/{$sofa->id}/real-photo.jpg", $photo->fresh()->path, 'seller photos are never touched');
        Storage::disk('local')->assertExists($photo->path);
        $this->assertSame("products/{$sofa->id}/demo-../../secret.jpg", $tricky->fresh()->path, 'odd paths are skipped');
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('local')->get($redrawn))['mime']);
        $copies = [$old->fresh()->thumb_path, $old->fresh()->large_path];
        $this->assertNotContains(null, $copies, 'the redrawn picture gets its smaller copies');
        Storage::disk('public')->assertExists($copies);

        $this->artisan('marketplace:demo-pictures')->expectsOutput('Demo pictures are up to date.')->assertSuccessful();
        $this->assertSame($redrawn, $old->fresh()->path);
        $this->assertSame($before, ProductImage::count(), 'pictures are replaced, never added');
    }

    public function test_redraws_drawn_demo_pictures_whose_files_are_gone(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->seedReferenceData();
        $seller = $this->approvedSeller();
        $bed = Product::factory()->published()->create(['seller_profile_id' => $seller->id, 'category_id' => $this->category()->id, 'name' => 'Oak Bed', 'color' => 'Oak']);
        $art = fn (string $name) => "products/{$bed->id}/demo-art-{$name}.jpg";

        // Kept: every file there. Lost: nothing on disk (added before the volume). Half: a copy is gone.
        $kept = $bed->images()->create(['path' => $art('keep1234'), 'thumb_path' => 'products/k-thumb.webp', 'large_path' => 'products/k-large.webp', 'sort_order' => 0]);
        $lost = $bed->images()->create(['path' => $art('lost1234'), 'thumb_path' => 'products/l-thumb.webp', 'large_path' => 'products/l-large.webp', 'sort_order' => 1]);
        $half = $bed->images()->create(['path' => $art('half1234'), 'thumb_path' => 'products/h-thumb.webp', 'large_path' => 'products/h-large.webp', 'sort_order' => 2]);
        $photo = $bed->images()->create(['path' => "products/{$bed->id}/seller-photo.jpg", 'thumb_path' => 'products/p-thumb.webp', 'large_path' => 'products/p-large.webp', 'sort_order' => 3]);
        Storage::disk('local')->put($kept->path, 'x');
        Storage::disk('public')->put($kept->copies()[0], 'x');
        Storage::disk('public')->put($kept->copies()[1], 'x');
        Storage::disk('local')->put($half->path, 'x');
        Storage::disk('public')->put($half->thumb_path, 'x');

        $this->artisan('marketplace:demo-pictures')->expectsOutput('Redrew 2 demo pictures.')->assertSuccessful();

        $this->assertSame($art('keep1234'), $kept->fresh()->path, 'complete pictures are left alone');
        $this->assertSame("products/{$bed->id}/seller-photo.jpg", $photo->fresh()->path, 'a lost seller photo cannot be redrawn, so it is not touched');
        foreach ([$lost, $half] as $image) {
            $image = $image->fresh();
            $this->assertNotSame($art('lost1234'), $image->path);
            Storage::disk('local')->assertExists($image->path);
            $this->assertCount(2, $image->copies());
            Storage::disk('public')->assertExists($image->copies());
        }
        Storage::disk('local')->assertMissing($art('half1234'));
        Storage::disk('public')->assertMissing('products/h-thumb.webp');

        $this->artisan('marketplace:demo-pictures')->expectsOutput('Demo pictures are up to date.')->assertSuccessful();
        $this->getJson('/api/products/'.$bed->slug)->assertOk();
    }

    public function test_draws_every_kind_of_demo_listing(): void
    {
        $art = new DemoFurnitureArt;
        foreach (['Sofa', 'Armchair', 'Bed', 'Bunk Bed', 'Mattress', 'Wardrobe', 'Dresser', 'Filing Cabinet', 'Bookshelf', 'Display Cabinet', 'Desk', 'Kids Desk', 'Office Chair', 'Dining Set', 'Chair', 'Bench', 'Patio Set', 'Lamp'] as $i => $name) {
            $info = getimagesizefromstring($art->jpeg($name, $i % 2 ? 'Unknown colour' : 'walnut', $i));
            $this->assertSame([800, 600, 'image/jpeg'], [$info[0], $info[1], $info['mime']], $name);
        }
    }
}
