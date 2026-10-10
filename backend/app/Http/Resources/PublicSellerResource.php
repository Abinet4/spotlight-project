<?php

namespace App\Http\Resources;

use App\Models\SellerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What guests see about a seller. No TIN, owner email or review notes.
 *
 * @mixin SellerProfile
 */
class PublicSellerResource extends JsonResource
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
            'city' => $this->whenLoaded('city', fn () => $this->city?->name),
            'area' => $this->whenLoaded('area', fn () => $this->area?->name),
            'offers_custom_orders' => $this->offers_custom_orders,
            'offers_delivery' => $this->offers_delivery,
            'products_count' => $this->whenCounted('products'),
            'member_since' => $this->created_at?->toDateString(),
        ];
    }
}
