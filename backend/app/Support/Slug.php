<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class Slug
{
    /**
     * Build a slug unique within the model's table (soft-deleted rows included, since the
     * database unique index covers them). Falls back to a random token for non-Latin names.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function unique(string $modelClass, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($source, 80, '')) ?: Str::lower(Str::random(8));
        $slug = $base;

        $query = fn (string $candidate) => $modelClass::query()
            ->when(method_exists($modelClass, 'withTrashed'), fn ($q) => $q->withTrashed())
            ->where('slug', $candidate)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        for ($i = 2; $query($slug); $i++) {
            $slug = $i <= 20 ? "{$base}-{$i}" : $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
