<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['path', 'thumb_path', 'large_path', 'alt', 'sort_order'])]
class ProductImage extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The uploaded file as the seller sent it, camera data and GPS location included. It is kept off
     * the public disk (never linked, not guessable from a copy's name) and only used to remake copies.
     */
    public const ORIGINALS_DISK = 'local';

    /** The photo for the product page: the large copy, or none when no copy could be made. */
    public function url(): ?string
    {
        return $this->large_path ? Storage::disk('public')->url($this->large_path) : null;
    }

    /** The photo for cards and lists. */
    public function thumbUrl(): ?string
    {
        $copy = $this->thumb_path ?? $this->large_path;

        return $copy ? Storage::disk('public')->url($copy) : null;
    }

    /** @return list<string> the copies on the public disk */
    public function copies(): array
    {
        return array_values(array_filter([$this->thumb_path, $this->large_path]));
    }

    /** Remove the private original and the public copies. */
    public function deleteFiles(): void
    {
        Storage::disk(self::ORIGINALS_DISK)->delete($this->path);
        Storage::disk('public')->delete($this->copies());
    }
}
