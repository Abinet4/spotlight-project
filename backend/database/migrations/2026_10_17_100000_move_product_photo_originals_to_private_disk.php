<?php

use App\Models\ProductImage;
use App\Services\ImageVariants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Uploaded originals used to sit on the public disk next to their copies, camera data and GPS
 * location included, at a name anyone could guess from a copy's name. Move them to the private disk
 * and make copies for any photo that has none, since only copies are shown from now on.
 * Files only: no table changes, and running it again does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk(ProductImage::ORIGINALS_DISK);
        $variants = app(ImageVariants::class);

        ProductImage::query()->orderBy('id')->chunkById(100, function ($images) use ($public, $private, $variants) {
            foreach ($images as $image) {
                if ($public->exists($image->path)) {
                    if (! $private->exists($image->path) && ! $private->put($image->path, $public->get($image->path))) {
                        Log::warning('Could not move a product photo original to the private disk.', ['id' => $image->id]);

                        continue;
                    }
                    $public->delete($image->path);
                }
                if ($image->large_path === null && $private->exists($image->path) && ! $variants->refresh($image)) {
                    Log::warning('A product photo has no copies, so it is no longer shown.', ['id' => $image->id]);
                }
            }
        });
    }

    public function down(): void
    {
        // Originals stay private: putting location data back on the public disk is never wanted.
    }
};
