<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BusinessType;
use App\Enums\SellerStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SellerProfileResource;
use App\Models\SellerProfile;
use App\Services\Alerts;
use App\Services\AuditLogger;
use App\Support\VatDetails;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SellerController extends Controller
{
    /**
     * Allowed review transitions: action => [from statuses, to status, reason required].
     */
    private const TRANSITIONS = [
        'approve' => [[SellerStatus::Pending, SellerStatus::Rejected], SellerStatus::Approved, false],
        'reject' => [[SellerStatus::Pending], SellerStatus::Rejected, true],
        'suspend' => [[SellerStatus::Approved], SellerStatus::Suspended, true],
        'reinstate' => [[SellerStatus::Suspended], SellerStatus::Approved, false],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SellerStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $sellers = SellerProfile::query()
            ->with(['user:id,name,email', 'city', 'area'])
            ->withCount('products')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $q->where(fn ($q) => $q
                    ->whereRaw("LOWER(store_name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(phone) LIKE ? ESCAPE '!'", [$like]));
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return SellerProfileResource::collection($sellers);
    }

    public function show(SellerProfile $seller): SellerProfileResource
    {
        return new SellerProfileResource($seller->load(['user:id,name,email', 'city', 'area'])->loadCount('products'));
    }

    /**
     * The store's checked identity: name, TIN and kind of business. Stores cannot change these
     * themselves after approval, so corrections and renames come through here, and are logged.
     */
    public function update(Request $request, SellerProfile $seller, AuditLogger $audit): SellerProfileResource
    {
        $data = $request->validate([
            'store_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'tin_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'business_type' => ['sometimes', Rule::enum(BusinessType::class)],
            ...VatDetails::rules(),
        ], [], ['tin_number' => 'TIN number', 'business_type' => 'kind of business', 'vat_number' => 'VAT number']);

        $data = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        if (array_key_exists('store_name', $data) && $data['store_name'] === null) {
            throw ValidationException::withMessages(['store_name' => 'Enter the store name.']);
        }
        $registered = (bool) ($data['vat_registered'] ?? $seller->vat_registered);
        VatDetails::check($registered, array_key_exists('vat_number', $data) ? $data['vat_number'] : $seller->vat_number,
            array_key_exists('tin_number', $data) ? $data['tin_number'] : $seller->tin_number);
        if (! $registered && $seller->vat_number !== null) {
            $data['vat_number'] = null;
        }

        DB::transaction(function () use ($seller, $data, $audit) {
            $locked = SellerProfile::whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $before = collect($data)->keys()->mapWithKeys(fn ($k) => [$k => $locked->{$k} instanceof \BackedEnum ? $locked->{$k}->value : $locked->{$k}])->all();
            // The storefront slug stays fixed so shared links keep working after a rename.
            $locked->fill($data);
            if (! $locked->isDirty()) {
                return;
            }
            $locked->save();
            $after = collect($data)->keys()->mapWithKeys(fn ($k) => [$k => $locked->{$k} instanceof \BackedEnum ? $locked->{$k}->value : $locked->{$k}])->all();
            $audit->log('seller.details_changed', $locked, ['before' => $before, 'after' => $after]);
        });

        return new SellerProfileResource($seller->refresh()->load(['user:id,name,email', 'city', 'area'])->loadCount('products'));
    }

    public function review(Request $request, SellerProfile $seller, string $action, AuditLogger $audit, Alerts $alerts): SellerProfileResource
    {
        abort_unless(isset(self::TRANSITIONS[$action]), 404);
        [$from, $to, $reasonRequired] = self::TRANSITIONS[$action];

        $data = $request->validate([
            'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($seller, $from, $to, $action, $data, $request, $audit, $alerts) {
            // Re-read under lock so two admins acting at once cannot apply conflicting transitions.
            $locked = SellerProfile::whereKey($seller->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot {$action} a seller whose status is {$locked->status->value}.",
                ]);
            }

            $previous = $locked->status;
            $locked->status = $to;
            $locked->status_reason = $data['reason'] ?? null;
            $locked->reviewed_at = now();
            $locked->reviewed_by = $request->user()->id;
            $locked->save();

            $audit->log("seller.{$action}", $locked, [
                'from' => $previous->value,
                'to' => $to->value,
                'reason' => $data['reason'] ?? null,
            ]);
            $alerts->sellerReviewed($locked, $action, $data['reason'] ?? null);

            $seller->setRawAttributes($locked->getAttributes(), true);
        });

        return new SellerProfileResource($seller->load(['user:id,name,email', 'city', 'area'])->loadCount('products'));
    }
}
