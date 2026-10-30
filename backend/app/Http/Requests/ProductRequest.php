<?php

namespace App\Http\Requests;

use App\Enums\ProductCondition;
use App\Enums\StockType;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
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
            // A sale: percent off the price (and every option's), until the end of the given day, or until taken off.
            'discount_percent' => ['nullable', 'integer', 'min:1', 'max:90'],
            'discount_ends_on' => ['nullable', 'date_format:Y-m-d'],
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
            'discount_percent.min' => 'A sale takes at least 1% off.',
            'discount_percent.max' => 'A sale can take up to 90% off.',
            'discount_percent.integer' => 'Enter the sale as a whole percent, for example 15.',
            'discount_ends_on.date_format' => 'Choose the last day of the sale.',
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

            $endsOn = $this->input('discount_ends_on');
            // A sale that has ended may be saved again as it was; a new end date must be today or later.
            if (filled($endsOn) && $endsOn < now(config('marketplace.timezone'))->toDateString() && $endsOn !== $this->savedEndsOn()) {
                $validator->errors()->add('discount_ends_on', 'The last day of the sale cannot be in the past.');
            }
            if (filled($endsOn) && $this->exists('discount_percent') && ! filled($this->input('discount_percent'))) {
                $validator->errors()->add('discount_percent', 'Enter the percent off, or clear the end date.');
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

    /** The last day of the sale saved on the listing being edited, in Addis Ababa. */
    private function savedEndsOn(): ?string
    {
        $saved = $this->isUpdate() ? Product::whereKey((int) $this->route('id'))->value('discount_ends_at') : null;

        return $saved ? Carbon::parse($saved, 'UTC')->timezone(config('marketplace.timezone'))->toDateString() : null;
    }

    /**
     * Validated product attributes ready for the model (money converted to santim).
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $data = collect($this->validated())->except(['price', 'delivery_fee', 'action', 'images', 'stock_expected', 'discount_percent', 'discount_ends_on'])->all();

        if ($this->has('price')) {
            $data['price_santim'] = Money::toSantim($this->input('price'));
        }
        if ($this->exists('delivery_fee')) {
            $fee = $this->input('delivery_fee');
            $data['delivery_fee_santim'] = ($fee === null || $fee === '') ? null : Money::toSantim($fee);
        }

        if ($this->exists('discount_percent')) {
            $data['discount_percent'] = filled($this->input('discount_percent')) ? (int) $this->input('discount_percent') : null;
            if ($data['discount_percent'] === null) {
                // Taking the sale off clears its end date too.
                $data['discount_ends_at'] = null;
            }
        }
        if ($this->exists('discount_ends_on') && ($data['discount_percent'] ?? true) !== null) {
            $endsOn = $this->input('discount_ends_on');
            // The sale runs to the end of that day in Addis Ababa.
            $data['discount_ends_at'] = filled($endsOn)
                ? Carbon::createFromFormat('Y-m-d', $endsOn, config('marketplace.timezone'))->endOfDay()->utc()
                : null;
        }

        return $data;
    }
}
