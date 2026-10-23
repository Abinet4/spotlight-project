<?php

namespace App\Http\Resources;

use App\Models\SellerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full seller profile, including review status. Only for the owner and administrators.
 *
 * @mixin SellerProfile
 */
class SellerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_name' => $this->store_name,
            'slug' => $this->slug,
            'description' => $this->description,
            'business_type' => $this->business_type->value,
            'phone' => $this->phone,
            'tin_number' => $this->tin_number,
            'city' => $this->whenLoaded('city', fn () => $this->city?->name),
            'area' => $this->whenLoaded('area', fn () => $this->area?->name),
            'city_id' => $this->city_id,
            'area_id' => $this->area_id,
            'address_line' => $this->address_line,
            // The store's pin prices delivery by distance. Never shown on public pages.
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'offers_delivery' => $this->offers_delivery,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'owner' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'products_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
