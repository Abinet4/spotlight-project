<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\DeliveryZone;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryZoneController extends Controller
{
    public function index(): JsonResponse
    {
        $zones = DeliveryZone::with('areas:id,name,delivery_zone_id')->orderBy('name')->get();
        $areas = Area::with('city:id,name')->orderBy('name')->get(['id', 'name', 'city_id', 'delivery_zone_id', 'is_active']);

        return response()->json(['data' => ['zones' => $zones, 'areas' => $areas]]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $zone = DB::transaction(function () use ($request, $audit) {
            $data = $this->validated($request);
            $zone = DeliveryZone::create(collect($data)->except('area_ids')->all());
            $this->assignAreas($zone, $data['area_ids'] ?? []);
            $audit->log('delivery_zone.created', $zone, ['fee_santim' => $zone->fee_santim, 'area_ids' => $data['area_ids'] ?? []]);

            return $zone;
        });

        return response()->json(['data' => $zone->load('areas:id,name,delivery_zone_id')], 201);
    }

    public function update(Request $request, DeliveryZone $zone, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $zone, $audit) {
            $data = $this->validated($request, $zone);
            $zone->fill(collect($data)->except('area_ids')->all());
            $changed = $zone->getDirty();
            $zone->save();
            if (array_key_exists('area_ids', $data)) {
                $this->assignAreas($zone, $data['area_ids'] ?? []);
            }
            $audit->log('delivery_zone.updated', $zone, ['changed' => $changed, 'area_ids' => $data['area_ids'] ?? null]);
        });

        return response()->json(['data' => $zone->fresh('areas:id,name,delivery_zone_id')]);
    }

    public function destroy(DeliveryZone $zone, AuditLogger $audit): JsonResponse
    {
        // Areas fall back to "seller quotes delivery" via nullOnDelete.
        $audit->log('delivery_zone.deleted', $zone, ['name' => $zone->name]);
        $zone->delete();

        return response()->json(['message' => 'Delivery zone deleted.']);
    }

    /**
     * Set exactly this zone's areas: listed areas move here, unlisted former members are released.
     *
     * @param  list<int>  $areaIds
     */
    private function assignAreas(DeliveryZone $zone, array $areaIds): void
    {
        Area::where('delivery_zone_id', $zone->id)->whereNotIn('id', $areaIds ?: [0])->update(['delivery_zone_id' => null]);
        if ($areaIds) {
            Area::whereIn('id', $areaIds)->update(['delivery_zone_id' => $zone->id]);
        }
    }

    private function validated(Request $request, ?DeliveryZone $zone = null): array
    {
        $required = $zone ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:100', Rule::unique('delivery_zones', 'name')->ignore($zone?->id)],
            'fee' => [$required, 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'min_days' => [$required, 'integer', 'min:0', 'max:60'],
            'max_days' => [$required, 'integer', 'min:0', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'area_ids' => ['sometimes', 'array'],
            'area_ids.*' => ['integer', 'distinct', 'exists:areas,id'],
        ]);

        $min = $data['min_days'] ?? $zone?->min_days;
        $max = $data['max_days'] ?? $zone?->max_days;
        if ($min !== null && $max !== null && $min > $max) {
            throw ValidationException::withMessages(['max_days' => 'Maximum days must be at least the minimum days.']);
        }

        if (isset($data['fee'])) {
            $data['fee_santim'] = Money::toSantim($data['fee']);
        }
        unset($data['fee']);

        return $data;
    }
}
