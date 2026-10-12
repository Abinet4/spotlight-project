<?php

namespace App\Http\Resources;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Address */
class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'area_id' => $this->area_id,
            'area' => $this->whenLoaded('area', fn () => $this->area->name),
            'address_line' => $this->address_line,
            'landmark' => $this->landmark,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'floor' => $this->floor,
            'has_lift' => $this->has_lift,
            'door_width_cm' => $this->door_width_cm,
            'stair_width_cm' => $this->stair_width_cm,
            'lift_door_width_cm' => $this->lift_door_width_cm,
            'is_default' => $this->is_default,
        ];
    }
}
