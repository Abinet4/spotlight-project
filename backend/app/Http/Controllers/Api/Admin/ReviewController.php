<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'hidden' => ['nullable', 'boolean'],
            'max_rating' => ['nullable', 'integer', 'between:1,5'],
        ]);

        $reviews = Review::query()
            ->when(isset($data['hidden']), fn ($q) => $q->where('is_hidden', filter_var($data['hidden'], FILTER_VALIDATE_BOOL)))
            ->when($data['max_rating'] ?? null, fn ($q, $r) => $q->where('rating', '<=', $r))
            ->with(['customer:id,name,email', 'product:id,name,slug', 'orderItem:id,name', 'seller:id,store_name,slug', 'order:id,number'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return ReviewResource::collection($reviews);
    }

    public function hide(Request $request, int $id): ReviewResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->fresh($this->reviews->setHidden($id, true, trim($data['reason'])));
    }

    public function restore(int $id): ReviewResource
    {
        return $this->fresh($this->reviews->setHidden($id, false, null));
    }

    private function fresh(Review $review): ReviewResource
    {
        return new ReviewResource($review->load(['customer:id,name,email', 'product:id,name,slug', 'orderItem:id,name', 'seller:id,store_name,slug', 'order:id,number']));
    }
}
