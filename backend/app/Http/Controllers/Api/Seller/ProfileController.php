<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\SellerStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SellerProfileResource;
use App\Models\Area;
use App\Support\MapPin;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): SellerProfileResource
    {
        $profile = $request->user()->sellerProfile()->with(['city', 'area'])->withCount('products')->firstOrFail();

        return new SellerProfileResource($profile);
    }

    public function update(Request $request): SellerProfileResource
    {
        $profile = $request->user()->sellerProfile()->firstOrFail();

        $data = validator($request->all(), [
            ...MapPin::rules(),
            'store_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['sometimes', 'string', 'regex:/^\+?[0-9 ]{9,16}$/'],
            'tin_number' => ['nullable', 'string', 'max:32'],
            'area_id' => ['sometimes', 'integer', Rule::exists('areas', 'id')->where('is_active', true)],
            'address_line' => ['nullable', 'string', 'max:255'],
            'offers_delivery' => ['sometimes', 'boolean'],
        ], [], MapPin::attributes())->after(MapPin::checkArea(...))->validate();

        // Once the marketplace has checked a store, its legal identity changes only through an admin
        // (Admin > Sellers), so a store cannot trade under a name or TIN nobody reviewed.
        if ($profile->status !== SellerStatus::Pending && $profile->status !== SellerStatus::Rejected) {
            $locked = [];
            foreach (['store_name' => 'store name', 'tin_number' => 'TIN number'] as $field => $label) {
                if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $profile->{$field}) {
                    $locked[$field] = "Your {$label} was checked when the store was approved. Ask the marketplace team to change it.";
                }
            }
            if ($locked) {
                throw ValidationException::withMessages($locked);
            }
        }

        if (isset($data['area_id'])) {
            $data['city_id'] = Area::findOrFail($data['area_id'])->city_id;
        }

        // The storefront slug stays fixed so shared links keep working after a rename.
        $profile->fill($data)->save();

        return new SellerProfileResource($profile->load(['city', 'area'])->loadCount('products'));
    }
}
