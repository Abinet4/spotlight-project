<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImageVariantsTest extends TestCase
{
    use RefreshDatabase;

    private const RED = [255, 0, 0];

    private const GREEN = [0, 255, 0];

    private const BLUE = [0, 0, 255];

    private const WHITE = [255, 255, 255];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedReferenceData();
    }

    public function test_upload_makes_thumb_and_large_copies_and_pages_use_them(): void
    {
        $seller = $this->approvedSeller();
        Sanctum::actingAs($seller->user);
        $product = Product::factory()->published()->create(['seller_profile_id' => $seller->id, 'category_id' => $this->category()->id]);

        $file = UploadedFile::fake()->createWithContent('big.jpg', $this->jpeg(3000, 2000));
        $this->post("/api/seller/products/{$product->id}/images", ['images' => [$file]], ['Accept' => 'application/json'])->assertOk();

        $image = ProductImage::query()->latest('id')->first();
        $this->assertMatchesRegularExpression('#-thumb\.webp$#', $image->thumb_path);
        $this->assertMatchesRegularExpression('#-large\.webp$#', $image->large_path);
        $this->assertSame([640, 427], $this->dimensions($image->thumb_path));
        $this->assertSame([1600, 1067], $this->dimensions($image->large_path));
        $this->assertSame([3000, 2000], $this->dimensions($image->path, 'local'), 'the original is kept, privately');
        Storage::disk('public')->assertMissing($image->path);
        $this->assertSame([$image->large_path, $image->thumb_path], $this->files(), 'only the copies are public');
        $this->assertLessThan(strlen(Storage::disk('local')->get($image->path)), strlen(Storage::disk('public')->get($image->thumb_path)));

        // Cards get the thumbnail of the first photo that has copies (the factory photo before it has none);
        // the product page gets the large copy plus a thumbnail per photo.
        $this->assertLessThanOrEqual($image->sort_order, ProductImage::whereKeyNot($image->id)->value('sort_order'), 'the factory photo comes first');
        $this->getJson('/api/products?per_page=48')->assertOk()
            ->assertJsonFragment(['image' => Storage::disk('public')->url($image->thumb_path)]);
        $detail = $this->getJson("/api/products/{$product->slug}")->assertOk()->json('data.images');
        $this->assertSame(Storage::disk('public')->url($image->large_path), $detail[0]['url']);
        $this->assertSame(Storage::disk('public')->url($image->thumb_path), $detail[0]['thumb']);
        // A photo without copies (from the factory) is not shown: there is nothing public to link to.
        $this->assertCount(1, $detail);
        $this->assertNull(ProductImage::whereKeyNot($image->id)->first()->thumbUrl());
    }

    public function test_phone_photos_are_turned_upright_for_all_eight_exif_orientations(): void
    {
        // Source corners: top-left red, top-right green, bottom-left blue, bottom-right white.
        // Expected corners of the upright picture (TL, TR, BL, BR), from the EXIF specification.
        $expected = [
            1 => [self::RED, self::GREEN, self::BLUE, self::WHITE],
            2 => [self::GREEN, self::RED, self::WHITE, self::BLUE],     // mirrored
            3 => [self::WHITE, self::BLUE, self::GREEN, self::RED],     // upside down
            4 => [self::BLUE, self::WHITE, self::RED, self::GREEN],     // mirrored upside down
            5 => [self::RED, self::BLUE, self::GREEN, self::WHITE],     // transposed
            6 => [self::BLUE, self::RED, self::WHITE, self::GREEN],     // turned 90 clockwise
            7 => [self::WHITE, self::GREEN, self::BLUE, self::RED],     // transverse
            8 => [self::GREEN, self::WHITE, self::RED, self::BLUE],     // turned 90 counter-clockwise
        ];

        foreach ($expected as $orientation => $corners) {
            $path = "products/1/o{$orientation}.jpg";
            Storage::disk('local')->put($path, $this->jpeg(400, 200, $orientation));
            $this->assertSame($orientation, exif_read_data(Storage::disk('local')->path($path))['Orientation'], 'fixture carries the tag');

            $copies = app(ImageVariants::class)->make($path);
            $this->assertCount(2, $copies, "orientation {$orientation}");
            $sideways = $orientation >= 5;
            $this->assertSame($sideways ? [200, 400] : [400, 200], $this->dimensions($copies['large_path']), "orientation {$orientation}");
            $this->assertSame($corners, $this->corners($copies['large_path']), "orientation {$orientation}");
            $this->assertSame($corners, $this->corners($copies['thumb_path']), "orientation {$orientation} thumb");
        }
    }

    public function test_copies_drop_photo_metadata_such_as_location(): void
    {
        Storage::disk('local')->put('products/1/gps.jpg', $this->jpeg(1000, 800, 1));
        $this->assertStringContainsString('Home-of-the-seller', Storage::disk('local')->get('products/1/gps.jpg'));

        $copies = app(ImageVariants::class)->make('products/1/gps.jpg');
        foreach ($copies as $copy) {
            $bytes = Storage::disk('public')->get($copy);
            $this->assertStringNotContainsString('Home-of-the-seller', $bytes);
            $this->assertStringNotContainsString('Exif', $bytes);
        }
        // Nothing on the public disk carries the location, the original included.
        $this->assertCount(2, $this->files());
        foreach ($this->files() as $file) {
            $this->assertStringNotContainsString('Home-of-the-seller', Storage::disk('public')->get($file));
        }
    }

    public function test_small_photos_are_not_enlarged_and_png_transparency_is_kept(): void
    {
        Storage::disk('local')->put('products/1/small.png', $this->png(300, 250));
        $copies = app(ImageVariants::class)->make('products/1/small.png');

        $this->assertSame([300, 250], $this->dimensions($copies['thumb_path']));
        $this->assertSame([300, 250], $this->dimensions($copies['large_path']));
        $img = imagecreatefromstring(Storage::disk('public')->get($copies['large_path']));
        $this->assertSame(127, (imagecolorat($img, 5, 5) >> 24) & 0x7F, 'see-through corner stays see-through');
    }

    public function test_without_webp_support_copies_are_jpeg_on_white(): void
    {
        $this->app->instance(ImageVariants::class, new class extends ImageVariants
        {
            protected function canWriteWebp(): bool
            {
                return false;
            }
        });
        Storage::disk('local')->put('products/1/small.png', $this->png(300, 250));
        $copies = app(ImageVariants::class)->make('products/1/small.png');

        $this->assertStringEndsWith('-thumb.jpg', $copies['thumb_path']);
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('public')->get($copies['large_path']))['mime']);
        $this->assertSame([self::WHITE], [$this->rgb(imagecreatefromstring(Storage::disk('public')->get($copies['large_path'])), 5, 5)]);
    }

    public function test_without_the_exif_extension_jpegs_get_no_copies_but_pngs_do(): void
    {
        $this->app->instance(ImageVariants::class, new class extends ImageVariants
        {
            protected function canReadOrientation(): bool
            {
                return false;
            }
        });
        Storage::disk('local')->put('products/1/side.jpg', $this->jpeg(400, 200, 6));
        Storage::disk('local')->put('products/1/flat.png', $this->png(300, 250));

        $this->assertSame([], app(ImageVariants::class)->make('products/1/side.jpg'), 'a sideways copy would be wrong');
        $this->assertCount(2, app(ImageVariants::class)->make('products/1/flat.png'));
        $this->assertSame(['products/1/flat-large.webp', 'products/1/flat-thumb.webp'], $this->files());
    }

    public function test_a_photo_that_cannot_be_processed_is_refused_and_nothing_is_left(): void
    {
        $seller = $this->approvedSeller();
        Sanctum::actingAs($seller->user);
        $product = Product::factory()->published()->create(['seller_profile_id' => $seller->id, 'category_id' => $this->category()->id]);
        $this->app->instance(ImageVariants::class, new class extends ImageVariants
        {
            protected function canWriteWebp(): bool
            {
                throw new \RuntimeException('GD is broken');
            }
        });

        $before = $product->images()->count();

        // The original cannot be shown (it keeps the camera's data), so without copies there is nothing to show.
        $this->post("/api/seller/products/{$product->id}/images", ['images' => [$this->photo('ok.jpg'), $this->photo('bad.jpg')]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('images.0');

        $this->assertSame($before, $product->images()->count());
        $this->assertSame([], $this->files());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_failed_second_copy_removes_the_first(): void
    {
        Storage::disk('local')->put('products/1/a.png', $this->png(300, 250));
        // A folder where the large copy should go makes that write fail.
        Storage::disk('public')->makeDirectory('products/1/a-large.webp');

        $this->assertSame([], app(ImageVariants::class)->make('products/1/a.png'));
        Storage::disk('public')->assertMissing('products/1/a-thumb.webp');
    }

    public function test_deleting_a_photo_removes_its_copies(): void
    {
        $seller = $this->approvedSeller();
        Sanctum::actingAs($seller->user);
        $product = Product::factory()->published()->create(['seller_profile_id' => $seller->id, 'category_id' => $this->category()->id]);
        $this->post("/api/seller/products/{$product->id}/images", ['images' => [$this->photo()]], ['Accept' => 'application/json'])->assertOk();
        $image = ProductImage::query()->latest('id')->first();
        $this->assertNotNull($image->thumb_path);
        Storage::disk('local')->assertExists($image->path);
        $this->assertCount(2, $image->copies());

        $this->deleteJson("/api/seller/products/{$product->id}/images/{$image->id}")->assertOk();

        Storage::disk('local')->assertMissing($image->path);
        foreach ($image->copies() as $file) {
            Storage::disk('public')->assertMissing($file);
        }
    }

    public function test_backfill_command_is_safe_to_rerun_and_reports_photos_it_cannot_read(): void
    {
        $product = Product::factory()->published()->create(['seller_profile_id' => $this->approvedSeller()->id, 'category_id' => $this->category()->id]);
        $good = $product->images()->first();
        Storage::disk('local')->put($good->path, $this->jpeg(2000, 1500));
        $broken = $product->images()->create(['path' => "products/{$product->id}/broken.jpg", 'sort_order' => 1]);
        Storage::disk('local')->put($broken->path, 'not a picture');
        $missing = $product->images()->create(['path' => "products/{$product->id}/gone.jpg", 'sort_order' => 2]);

        $this->artisan('marketplace:image-variants')
            ->expectsOutput('Made smaller copies of 1 photo.')
            ->expectsOutputToContain("2 photos could not be resized (ids {$broken->id}, {$missing->id})")
            ->assertSuccessful();

        $good->refresh();
        $this->assertSame([1600, 1200], $this->dimensions($good->large_path));
        $this->assertNull($broken->fresh()->thumb_path);
        $this->assertNull($missing->fresh()->thumb_path);
        $before = $this->files();

        // Rerun: the done photo is skipped, the broken ones are tried again, nothing is added.
        $this->artisan('marketplace:image-variants')->expectsOutput('Made smaller copies of 0 photos.')->assertSuccessful();
        $this->assertSame($good->thumb_path, $good->fresh()->thumb_path);
        $this->assertSame($before, $this->files());
    }

    public function test_backfill_all_remakes_copies_and_cleans_up_a_format_change(): void
    {
        $product = Product::factory()->published()->create(['seller_profile_id' => $this->approvedSeller()->id, 'category_id' => $this->category()->id]);
        $image = $product->images()->first();
        Storage::disk('local')->put($image->path, $this->jpeg(1000, 800));
        $this->artisan('marketplace:image-variants')->assertSuccessful();
        $this->assertStringEndsWith('.webp', $image->fresh()->thumb_path);

        $this->app->instance(ImageVariants::class, new class extends ImageVariants
        {
            protected function canWriteWebp(): bool
            {
                return false;
            }
        });
        $this->artisan('marketplace:image-variants')->expectsOutput('Made smaller copies of 0 photos.')->assertSuccessful();
        $this->artisan('marketplace:image-variants --all')->expectsOutput('Made smaller copies of 1 photo.')->assertSuccessful();

        $image->refresh();
        $this->assertStringEndsWith('-thumb.jpg', $image->thumb_path);
        $this->assertSame([$image->large_path, $image->thumb_path], $this->files(), 'the old WebP copies are removed');
    }

    public function test_moving_old_public_originals_to_the_private_disk(): void
    {
        $product = Product::factory()->published()->create(['seller_profile_id' => $this->approvedSeller()->id, 'category_id' => $this->category()->id]);
        // Uploaded before this change: the original (with GPS) is public and has no copies yet.
        $old = $product->images()->first();
        Storage::disk('public')->put($old->path, $this->jpeg(1000, 800, 1));
        // Also from before, but its copies were already made.
        $copied = $product->images()->create(['path' => "products/{$product->id}/copied.png", 'thumb_path' => "products/{$product->id}/copied-thumb.webp", 'large_path' => "products/{$product->id}/copied-large.webp", 'sort_order' => 1]);
        Storage::disk('public')->put($copied->path, $this->png(300, 250));
        Storage::disk('public')->put($copied->thumb_path, 'thumb');
        Storage::disk('public')->put($copied->large_path, 'large');
        // Already private, and one whose file is gone: neither stops the others.
        $private = $product->images()->create(['path' => "products/{$product->id}/private.png", 'sort_order' => 2]);
        Storage::disk('local')->put($private->path, $this->png(200, 200));
        $gone = $product->images()->create(['path' => "products/{$product->id}/gone.jpg", 'sort_order' => 3]);

        $migrate = fn () => (require database_path('migrations/2026_10_17_100000_move_product_photo_originals_to_private_disk.php'))->up();
        $migrate();

        foreach ([$old, $copied, $private] as $image) {
            Storage::disk('public')->assertMissing($image->path);
            Storage::disk('local')->assertExists($image->path);
            $this->assertNotNull($image->fresh()->large_path);
        }
        $this->assertSame([1000, 800], $this->dimensions($old->path, 'local'), 'moved unchanged');
        $this->assertSame('thumb', Storage::disk('public')->get($copied->thumb_path), 'existing copies are kept');
        $this->assertNull($gone->fresh()->large_path);
        foreach ($this->files() as $file) {
            $this->assertStringNotContainsString('Home-of-the-seller', Storage::disk('public')->get($file));
            $this->assertMatchesRegularExpression('/-(thumb|large)\.webp$/', $file);
        }

        // Running it again changes nothing.
        $public = $this->files();
        $migrate();
        $this->assertSame($public, $this->files());
        $this->assertSame([1000, 800], $this->dimensions($old->path, 'local'));
    }

    public function test_memory_limit_is_raised_for_a_big_photo_and_restored_afterwards(): void
    {
        Storage::disk('local')->put('products/1/huge.jpg', $this->jpeg(4000, 3000));
        $original = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '200M');
            $copies = app(ImageVariants::class)->make('products/1/huge.jpg');
            $this->assertSame([1600, 1200], $this->dimensions($copies['large_path']));
            $this->assertSame('200M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    // ---------------------------------------------------------------- helpers

    /** A JPEG split into four colored corners, optionally with an EXIF block (orientation plus a fake location). */
    private function jpeg(int $w, int $h, ?int $orientation = null): string
    {
        $img = imagecreatetruecolor($w, $h);
        $half = fn (int $n) => intdiv($n, 2);
        imagefilledrectangle($img, 0, 0, $half($w) - 1, $half($h) - 1, imagecolorallocate($img, ...self::RED));
        imagefilledrectangle($img, $half($w), 0, $w - 1, $half($h) - 1, imagecolorallocate($img, ...self::GREEN));
        imagefilledrectangle($img, 0, $half($h), $half($w) - 1, $h - 1, imagecolorallocate($img, ...self::BLUE));
        imagefilledrectangle($img, $half($w), $half($h), $w - 1, $h - 1, imagecolorallocate($img, ...self::WHITE));
        ob_start();
        imagejpeg($img, null, 95);
        $jpeg = ob_get_clean();

        if ($orientation === null) {
            return $jpeg;
        }

        // Big-endian TIFF: IFD0 has ImageDescription, Orientation and a pointer to a GPS IFD.
        $desc = "Home-of-the-seller\0";                    // 19 bytes, stored at offset 50
        $gpsOffset = 50 + strlen($desc) + 1;                // 70, kept even
        $entry = fn (int $tag, int $type, int $count, string $value) => pack('nnN', $tag, $type, $count).str_pad($value, 4, "\0");
        $tiff = "MM\0\x2A".pack('N', 8)
            .pack('n', 3)
            .$entry(0x010E, 2, strlen($desc), pack('N', 50))
            .$entry(0x0112, 3, 1, pack('n', $orientation))
            .$entry(0x8825, 4, 1, pack('N', $gpsOffset))
            .pack('N', 0)
            .$desc."\0"
            .pack('n', 1).$entry(0x0001, 2, 2, "N\0").pack('N', 0);
        $app1 = "Exif\0\0".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    /** A PNG with a see-through border and a red middle. */
    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 50, 50, $w - 50, $h - 50, imagecolorallocate($img, 255, 0, 0));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    /** @return array{int, int} */
    private function dimensions(string $path, string $disk = 'public'): array
    {
        $info = getimagesizefromstring(Storage::disk($disk)->get($path));

        return [$info[0], $info[1]];
    }

    /** Colors at the four corner quarters, snapped to the nearest test color (WebP is lossy). */
    private function corners(string $path): array
    {
        $img = imagecreatefromstring(Storage::disk('public')->get($path));
        $w = imagesx($img);
        $h = imagesy($img);
        $at = fn (float $x, float $y) => $this->rgb($img, (int) ($w * $x), (int) ($h * $y));

        return [$at(0.25, 0.25), $at(0.75, 0.25), $at(0.25, 0.75), $at(0.75, 0.75)];
    }

    private function rgb(\GdImage $img, int $x, int $y): array
    {
        $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ([self::RED, self::GREEN, self::BLUE, self::WHITE] as $color) {
            $d = ($c['red'] - $color[0]) ** 2 + ($c['green'] - $color[1]) ** 2 + ($c['blue'] - $color[2]) ** 2;
            if ($d < $bestDistance) {
                [$best, $bestDistance] = [$color, $d];
            }
        }
        $this->assertLessThan(60 ** 2, $bestDistance, 'color is close to a test color');

        return $best;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = Storage::disk('public')->allFiles();
        sort($files);

        return $files;
    }
}
