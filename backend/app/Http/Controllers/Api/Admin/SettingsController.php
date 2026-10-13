<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Payments\ChapaGateway;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use App\Services\AuditLogger;
use App\Sms\SmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->current(), 'meta' => $this->meta()]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $max = config('marketplace.commission.max_rate_percent');
        $data = $request->validate([
            'commission_rate_percent' => ['sometimes', 'numeric', 'min:0', "max:$max", 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'cod_enabled' => ['sometimes', 'boolean'],
            'online_payments_enabled' => ['sometimes', 'boolean'],
            'commission_credit_limit_birr' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'settlement_instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sms_enabled' => ['sometimes', 'boolean'],
            'delivery_base_fee_birr' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'delivery_per_km_birr' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'delivery_max_km' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'delivery_per_floor_birr' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'stores_set_delivery_fee' => ['sometimes', 'boolean'],
        ], [], [
            'delivery_base_fee_birr' => 'base fee',
            'delivery_per_km_birr' => 'per km fee',
            'delivery_max_km' => 'longest trip',
            'delivery_per_floor_birr' => 'per floor fee',
        ]);

        DB::transaction(function () use ($data, $request, $audit) {
            $before = $this->current();
            foreach ($data as $key => $value) {
                $stored = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);
                Setting::updateOrCreate(['key' => $key], ['value' => $stored, 'updated_by' => $request->user()->id]);
            }
            $audit->log('settings.updated', null, ['before' => $before, 'after' => $this->current()]);
        });

        return response()->json(['data' => $this->current(), 'meta' => $this->meta()]);
    }

    /** Read-only facts shown next to the settings. */
    private function meta(): array
    {
        $gateway = app(SmsGateway::class);
        $payments = app(PaymentGateway::class);

        return [
            'sms_driver' => $gateway->name(),
            'sms_delivers' => $gateway->delivers(),
            'payment_gateway' => $payments->name(),
            'payment_gateway_ready' => $payments->ready(),
            'payment_test_mode' => $payments instanceof FakeGateway || ($payments instanceof ChapaGateway && $payments->testMode()),
            'payment_window_minutes' => max(5, (int) config('marketplace.payments.window_minutes')),
            'maps_browser_key_set' => (bool) config('services.google_maps.browser_key'),
            'maps_server_key_set' => (bool) config('services.google_maps.server_key'),
            'listings_with_own_fee' => Product::whereNotNull('delivery_fee_santim')->where('delivery_fee_santim', '>', 0)->count(),
            'stores_without_pin' => SellerProfile::where('status', 'approved')->where('offers_delivery', true)->whereNull('latitude')->count(),
        ];
    }

    private function current(): array
    {
        return [
            'commission_rate_percent' => Setting::commissionRateBp() / 100,
            'cod_enabled' => Setting::codEnabled(),
            'online_payments_enabled' => Setting::onlinePaymentsEnabled(),
            'commission_credit_limit_birr' => intdiv(Setting::creditLimitSantim(), 100),
            'settlement_instructions' => Setting::settlementInstructions(),
            'sms_enabled' => Setting::smsEnabled(),
            'delivery_base_fee_birr' => intdiv(Setting::deliveryBaseFeeSantim(), 100),
            'delivery_per_km_birr' => intdiv(Setting::deliveryPerKmSantim(), 100),
            'delivery_max_km' => Setting::deliveryMaxKm(),
            'delivery_per_floor_birr' => intdiv(Setting::deliveryPerFloorSantim(), 100),
            'stores_set_delivery_fee' => Setting::storesSetDeliveryFee(),
        ];
    }
}
