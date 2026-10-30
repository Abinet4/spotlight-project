<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SellerLedgerEntry;
use App\Services\AuditLogger;
use App\Support\Dates;
use App\Support\Spreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales report for a date range in Addis Ababa time. Gross sales, commission and cash collected
 * are reported separately; none of them is profit.
 */
class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        [$from, $to, $start, $end] = $this->range($request);

        $delivered = Order::where('status', OrderStatus::Delivered->value)->whereBetween('delivered_at', [$start, $end]);
        // Online orders that were never paid are not sales; they are counted separately.
        $placed = Order::visibleToSeller()->whereBetween('created_at', [$start, $end]);
        $tz = config('marketplace.timezone');

        // Daily series in PHP: day boundaries follow Addis Ababa time on every database engine.
        $days = [];
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'orders' => 0, 'gross_santim' => 0, 'commission_santim' => 0];
        }
        foreach ((clone $delivered)->select(['delivered_at', 'total_santim', 'commission_santim'])->cursor() as $o) {
            $key = $o->delivered_at->setTimezone($tz)->toDateString();
            $days[$key]['orders']++;
            $days[$key]['gross_santim'] += $o->total_santim;
            $days[$key]['commission_santim'] += $o->commission_santim;
        }

        $topSellers = (clone $delivered)
            ->selectRaw('seller_profile_id, count(*) as orders, sum(total_santim) as gross, sum(commission_santim) as commission')
            ->groupBy('seller_profile_id')->orderByDesc('gross')->limit(10)
            ->with('seller:id,store_name,slug')->get()
            ->map(fn ($r) => ['store_name' => $r->seller?->store_name, 'slug' => $r->seller?->slug, 'orders' => (int) $r->orders, 'gross_santim' => (int) $r->gross, 'commission_santim' => (int) $r->commission]);

        $cancelled = Order::visibleToSeller()->where('status', OrderStatus::Cancelled->value)->whereBetween('cancelled_at', [$start, $end])
            ->selectRaw('cancelled_by, count(*) as c')->groupBy('cancelled_by')->pluck('c', 'cancelled_by');
        $unpaid = Order::where('payment_method', 'online')->whereNull('paid_at')->where('status', OrderStatus::Cancelled->value)
            ->whereBetween('cancelled_at', [$start, $end])->count();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'timezone' => $tz,
            'placed' => ['orders' => (clone $placed)->count(), 'value_santim' => (int) (clone $placed)->sum('total_santim')],
            'delivered' => [
                'orders' => (clone $delivered)->count(),
                'gross_santim' => (int) (clone $delivered)->sum('total_santim'),
                'items_santim' => (int) (clone $delivered)->sum('items_total_santim'),
                'delivery_fees_santim' => (int) (clone $delivered)->sum('delivery_fee_santim'),
                'commission_santim' => (int) (clone $delivered)->sum('commission_santim'),
                // Taken off what customers paid; Betoch pays it to the stores.
                'coupons_santim' => (int) (clone $delivered)->sum('coupon_discount_santim'),
            ],
            'cancelled' => [
                'orders' => (int) $cancelled->sum(),
                'by_customer' => (int) ($cancelled['customer'] ?? 0),
                'by_seller' => (int) ($cancelled['seller'] ?? 0),
                'by_admin' => (int) ($cancelled['admin'] ?? 0),
            ],
            'online' => [
                'paid_santim' => (int) Payment::where('status', 'paid')->whereBetween('paid_at', [$start, $end])->sum('amount_santim'),
                'unpaid_cancelled_orders' => $unpaid,
                'refunds_due_santim' => (int) Refund::where('status', 'due')->sum('amount_santim'),
                'refunded_santim' => (int) Refund::where('status', 'refunded')->whereBetween('refunded_at', [$start, $end])->sum('amount_santim'),
                'paid_out_santim' => (int) SellerLedgerEntry::where('type', 'payout')->whereBetween('created_at', [$start, $end])->sum('amount_santim'),
            ],
            'commission_collected_santim' => -(int) SellerLedgerEntry::where('type', 'payment')->whereBetween('created_at', [$start, $end])->sum('amount_santim'),
            'top_sellers' => $topSellers,
            'days' => array_values($days),
        ]]);
    }

    /**
     * Orders placed in the range for spreadsheets and accounting, as an Excel workbook or CSV: every order
     * field, the delivery details, the items and any refund.
     */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        [$from, $to, $start, $end] = $this->range($request);
        $tz = config('marketplace.timezone');
        $format = Spreadsheet::formatOf($request);
        $audit->log('report.exported', null, ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'format' => $format]);
        $time = fn ($t) => $t?->setTimezone($tz)->format('Y-m-d H:i');

        // The first 20 columns keep their order from earlier exports; later ones were added after.
        $header = ['order', 'placed_at', 'status', 'store', 'customer', 'area', 'fulfillment', 'items_etb', 'delivery_etb', 'total_etb',
            'vat_rate_percent', 'vat_etb', 'store_vat_number', 'commission_rate_percent', 'commission_etb', 'payment_method', 'payment_status',
            'delivered_at', 'cancelled_at', 'cancelled_by',
            'customer_email', 'recipient', 'phone', 'address', 'landmark', 'floor', 'items', 'item_count', 'carry_fee_etb', 'distance_km',
            'paid_etb', 'paid_at', 'confirmed_at', 'customer_note', 'store_note', 'cancel_reason', 'refund_status', 'refund_etb', 'refund_reference',
            'coupon_code', 'coupon_etb'];

        return Spreadsheet::download($format, 'orders-'.$from->toDateString().'-to-'.$to->toDateString(), function (callable $row) use ($header, $start, $end, $time) {
            $row($header);
            Order::whereBetween('created_at', [$start, $end])
                ->with(['seller:id,store_name', 'customer:id,name,email', 'checkout', 'items:id,order_id,name,variant_label,quantity', 'refund'])
                ->orderBy('id')
                ->chunkById(500, function ($orders) use ($row, $time) {
                    foreach ($orders as $o) {
                        $c = $o->checkout;
                        $row([
                            $o->number,
                            $time($o->created_at),
                            $o->status->value,
                            self::cell($o->seller?->store_name),
                            self::cell($o->customer?->name),
                            self::cell($c?->area_name),
                            $o->fulfillment,
                            self::birr($o->items_total_santim),
                            $o->delivery_fee_santim === null ? '' : self::birr($o->delivery_fee_santim),
                            self::birr($o->total_santim),
                            number_format($o->vat_rate_bp / 100, 2, '.', ''),
                            self::birr($o->vatSantim()),
                            self::cell($o->vat_number),
                            number_format($o->commission_rate_bp / 100, 2, '.', ''),
                            self::birr($o->commission_santim),
                            $o->payment_method,
                            $o->payment_status,
                            $time($o->delivered_at),
                            $time($o->cancelled_at),
                            $o->cancelled_by,
                            self::cell($o->customer?->email),
                            self::cell($c?->recipient_name),
                            self::cell($c?->phone),
                            self::cell($c?->address_line),
                            self::cell($c?->landmark),
                            $c?->floor,
                            self::cell($o->items->map(fn ($i) => $i->quantity.' x '.$i->name.($i->variant_label ? ' ('.$i->variant_label.')' : ''))->implode('; ')),
                            (int) $o->items->sum('quantity'),
                            $o->carry_fee_santim ? self::birr((int) $o->carry_fee_santim) : '',
                            $o->delivery_distance_m === null ? '' : number_format($o->delivery_distance_m / 1000, 1, '.', ''),
                            $o->paid_santim ? self::birr((int) $o->paid_santim) : '',
                            $time($o->paid_at),
                            $time($o->confirmed_at),
                            self::cell($o->customer_note),
                            self::cell($o->seller_note),
                            self::cell($o->cancel_reason),
                            $o->refund?->status,
                            $o->refund ? self::birr((int) $o->refund->amount_santim) : '',
                            self::cell($o->refund?->reference),
                            self::cell($c?->coupon_code),
                            $o->coupon_discount_santim ? self::birr((int) $o->coupon_discount_santim) : '',
                        ]);
                    }
                });
        });
    }

    /**
     * Spreadsheet apps run cells starting with = + - @ as formulas; prefix them so names and notes stay text.
     * The first visible character decides (some apps skip leading spaces, and some read full-width signs).
     */
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value === '') {
            return $value;
        }
        $first = mb_substr(preg_replace('/^[\s\x{00A0}\x{3000}]+/u', '', $value) ?? ltrim($value), 0, 1);

        return in_array($value[0], ["\t", "\r", "\n"], true) || in_array($first, ['=', '+', '-', '@', '＝', '＋', '－', '＠'], true) ? "'".$value : $value;
    }

    private static function birr(int $santim): string
    {
        return number_format($santim / 100, 2, '.', '');
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable} local from/to dates and their UTC bounds
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            ...Dates::range($request),
        ]);
        $tz = config('marketplace.timezone');
        $to = isset($data['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfDay();
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay() : $to->subDays(29);
        if ($from->greaterThan($to)) {
            abort(response()->json(['message' => 'The start date must be on or before the end date.', 'errors' => ['from' => ['The start date must be on or before the end date.']]], 422));
        }
        if ($from->diffInDays($to) > 366) {
            abort(response()->json(['message' => 'Pick a range of one year or less.', 'errors' => ['from' => ['Pick a range of one year or less.']]], 422));
        }

        return [$from, $to, $from->utc(), $to->endOfDay()->utc()];
    }
}
