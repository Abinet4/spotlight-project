<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FavoriteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Products that were unpublished since being saved are hidden rather than shown broken.
        $products = $request->user()->favoriteProducts()
            ->visible()
            ->with(['primaryImage', 'category:id,name,slug', 'seller:id,store_name,slug'])
            ->orderByPivot('created_at', 'desc')
            ->paginate(24);

        $products->getCollection()->each(fn ($p) => $p->is_favorite = true);

        return ProductResource::collection($products);
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        abort_unless(Product::visible()->whereKey($product->id)->exists(), 404);

        $request->user()->favoriteProducts()->syncWithoutDetaching([$product->id]);

        return response()->json(['message' => 'Saved to your wishlist.', 'is_favorite' => true], 201);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $request->user()->favoriteProducts()->detach($product->id);

        return response()->json(['message' => 'Removed from your wishlist.', 'is_favorite' => false]);
    }
}
