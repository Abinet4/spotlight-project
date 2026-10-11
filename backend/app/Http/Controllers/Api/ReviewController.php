<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /**
     * Public: visible reviews of a visible listing, newest first.
     */
    public function forProduct(Request $request, string $slug): AnonymousResourceCollection
    {
        $product = Product::visible()->where('slug', $slug)->firstOrFail();

        return $this->page(Review::visible()->where('product_id', $product->id), $product->rating_count, $product->rating_sum);
    }

    /**
     * Public: visible reviews across a visible store.
     */
    public function forSeller(Request $request, string $slug): AnonymousResourceCollection
    {
        $seller = SellerProfile::publiclyVisible()->where('slug', $slug)->firstOrFail();

        return $this->page(Review::visible()->where('seller_profile_id', $seller->id)->with('product:id,name,slug'), $seller->rating_count, $seller->rating_sum);
    }

    public function store(Request $request, int $orderId, int $itemId): JsonResponse
    {
        $data = $this->validated($request);
        $review = $this->reviews->create($request->user(), $orderId, $itemId, $data['rating'], $data['body']);

        return (new ReviewResource($review->load('customer')))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): ReviewResource
    {
        $data = $this->validated($request);

        return new ReviewResource($this->reviews->update($request->user(), $id, $data['rating'], $data['body'])->load('customer'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        return ['rating' => (int) $data['rating'], 'body' => isset($data['body']) ? (trim($data['body']) ?: null) : null];
    }

    private function page($query, int $count, int $sum): AnonymousResourceCollection
    {
        $breakdown = (clone $query)->toBase()->selectRaw('rating, count(*) as c')->groupBy('rating')->pluck('c', 'rating');
        $page = $query->with('customer:id,name')->orderByDesc('id')->paginate(10);

        return ReviewResource::collection($page)->additional(['summary' => [
            'count' => $count,
            'average' => $count ? round($sum / $count, 1) : null,
            'breakdown' => collect(range(5, 1))->mapWithKeys(fn ($r) => [$r => (int) ($breakdown[$r] ?? 0)]),
        ]]);
    }
}
