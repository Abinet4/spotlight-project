<?php

namespace App\Http\Resources;

use App\Models\SellerLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SellerLedgerEntry */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount_santim' => $this->amount_santim,
            'order' => $this->order_id ? ['id' => $this->order_id, 'number' => $this->order?->number] : null,
            'method' => $this->method,
            'reference' => $this->reference,
            'note' => $this->note,
            'recorded_by' => $this->when($request->user()?->isAdmin(), fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
