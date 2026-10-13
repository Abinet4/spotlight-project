<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductImageService
{
    public function __construct(private ImageVariants $variants) {}

    /**
     * Store uploaded images under a random file name (never the client's name).
     *
     * @param  array<UploadedFile>  $files
     */
    public function storeMany(Product $product, array $files): void
    {
        if ($files === []) {
            return;
        }

        $max = (int) config('marketplace.products.max_images');

        DB::transaction(function () use ($product, $files, $max) {
            // Lock the product row so two concurrent uploads cannot exceed the limit.
            Product::whereKey($product->id)->lockForUpdate()->first();

            $existing = $product->images()->count();
            if ($existing + count($files) > $max) {
                throw ValidationException::withMessages([
                    'images' => "A listing can have at most {$max} photos. It has {$existing} already.",
                ]);
            }

            $next = (int) $product->images()->max('sort_order') + ($existing > 0 ? 1 : 0);

            $originals = [];
            $copies = [];
            try {
                foreach ($files as $i => $file) {
                    // The original keeps the camera's data (GPS location included), so it stays private.
                    $path = $file->store("products/{$product->id}", ProductImage::ORIGINALS_DISK);
                    if ($path === false) {
                        throw new \RuntimeException('Image could not be saved.');
                    }
                    $originals[] = $path;
                    // Only the re-encoded copies are shown, so a photo without them is refused.
                    $made = $this->variants->make($path);
                    array_push($copies, ...array_values($made));
                    if (! isset($made['thumb_path'], $made['large_path'])) {
                        throw ValidationException::withMessages(["images.{$i}" => 'This photo could not be processed. Try another JPG, PNG or WebP photo.']);
                    }
                    $product->images()->create(['path' => $path, ...$made, 'sort_order' => $next++, 'alt' => $product->name]);
                }
            } catch (\Throwable $e) {
                // Do not leave orphaned files when a photo or the database write fails.
                Storage::disk(ProductImage::ORIGINALS_DISK)->delete($originals);
                Storage::disk('public')->delete($copies);
                throw $e;
            }
        });
    }

    public function delete(ProductImage $image): void
    {
        $image->delete();
        $image->deleteFiles();
    }
}
