<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A seller reads their store's reviews (hidden ones excluded) and can reply publicly to each.
 */
class ReviewController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['unanswered' => ['nullable', 'boolean']]);
        $seller = $request->user()->sellerProfile()->firstOrFail();

        $reviews = Review::visible()->where('seller_profile_id', $seller->id)
            ->when(filter_var($data['unanswered'] ?? false, FILTER_VALIDATE_BOOL), fn ($q) => $q->whereNull('seller_reply'))
            ->with(['customer:id,name', 'product:id,name,slug', 'orderItem:id,name'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return ReviewResource::collection($reviews)->additional(['summary' => [
            'count' => $seller->rating_count,
            'average' => $seller->rating_count ? round($seller->rating_sum / $seller->rating_count, 1) : null,
        ]]);
    }

    public function reply(Request $request, int $id, ReviewService $reviews): ReviewResource
    {
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:1000']]);
        $seller = $request->user()->sellerProfile()->firstOrFail();
        $reply = isset($data['reply']) ? (trim($data['reply']) ?: null) : null;

        return new ReviewResource($reviews->reply($seller, $id, $reply)->load(['customer:id,name', 'product:id,name,slug', 'orderItem:id,name']));
    }
}
