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
            'is_default' => $this->is_default,
        ];
    }
}
