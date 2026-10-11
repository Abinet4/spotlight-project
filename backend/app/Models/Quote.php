<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// conversation, seller and status are set by the controller.
#[Fillable(['product_id', 'title', 'description', 'price_santim', 'quantity', 'delivery_fee_santim', 'production_days', 'valid_until'])]
class Quote extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'price_santim' => 'integer',
            'delivery_fee_santim' => 'integer',
            'quantity' => 'integer',
            'production_days' => 'integer',
            'valid_until' => 'date',
            'responded_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Stored status, except a pending quote past its validity date reports as expired.
     * Validity is inclusive: a quote valid until today can still be accepted today.
     */
    public function effectiveStatus(): QuoteStatus
    {
        if ($this->status === QuoteStatus::Pending && $this->valid_until->endOfDay()->isPast()) {
            return QuoteStatus::Expired;
        }

        return $this->status;
    }

    public function totalSantim(): int
    {
        return $this->price_santim * $this->quantity + (int) $this->delivery_fee_santim;
    }
}
