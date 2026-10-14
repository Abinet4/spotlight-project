<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'is_mine' => $this->sender_id === $request->user()?->id,
            'body' => $this->body,
            'quote_id' => $this->quote_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
