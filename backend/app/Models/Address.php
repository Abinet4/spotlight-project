<?php

namespace App\Models;

use App\Support\MapPin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// user_id and is_default are set by the controller, never from the request body directly.
#[Fillable(['label', 'recipient_name', 'phone', 'area_id', 'address_line', 'landmark', 'latitude', 'longitude', 'floor', 'has_lift', 'door_width_cm', 'stair_width_cm', 'lift_door_width_cm'])]
class Address extends Model
{
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'has_lift' => 'boolean', 'floor' => 'integer', 'door_width_cm' => 'integer', 'stair_width_cm' => 'integer', 'lift_door_width_cm' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** The map pin as [latitude, longitude], or null when none is set. */
    public function pin(): ?array
    {
        return MapPin::from($this->latitude, $this->longitude);
    }
}
