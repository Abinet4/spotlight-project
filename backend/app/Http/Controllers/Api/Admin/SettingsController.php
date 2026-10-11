<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->current()]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $max = config('marketplace.commission.max_rate_percent');
        $data = $request->validate([
            'commission_rate_percent' => ['sometimes', 'numeric', 'min:0', "max:$max", 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'cod_enabled' => ['sometimes', 'boolean'],
            'commission_credit_limit_birr' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'settlement_instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($data, $request, $audit) {
            $before = $this->current();
            foreach ($data as $key => $value) {
                $stored = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);
                Setting::updateOrCreate(['key' => $key], ['value' => $stored, 'updated_by' => $request->user()->id]);
            }
            $audit->log('settings.updated', null, ['before' => $before, 'after' => $this->current()]);
        });

        return response()->json(['data' => $this->current()]);
    }

    private function current(): array
    {
        return [
            'commission_rate_percent' => Setting::commissionRateBp() / 100,
            'cod_enabled' => Setting::codEnabled(),
            'commission_credit_limit_birr' => intdiv(Setting::creditLimitSantim(), 100),
            'settlement_instructions' => Setting::settlementInstructions(),
        ];
    }
}
