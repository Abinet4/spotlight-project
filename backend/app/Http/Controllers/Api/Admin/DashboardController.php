<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Enums\SellerStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $sellers = SellerProfile::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $products = Product::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json(['data' => [
            'customers' => User::where('role', Role::Customer->value)->count(),
            'sellers' => collect(SellerStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($sellers[$s->value] ?? 0)]),
            'products' => collect(ProductStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($products[$s->value] ?? 0)]),
            // Orders, GMV, platform revenue and payouts arrive with the checkout milestone.
        ]]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $logs = AuditLog::with('user:id,name,email')
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], '', $a).'%'))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return response()->json($logs);
    }
}
