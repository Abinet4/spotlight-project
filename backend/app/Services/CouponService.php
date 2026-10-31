<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Checks a coupon code against a priced cart and takes it off the totals, the same way for the
 * checkout preview and for placing the order.
 *
 * The discount comes off the items only (not delivery or VAT) and is shared between the stores'
 * orders in proportion to their items, so each order shows what was taken off it. VAT stays on the
 * full price: Betoch pays the store the discount, so the store's sale is unchanged.
 */
class CouponService
{
    /**
     * @param  array<string, mixed>  $priced  from CheckoutCalculator::calculate()
     * @param  Coupon|null  $locked  the coupon, already locked, when placing an order (see lock())
     * @return array{0: array<string, mixed>, 1: Coupon|null} the priced cart with the coupon applied, and the coupon if it applied
     */
    public function apply(array $priced, ?string $code, User $customer, ?Coupon $locked = null): array
    {
        foreach ($priced['groups'] as $i => $group) {
            $priced['groups'][$i]['coupon_discount_santim'] = 0;
        }
        $priced['coupon'] = null;
        $code = Coupon::normalize($code);
        if ($code === '') {
            return [$priced, null];
        }

        $coupon = $locked ?? Coupon::where('code', $code)->first();
        $error = $this->problem($coupon, $customer, (int) $priced['items_total_santim']);
        $discount = $error === null ? $coupon->discountFor((int) $priced['items_total_santim']) : 0;

        $priced['coupon'] = [
            'code' => $code,
            'label' => $coupon && $error === null ? $coupon->label() : null,
            'discount_santim' => $discount,
            'error' => $error,
        ];
        if ($discount === 0) {
            return [$priced, null];
        }

        $shares = self::share($discount, array_column($priced['groups'], 'items_total_santim'));
        foreach ($priced['groups'] as $i => $group) {
            $priced['groups'][$i]['coupon_discount_santim'] = $shares[$i];
            $priced['groups'][$i]['total_santim'] -= $shares[$i];
        }
        $priced['grand_total_santim'] -= $discount;

        return [$priced, $coupon];
    }

    /**
     * Lock the coupon row for placing an order. Call it before the transaction reads anything else: on
     * MySQL the transaction's snapshot starts at its first plain read, so locking first means the uses
     * counted afterwards include every order placed with the code until now. Checkouts using the code
     * are not locked themselves, as payments lock them before listings and orders lock listings first.
     */
    public function lock(?string $code): ?Coupon
    {
        $code = Coupon::normalize($code);

        return $code === '' ? null : Coupon::where('code', $code)->lockForUpdate()->first();
    }

    /** Why this code cannot be used on this cart now, or null when it can. */
    private function problem(?Coupon $coupon, User $customer, int $itemsSantim): ?string
    {
        if (! $coupon || ! $coupon->is_active) {
            return 'This code is not valid. Check the spelling.';
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            return 'This code can be used from '.self::day($coupon->starts_at).'.';
        }
        if ($coupon->ends_at && ! $coupon->ends_at->isFuture()) {
            return 'This code ended on '.self::day($coupon->ends_at).'.';
        }
        if ($itemsSantim < $coupon->min_items_santim) {
            return 'This code is for items of '.Alerts::etb($coupon->min_items_santim).' or more. Your items come to '.Alerts::etb($itemsSantim).'.';
        }

        if ($coupon->max_uses_per_customer !== null && $coupon->uses()->where('customer_id', $customer->id)->count() >= $coupon->max_uses_per_customer) {
            return $coupon->max_uses_per_customer === 1 ? 'You have already used this code.' : 'You have used this code as many times as it allows.';
        }
        if ($coupon->max_uses !== null && $coupon->uses()->count() >= $coupon->max_uses) {
            return 'This code has been used up.';
        }

        return null;
    }

    /**
     * Split $amount (at most the sum of $weights) over $weights in proportion, in whole santim that add
     * up exactly and never more than a weight: each part rounded down, then the santim left over go to
     * the largest remainders (the first of equals).
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public static function share(int $amount, array $weights): array
    {
        $weights = array_values($weights);
        $total = array_sum($weights);
        if ($total <= 0 || $amount <= 0) {
            return array_fill(0, count($weights), 0);
        }
        $amount = min($amount, $total);
        $parts = [];
        $remainders = [];
        foreach ($weights as $i => $w) {
            if ($w > intdiv(PHP_INT_MAX, $amount)) {
                // Too large to multiply exactly (billions of birr): close enough, and the sum is fixed below.
                $exact = $amount / $total * $w;
                $parts[$i] = min($w, (int) floor($exact));
                $remainders[$i] = $exact - floor($exact);
            } else {
                $parts[$i] = intdiv($amount * $w, $total);
                $remainders[$i] = ($amount * $w) % $total / $total;
            }
        }
        uksort($remainders, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
        $order = array_keys($remainders);
        for ($left = $amount - array_sum($parts), $n = 0; $left !== 0 && $n < 4 * count($order); $n++) {
            $i = $left > 0 ? $order[$n % count($order)] : $order[count($order) - 1 - $n % count($order)];
            if ($left > 0 && $parts[$i] < $weights[$i]) {
                $parts[$i]++;
                $left--;
            } elseif ($left < 0 && $parts[$i] > 0) {
                $parts[$i]--;
                $left++;
            }
        }
        ksort($parts);

        return $parts;
    }

    private static function day(Carbon $at): string
    {
        return $at->copy()->timezone(config('marketplace.timezone'))->format('j M Y');
    }
}
