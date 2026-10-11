<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Positive amounts increase what the seller owes the platform, negative amounts reduce it.
 */
class SellerLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['commission', 'payment', 'adjustment'];

    public const METHODS = ['cash', 'bank_transfer', 'telebirr', 'other'];

    protected function casts(): array
    {
        return [
            'amount_santim' => 'integer',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
