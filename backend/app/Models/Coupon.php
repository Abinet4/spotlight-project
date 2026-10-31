<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Services\Alerts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A code Betoch gives customers for money off their items at checkout. Betoch pays the discount, not
 * the stores: each store is credited its share of it when its order is delivered.
 *
 * Either a percent off (optionally capped) or an amount off. An amount needs a minimum spend above it,
 * and a percent is at most 90, so no order is ever free.
 */
class Coupon extends Model
{
    protected $fillable = [
        'code', 'description', 'percent_off', 'amount_off_santim', 'max_discount_santim', 'min_items_santim',
        'starts_at', 'ends_at', 'max_uses', 'max_uses_per_customer', 'is_active',
    ];

    protected $attributes = [
        'min_items_santim' => 0,
        'max_uses_per_customer' => 1,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'percent_off' => 'integer',
            'amount_off_santim' => 'integer',
            'max_discount_santim' => 'integer',
            'min_items_santim' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses' => 'integer',
            'max_uses_per_customer' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Codes are matched without case or spaces: " save 10 " is SAVE10. */
    public static function normalize(?string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $code));
    }

    public function checkouts(): HasMany
    {
        return $this->hasMany(Checkout::class);
    }

    /**
     * Checkouts that used this code and still count: at least one of their orders is not cancelled.
     * A checkout whose orders were all cancelled (or never paid) gives the use back.
     */
    public function uses(): HasMany
    {
        return $this->checkouts()->whereHas('orders', fn (Builder $q) => $q->where('status', '!=', OrderStatus::Cancelled->value));
    }

    /** What this code takes off items worth $itemsSantim, before checking dates, limits or the minimum. */
    public function discountFor(int $itemsSantim): int
    {
        if ($this->percent_off) {
            $off = intdiv($itemsSantim * $this->percent_off + 50, 100);
            if ($this->max_discount_santim) {
                $off = min($off, $this->max_discount_santim);
            }
        } else {
            $off = (int) $this->amount_off_santim;
        }

        return max(0, min($off, $itemsSantim));
    }

    /** "10% off your items, up to ETB 1,000 off" or "ETB 500 off your items, on items of ETB 5,000 or more". */
    public function label(): string
    {
        $what = $this->percent_off
            ? $this->percent_off.'% off your items'.($this->max_discount_santim ? ', up to '.Alerts::etb($this->max_discount_santim).' off' : '')
            : Alerts::etb((int) $this->amount_off_santim).' off your items';

        return $what.($this->min_items_santim > 0 ? ', on items of '.Alerts::etb($this->min_items_santim).' or more' : '');
    }
}
