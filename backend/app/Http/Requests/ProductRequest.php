<?php

namespace App\Http\Requests;

use App\Enums\ProductCondition;
use App\Enums\StockType;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for seller product create (POST) and update (PUT/PATCH).
 * Prices arrive in birr ("1500" or "1500.50") and are converted to santim here.
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Ownership and seller status are enforced in the controller.
    }

    private function isUpdate(): bool
    {
        return in_array($this->method(), ['PUT', 'PATCH'], true);
    }

    public function rules(): array
    {
        $required = $this->isUpdate() ? 'sometimes' : 'required';
        $money = 'regex:/^\d{1,10}(\.\d{1,2})?$/';
        $imageRule = ['image', 'mimes:'.implode(',', config('marketplace.products.image_mimes')),
            'max:'.config('marketplace.products.max_image_kb'), 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000'];

        return [
            'category_id' => [$required, 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'name' => [$required, 'string', 'min:3', 'max:160'],
            'description' => [$required, 'string', 'min:10', 'max:5000'],
            'price' => [$required, 'string', $money],
            'condition' => [$required, Rule::enum(ProductCondition::class)],
            // Betoch sells finished furniture only: every listing is in stock with a count.
            'stock_type' => ['sometimes', Rule::in([StockType::InStock->value])],
            'stock_quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            // The stock shown when the form was opened; a mismatch on save means an order came in meanwhile.
            'stock_expected' => ['nullable', 'integer', 'min:0'],
            'width_cm' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'depth_cm' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'height_cm' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'material' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:60'],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')->where('is_active', true)],
            'offers_delivery' => ['sometimes', 'boolean'],
            'pickup_available' => ['sometimes', 'boolean'],
            'delivery_fee' => ['nullable', 'string', $money],
            'delivery_notes' => ['nullable', 'string', 'max:500'],
            'action' => ['sometimes', Rule::in(['draft', 'submit'])],
            'images' => ['sometimes', 'array', 'max:'.config('marketplace.products.max_images')],
            'images.*' => $imageRule,
        ];
    }

    public function messages(): array
    {
        return [
            'price.regex' => 'Enter the price in birr, for example 12500 or 12500.50.',
            'stock_type.in' => 'Betoch sells finished furniture only, so a listing must be in stock.',
            'delivery_fee.regex' => 'Enter the delivery fee in birr, for example 300 or 300.50.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('stock_quantity') === null && ! $this->isUpdate()) {
                $validator->errors()->add('stock_quantity', 'Enter how many units are in stock.');
            }

            if ($this->has('price') && Money::toSantim($this->input('price')) < 100) {
                $validator->errors()->add('price', 'The price must be at least 1 birr.');
            }

            // While admins price delivery, a store may only offer it free (0) or leave it to the marketplace.
            $fee = $this->input('delivery_fee');
            if ($fee !== null && $fee !== '' && Money::toSantim($fee) > 0 && ! Setting::storesSetDeliveryFee()) {
                $validator->errors()->add('delivery_fee', 'The marketplace sets delivery prices. Leave this empty, or offer free delivery.');
            }

            $delivery = $this->boolean('offers_delivery', true);
            $pickup = $this->boolean('pickup_available', false);
            if (! $this->isUpdate() && ! $delivery && ! $pickup) {
                $validator->errors()->add('offers_delivery', 'Offer delivery, pickup, or both.');
            }
        }];
    }

    /**
     * Validated product attributes ready for the model (money converted to santim).
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $data = collect($this->validated())->except(['price', 'delivery_fee', 'action', 'images', 'stock_expected'])->all();

        if ($this->has('price')) {
            $data['price_santim'] = Money::toSantim($this->input('price'));
        }
        if ($this->exists('delivery_fee')) {
            $fee = $this->input('delivery_fee');
            $data['delivery_fee_santim'] = ($fee === null || $fee === '') ? null : Money::toSantim($fee);
        }

        return $data;
    }
}
