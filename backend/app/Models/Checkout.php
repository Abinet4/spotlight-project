<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checkout extends Model
{
    protected function casts(): array
    {
        return [
            'items_total_santim' => 'integer',
            'delivery_total_santim' => 'integer',
            'grand_total_santim' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('id');
    }
}
