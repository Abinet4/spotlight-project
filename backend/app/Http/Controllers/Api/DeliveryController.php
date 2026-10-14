<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Services\DeliveryEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryController extends Controller
{
    /**
     * Active zones with their areas, for the public delivery information page.
     */
    public function zones(): JsonResponse
    {
        $zones = DeliveryZone::where('is_active', true)
            ->with(['areas' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->orderBy('fee_santim')
            ->get()
            ->map(fn (DeliveryZone $z) => [
                'id' => $z->id,
                'name' => $z->name,
                'fee_santim' => $z->fee_santim,
                'min_days' => $z->min_days,
                'max_days' => $z->max_days,
                'notes' => $z->notes,
                'areas' => $z->areas->pluck('name'),
            ]);

        return response()->json(['data' => $zones]);
    }

    public function estimate(Request $request, string $slug, DeliveryEstimator $estimator): JsonResponse
    {
        $data = $request->validate([
            'area_id' => ['required_without:address_id', 'nullable', 'integer', Rule::exists('areas', 'id')->where('is_active', true)],
            // A saved address of the signed-in customer: its pin prices delivery by distance.
            'address_id' => ['nullable', 'integer'],
        ]);

        $product = Product::visible()->where('slug', $slug)->with('seller')->firstOrFail();
        $address = null;
        $areaId = $data['area_id'] ?? null;
        if (! empty($data['address_id'])) {
            $user = $request->user('sanctum');
            abort_unless($user, 401);
            $address = $user->addresses()->findOrFail($data['address_id']);
            $areaId = $address->area_id;
        }
        $area = Area::with('deliveryZone')->findOrFail($areaId);

        return response()->json(['data' => $estimator->estimate($product, $area, $address)]);
    }
}
