<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Support\MapPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AddressController extends Controller
{
    public const MAX_ADDRESSES = 10;

    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection(
            $request->user()->addresses()->with('area')->orderByDesc('is_default')->orderBy('id')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        $address = DB::transaction(function () use ($user, $data, $request) {
            // Lock the user's rows so concurrent requests cannot exceed the limit or create two defaults.
            $count = $user->addresses()->lockForUpdate()->count();
            if ($count >= self::MAX_ADDRESSES) {
                throw ValidationException::withMessages(['address_line' => 'You can save up to '.self::MAX_ADDRESSES.' addresses. Remove one first.']);
            }

            $address = new Address($data);
            $address->user_id = $user->id;
            $address->is_default = $count === 0 || $request->boolean('is_default');
            if ($address->is_default) {
                $user->addresses()->update(['is_default' => false]);
            }
            $address->save();

            return $address;
        });

        return (new AddressResource($address->load('area')))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): AddressResource
    {
        $address = $request->user()->addresses()->findOrFail($id);
        $address->fill($this->validated($request, partial: true))->save();

        if ($request->boolean('is_default')) {
            $this->makeDefault($request, $address);
        }

        return new AddressResource($address->fresh('area'));
    }

    public function setDefault(Request $request, int $id): AddressResource
    {
        $address = $request->user()->addresses()->findOrFail($id);
        $this->makeDefault($request, $address);

        return new AddressResource($address->fresh('area'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            $address = $request->user()->addresses()->lockForUpdate()->findOrFail($id);
            $address->delete();

            // Keep exactly one default while any address remains.
            if ($address->is_default) {
                $request->user()->addresses()->orderBy('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });

        return response()->json(['message' => 'Address removed.']);
    }

    private function makeDefault(Request $request, Address $address): void
    {
        DB::transaction(function () use ($request, $address) {
            $request->user()->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            $address->forceFill(['is_default' => true])->save();
        });
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return collect(validator($request->all(), [
            ...MapPin::rules(),
            'label' => ['nullable', 'string', 'max:40'],
            'recipient_name' => [$required, 'string', 'min:2', 'max:100'],
            'phone' => [$required, 'string', 'regex:/^\+?[0-9 ]{9,16}$/'],
            'area_id' => [$required, 'integer', Rule::exists('areas', 'id')->where('is_active', true)],
            'address_line' => [$required, 'string', 'min:3', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            // 0 is the ground floor. A lift only matters above it.
            'floor' => ['nullable', 'integer', 'min:0', 'max:60'],
            // Asked whenever a floor above the ground is given: it decides the carrying fee.
            'has_lift' => [Rule::requiredIf(fn () => (int) $request->input('floor') > 0), 'nullable', 'boolean'],
            'door_width_cm' => ['nullable', 'integer', 'min:40', 'max:400'],
            'stair_width_cm' => ['nullable', 'integer', 'min:40', 'max:400'],
            'lift_door_width_cm' => ['nullable', 'integer', 'min:40', 'max:400'],
            'is_default' => ['sometimes', 'boolean'],
        ], [
            'has_lift.required' => 'Say whether the building has a lift.',
        ], [
            ...MapPin::attributes(),
            'area_id' => 'sub-city',
            'address_line' => 'address',
            'door_width_cm' => 'front door width',
            'stair_width_cm' => 'stair width',
            'lift_door_width_cm' => 'lift door width',
        ])->after(MapPin::checkArea(...))->validate())->except('is_default')->all();
    }
}
