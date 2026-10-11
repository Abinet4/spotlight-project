<?php

namespace App\Http\Resources;

use App\Models\Quote;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quote */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'product_id' => $this->product_id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => Money::toBirr($this->price_santim),
            'price_santim' => $this->price_santim,
            'quantity' => $this->quantity,
            'delivery_fee' => Money::toBirr($this->delivery_fee_santim),
            'delivery_fee_santim' => $this->delivery_fee_santim,
            'total_santim' => $this->totalSantim(),
            'production_days' => $this->production_days,
            'valid_until' => $this->valid_until->toDateString(),
            'status' => $this->effectiveStatus()->value,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
