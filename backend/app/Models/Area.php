<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['city_id', 'delivery_zone_id', 'name', 'slug', 'is_active'])]
class Area extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }
}
