<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Coupon codes Betoch gives customers. Betoch pays every coupon: stores are credited the amount on delivery.
 */
class CouponController extends Controller
{
    public function index(): JsonResponse
    {
        $coupons = Coupon::withCount(['uses', 'checkouts'])->orderByDesc('id')->get();
        // What each code has taken off orders that were not cancelled.
        $given = Order::where('orders.status', '!=', OrderStatus::Cancelled->value)->where('orders.coupon_discount_santim', '>', 0)
            ->join('checkouts', 'checkouts.id', '=', 'orders.checkout_id')
            ->whereNotNull('checkouts.coupon_id')
            ->selectRaw('checkouts.coupon_id, sum(orders.coupon_discount_santim) as total')
            ->groupBy('checkouts.coupon_id')->pluck('total', 'checkouts.coupon_id');

        return response()->json(['data' => $coupons->map(fn (Coupon $c) => self::present($c, (int) ($given[$c->id] ?? 0)))->values()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $coupon = new Coupon($this->validated($request));
        $coupon->created_by = $request->user()->id;
        $coupon->save();
        $audit->log('coupon.created', $coupon, ['code' => $coupon->code, 'label' => $coupon->label()]);

        return response()->json(['data' => self::present($coupon->loadCount(['uses', 'checkouts']), 0)], 201);
    }

    public function update(Request $request, Coupon $coupon, AuditLogger $audit): JsonResponse
    {
        $coupon->fill($this->validated($request, $coupon));
        $changed = array_keys($coupon->getDirty());
        $coupon->save();
        if ($changed) {
            $audit->log('coupon.updated', $coupon, ['code' => $coupon->code, 'fields' => $changed]);
        }

        return response()->json(['data' => self::present($coupon->loadCount(['uses', 'checkouts']), 0)]);
    }

    public function destroy(Coupon $coupon, AuditLogger $audit): JsonResponse
    {
        // Orders keep the code they were placed with, so a code that was ever used is only turned off.
        if ($coupon->checkouts()->exists()) {
            throw ValidationException::withMessages(['coupon' => 'This code has been used on orders, so it cannot be deleted. Turn it off instead.']);
        }
        $audit->log('coupon.deleted', $coupon, ['code' => $coupon->code]);
        $coupon->delete();

        return response()->json(['message' => 'Coupon deleted.']);
    }

    private static function present(Coupon $c, int $givenSantim): array
    {
        $tz = config('marketplace.timezone');
        $state = match (true) {
            ! $c->is_active => 'off',
            $c->starts_at && $c->starts_at->isFuture() => 'scheduled',
            $c->ends_at && ! $c->ends_at->isFuture() => 'ended',
            $c->max_uses !== null && $c->uses_count >= $c->max_uses => 'used_up',
            default => 'active',
        };

        return [
            'id' => $c->id,
            'code' => $c->code,
            'description' => $c->description,
            'label' => $c->label(),
            'kind' => $c->percent_off ? 'percent' : 'amount',
            'percent_off' => $c->percent_off,
            'amount_off' => Money::toBirr($c->amount_off_santim),
            'max_discount' => Money::toBirr($c->max_discount_santim),
            'min_items' => $c->min_items_santim ? Money::toBirr($c->min_items_santim) : null,
            'starts_on' => $c->starts_at?->copy()->timezone($tz)->toDateString(),
            'ends_on' => $c->ends_at?->copy()->timezone($tz)->toDateString(),
            'max_uses' => $c->max_uses,
            'max_uses_per_customer' => $c->max_uses_per_customer,
            'is_active' => $c->is_active,
            'state' => $state,
            'uses' => (int) $c->uses_count,
            // Never used, even on an order later cancelled.
            'deletable' => (int) $c->checkouts_count === 0,
            'given_santim' => $givenSantim,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $request->merge(['code' => Coupon::normalize($request->input('code', $coupon?->code))]);
        $money = 'regex:/^\d{1,10}(\.\d{1,2})?$/';
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{3,32}$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:160'],
            'kind' => ['required', Rule::in(['percent', 'amount'])],
            'percent_off' => ['nullable', 'required_if:kind,percent', 'integer', 'min:1', 'max:90'],
            'amount_off' => ['nullable', 'required_if:kind,amount', 'string', $money],
            'max_discount' => ['nullable', 'string', $money],
            'min_items' => ['nullable', 'string', $money],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'Use 3 to 32 letters, numbers or dashes, for example SAVE10.',
            'code.unique' => 'Another coupon already uses this code.',
            'percent_off.required_if' => 'Enter the percent off.',
            'percent_off.max' => 'A coupon can take up to 90% off.',
            'amount_off.required_if' => 'Enter the amount off.',
            'amount_off.regex' => 'Enter the amount in birr, for example 500.',
            'max_discount.regex' => 'Enter the amount in birr, for example 1000.',
            'min_items.regex' => 'Enter the amount in birr, for example 5000.',
        ]);

        $tz = config('marketplace.timezone');
        $toSantim = fn (?string $birr) => filled($birr) ? Money::toSantim($birr) : null;
        $percent = $data['kind'] === 'percent';
        $out = [
            'code' => $data['code'],
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'percent_off' => $percent ? (int) $data['percent_off'] : null,
            'amount_off_santim' => $percent ? null : $toSantim($data['amount_off']),
            'max_discount_santim' => $percent ? $toSantim($data['max_discount'] ?? null) : null,
            'min_items_santim' => (int) $toSantim($data['min_items'] ?? null),
            // Whole days in Addis Ababa: from the start of the first day to the end of the last.
            'starts_at' => filled($data['starts_on'] ?? null) ? Carbon::createFromFormat('Y-m-d', $data['starts_on'], $tz)->startOfDay()->utc() : null,
            'ends_at' => filled($data['ends_on'] ?? null) ? Carbon::createFromFormat('Y-m-d', $data['ends_on'], $tz)->endOfDay()->utc() : null,
            'max_uses' => $data['max_uses'] ?? null,
        ];
        // Left out: once per customer for a new code, unchanged for an existing one.
        if (array_key_exists('max_uses_per_customer', $data)) {
            $out['max_uses_per_customer'] = $data['max_uses_per_customer'];
        }
        if (array_key_exists('is_active', $data)) {
            $out['is_active'] = (bool) $data['is_active'];
        }

        $errors = [];
        if (! $percent && $out['amount_off_santim'] < 100) {
            $errors['amount_off'] = 'The amount off must be at least 1 birr.';
        }
        // So that no order is free: the items must always cost more than the amount off.
        if (! $percent && $out['amount_off_santim'] >= 100 && $out['min_items_santim'] <= $out['amount_off_santim']) {
            $errors['min_items'] = 'Set a minimum spend above the amount off, so no order is free. For ETB '
                .number_format($out['amount_off_santim'] / 100).' off, for example ETB '.number_format($out['amount_off_santim'] * 5 / 100).'.';
        }
        if ($percent && $out['max_discount_santim'] !== null && $out['max_discount_santim'] < 100) {
            $errors['max_discount'] = 'The most off must be at least 1 birr, or leave it empty for no limit.';
        }
        if ($out['starts_at'] && $out['ends_at'] && $out['ends_at']->lt($out['starts_at'])) {
            $errors['ends_on'] = 'The last day cannot be before the first day.';
        }
        // A new end date must not be in the past; an old one may be saved again as it was.
        $savedEnd = $coupon?->ends_at?->copy()->timezone($tz)->toDateString();
        if (filled($data['ends_on'] ?? null) && $data['ends_on'] < now($tz)->toDateString() && $data['ends_on'] !== $savedEnd) {
            $errors['ends_on'] = 'The last day cannot be in the past.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
