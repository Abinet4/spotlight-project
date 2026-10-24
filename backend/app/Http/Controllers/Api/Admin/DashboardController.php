<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Enums\SellerStatus;
use App\Enums\SupportStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Review;
use App\Models\SellerLedgerEntry;
use App\Models\SellerProfile;
use App\Models\SellerRequest;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AuditPresenter;
use App\Support\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $sellers = SellerProfile::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $products = Product::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $orders = Order::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json(['data' => [
            'customers' => User::where('role', Role::Customer->value)->count(),
            'sellers' => collect(SellerStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($sellers[$s->value] ?? 0)]),
            'products' => collect(ProductStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($products[$s->value] ?? 0)]),
            'orders' => collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($orders[$s->value] ?? 0)]),
            // Money is reported only for delivered orders. Gross sales are what customers paid
            // sellers; commission is the platform's revenue; neither is profit.
            'money' => [
                'delivered_gross_santim' => (int) Order::where('status', OrderStatus::Delivered->value)->sum('total_santim'),
                'delivered_items_santim' => (int) Order::where('status', OrderStatus::Delivered->value)->sum('items_total_santim'),
                'commission_earned_santim' => (int) Order::where('status', OrderStatus::Delivered->value)->sum('commission_santim'),
                'open_orders_value_santim' => (int) Order::whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value, OrderStatus::AwaitingPayment->value])->sum('total_santim'),
                'commission_rate_percent' => Setting::commissionRateBp() / 100,
                // What sellers still owe on cash-on-delivery sales (credits on other stores do not offset it).
                'commission_outstanding_santim' => (int) DB::query()
                    ->fromSub(SellerLedgerEntry::query()->selectRaw('seller_profile_id, sum(amount_santim) as b')->groupBy('seller_profile_id'), 'x')
                    ->where('b', '>', 0)->sum('b'),
            ],
            // Refunds an admin still has to send (online orders cancelled after payment).
            'refunds_due' => Refund::where('status', 'due')->count(),
            'reviews_hidden' => Review::where('is_hidden', true)->count(),
            'reviews_low' => Review::visible()->where('rating', '<=', 2)->where('created_at', '>=', now()->subDays(30))->count(),
            'support' => [
                'needs_reply' => SupportTicket::needsReply()->count(),
                'escalated' => SupportTicket::whereIn('status', SupportStatus::activeValues())->whereNotNull('escalated_at')->count(),
                'store_answers' => SellerRequest::where('status', SellerRequest::STATUS_ANSWERED)->count(),
            ],
        ]]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $logs = $this->auditQuery($request)->paginate(50)->withQueryString();
        $subjects = AuditPresenter::subjects($logs->getCollection());

        return response()->json(array_merge($logs->through(fn (AuditLog $log) => [
            ...$log->toArray(),
            'action_label' => AuditPresenter::label($log->action),
            'subject_label' => $log->subject_type ? ($subjects[$log->subject_type.':'.$log->subject_id] ?? null) : null,
            'details' => AuditPresenter::details($log->meta),
        ])->toArray(), ['groups' => AuditPresenter::groups()]));
    }

    /** Every audit row the page's filters match, oldest first, with each field in its own column. */
    public function exportAuditLogs(Request $request, AuditLogger $audit): StreamedResponse
    {
        $query = $this->auditQuery($request);
        // Rows up to the moment of the click, so the file never lists its own download.
        $query->where('id', '<=', (int) AuditLog::max('id'));
        $format = Spreadsheet::formatOf($request);
        $tz = config('marketplace.timezone');
        $audit->log('audit.exported', null, ['format' => $format, ...array_filter($request->only(['action', 'user_id', 'q', 'from', 'to']))]);

        return Spreadsheet::download($format, 'audit-log-'.now($tz)->format('Y-m-d-His'), function (callable $put) use ($query, $tz) {
            $put(['id', 'date_time', 'who', 'who_email', 'who_role', 'action', 'action_code', 'subject', 'subject_type', 'subject_id', 'details', 'details_full', 'ip_address']);
            $query->reorder()->chunkById(500, function ($logs) use ($put, $tz) {
                $subjects = AuditPresenter::subjects($logs);
                foreach ($logs as $log) {
                    $put([
                        $log->id,
                        $log->created_at?->setTimezone($tz)->format('Y-m-d H:i:s'),
                        ReportController::cell($log->user?->name),
                        ReportController::cell($log->user?->email),
                        $log->user?->role?->value,
                        AuditPresenter::label($log->action),
                        $log->action,
                        ReportController::cell($log->subject_type ? ($subjects[$log->subject_type.':'.$log->subject_id] ?? null) : null),
                        AuditPresenter::typeName($log->subject_type),
                        $log->subject_id,
                        ReportController::cell(AuditPresenter::details($log->meta)),
                        ReportController::cell($log->meta ? json_encode($log->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null),
                        $log->ip_address,
                    ]);
                }
            });
        });
    }

    private function auditQuery(Request $request): Builder
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tz = config('marketplace.timezone');

        return AuditLog::with('user:id,name,email,role')
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', str_replace('%', '', $a).'%'))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            // A "_" in the search matches any one character, which only ever finds a few more rows.
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('user', fn ($u) => $u
                ->where('email', 'like', '%'.str_replace('%', '', $term).'%')
                ->orWhere('name', 'like', '%'.str_replace('%', '', $term).'%')))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d, $tz)->endOfDay()->utc()))
            ->latest('id');
    }
}
