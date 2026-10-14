<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'is_active'])]
class City extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }
}
