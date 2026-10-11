<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view shows the reviewer as "Abebe K." only. Admins also see moderation fields.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user?->isAdmin() ?? false;
        $isAuthor = $user && $user->id === $this->customer_id;

        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'body' => $this->body,
            'reviewer' => $this->whenLoaded('customer', fn () => self::shortName($this->customer->name)),
            'product' => $this->whenLoaded('product', fn () => $this->product ? ['name' => $this->product->name, 'slug' => $this->product->slug] : null),
            'item_name' => $this->whenLoaded('orderItem', fn () => $this->orderItem->name),
            'seller' => $this->whenLoaded('seller', fn () => ['store_name' => $this->seller->store_name, 'slug' => $this->seller->slug]),
            'seller_reply' => $this->seller_reply,
            'seller_replied_at' => $this->seller_replied_at?->toIso8601String(),
            'verified_purchase' => true,
            'is_hidden' => $this->when($isAdmin || $isAuthor, $this->is_hidden),
            'hidden_reason' => $this->when($isAdmin, $this->hidden_reason),
            'order_number' => $this->when($isAdmin && $this->relationLoaded('order'), fn () => $this->order?->number),
            'customer_email' => $this->when($isAdmin && $this->relationLoaded('customer'), fn () => $this->customer->email),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = $parts[0] ?? 'Customer';
        $last = count($parts) > 1 ? mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : '';

        return trim("$first $last");
    }
}
