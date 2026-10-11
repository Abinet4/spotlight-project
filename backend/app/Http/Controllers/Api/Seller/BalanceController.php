<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use App\Models\SellerLedgerEntry;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The seller's commission balance: what they owe the platform on cash-on-delivery sales.
 */
class BalanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $sellerId = $request->user()->sellerProfile()->firstOrFail()->id;

        return response()->json(['data' => self::summary($sellerId)]);
    }

    public function entries(Request $request): AnonymousResourceCollection
    {
        $sellerId = $request->user()->sellerProfile()->firstOrFail()->id;

        return LedgerEntryResource::collection(
            SellerLedgerEntry::where('seller_profile_id', $sellerId)->with('order:id,number')->orderByDesc('id')->paginate(25)
        );
    }

    public static function summary(int $sellerId): array
    {
        $totals = SellerLedgerEntry::where('seller_profile_id', $sellerId)
            ->selectRaw('type, sum(amount_santim) as total')->groupBy('type')->pluck('total', 'type');
        $balance = (int) $totals->sum();
        $limit = Setting::creditLimitSantim();

        return [
            'balance_santim' => $balance,
            'commission_total_santim' => (int) ($totals['commission'] ?? 0),
            'paid_total_santim' => -(int) ($totals['payment'] ?? 0),
            'adjustments_total_santim' => (int) ($totals['adjustment'] ?? 0),
            'credit_limit_santim' => $limit ?: null,
            'over_limit' => $limit > 0 && $balance > $limit,
            'instructions' => Setting::settlementInstructions() ?: null,
        ];
    }
}
