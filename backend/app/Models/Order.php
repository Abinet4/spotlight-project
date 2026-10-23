<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One seller's part of a checkout. Totals are snapshots; nothing is mass assignable.
 */
class Order extends Model
{
    protected $attributes = [
        'status' => 'pending_confirmation',
        'payment_status' => 'unpaid',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'items_total_santim' => 'integer',
            'delivery_fee_santim' => 'integer',
            'delivery_distance_m' => 'integer',
            'delivery_distance_estimated' => 'boolean',
            'carry_fee_santim' => 'integer',
            'total_santim' => 'integer',
            'vat_santim' => 'integer',
            'paid_santim' => 'integer',
            'commission_rate_bp' => 'integer',
            'commission_santim' => 'integer',
            'vat_rate_bp' => 'integer',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }

    /**
     * Orders a store can see: cash orders, and online orders once paid. An online order that was
     * never paid (still waiting, or expired) is the customer's business only.
     */
    public function scopeVisibleToSeller(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('payment_method', '!=', 'online')->orWhereNotNull('paid_at'));
    }

    public function deliveryFeeConfirmed(): bool
    {
        return $this->fulfillment === 'pickup' || $this->delivery_fee_santim !== null;
    }

    /** VAT on this order (items and delivery), added on top at checkout at the rate frozen then. */
    public function vatSantim(): int
    {
        return (int) $this->vat_santim;
    }

    /**
     * VAT shown on the order: the rate, the amount, whether it sat inside the prices (orders placed while
     * VAT-registered stores priced VAT in, before it moved to checkout), and the store's VAT number.
     *
     * @return array{rate_percent: float|int, amount_santim: int, included: bool, number: ?string}|null
     */
    public function vatSummary(): ?array
    {
        if ((int) $this->vat_rate_bp <= 0) {
            return null;
        }

        return [
            'rate_percent' => $this->vat_rate_bp / 100,
            'amount_santim' => $this->vatSantim(),
            'included' => $this->vatInsidePrice(),
            'number' => $this->vat_number,
        ];
    }

    /** Placed before VAT moved to checkout: its VAT sits inside the total instead of on top. */
    public function vatInsidePrice(): bool
    {
        return $this->vatSantim() > 0 && (int) $this->total_santim === (int) $this->items_total_santim + (int) $this->delivery_fee_santim;
    }

    /** The VAT part of a price that already includes it: price x rate / (100% + rate), rounded half up. */
    public static function vatInside(int $priceSantim, int $rateBp): int
    {
        if ($rateBp <= 0 || $priceSantim <= 0) {
            return 0;
        }
        $base = 10000 + $rateBp;

        return intdiv($priceSantim, $base) * $rateBp + intdiv(($priceSantim % $base) * $rateBp + intdiv($base, 2), $base);
    }

    /** VAT added on top of an amount: amount x rate, rounded half up to the santim. */
    public static function vatOn(int $amountSantim, int $rateBp): int
    {
        if ($rateBp <= 0 || $amountSantim <= 0) {
            return 0;
        }

        return intdiv($amountSantim, 10000) * $rateBp + intdiv(($amountSantim % 10000) * $rateBp + 5000, 10000);
    }

    /**
     * Commission rounded half up in integer santim: no floats touch money.
     */
    public static function commissionFor(int $itemsTotalSantim, int $rateBp): int
    {
        // Split the total so the multiplication cannot overflow 64-bit integers on very large orders.
        return intdiv($itemsTotalSantim, 10000) * $rateBp + intdiv(($itemsTotalSantim % 10000) * $rateBp + 5000, 10000);
    }
}
