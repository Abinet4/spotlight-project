<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SignInCode;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Find accounts, suspend or reactivate them, and reset two-step sign-in for an owner who lost their phone. Admin accounts are managed only from the command line.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
        ]);

        $users = User::query()
            ->with('sellerProfile:id,user_id,store_name,slug,status')
            ->when($filters['role'] ?? null, fn ($q, $r) => $q->where('role', $r))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($term))).'%';
                $q->where(fn ($q) => $q
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(phone) LIKE ? ESCAPE '!'", [$like]));
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $u) => $this->row($u)),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::with('sellerProfile:id,user_id,store_name,slug,status')->findOrFail($id);
        $orders = Order::where('customer_id', $user->id);

        return response()->json(['data' => [
            ...$this->row($user),
            'orders' => [
                'count' => (clone $orders)->count(),
                'delivered_count' => (clone $orders)->where('status', OrderStatus::Delivered->value)->count(),
                'cancelled_by_customer' => (clone $orders)->where('status', OrderStatus::Cancelled->value)->where('cancelled_by', 'customer')->count(),
                'delivered_spend_santim' => (int) (clone $orders)->where('status', OrderStatus::Delivered->value)->sum('total_santim'),
            ],
            'reviews_count' => DB::table('reviews')->where('customer_id', $user->id)->count(),
        ]]);
    }

    public function setStatus(Request $request, int $id, string $action, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => [$action === 'suspend' ? 'required' : 'nullable', 'string', 'min:3', 'max:500']]);

        $user = DB::transaction(function () use ($request, $id, $action, $data, $audit) {
            $user = User::lockForUpdate()->findOrFail($id);
            if ($user->role === Role::Admin) {
                throw ValidationException::withMessages(['status' => 'Admin accounts are managed from the command line.']);
            }
            $to = $action === 'suspend' ? UserStatus::Suspended : UserStatus::Active;
            if ($user->status === $to) {
                throw ValidationException::withMessages(['status' => "This account is already {$to->value}."]);
            }

            $user->status = $to;
            $user->save();
            if ($to === UserStatus::Suspended) {
                // Sign the account out everywhere; active tokens would otherwise keep working until they expire.
                $user->tokens()->delete();
            }
            $audit->log($to === UserStatus::Suspended ? 'user.suspended' : 'user.reactivated', $user, ['reason' => $data['reason'] ?? null, 'by' => $request->user()->id]);

            return $user;
        });

        return response()->json(['data' => $this->row($user->load('sellerProfile:id,user_id,store_name,slug,status'))]);
    }

    /**
     * For an owner who lost their phone: turn two-step sign-in off and forget the checked number, so
     * they can sign in with their password and check a new one. Administrators are reset from the
     * command line (marketplace:two-step-off).
     */
    public function resetTwoStep(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $user = DB::transaction(function () use ($request, $id, $data, $audit) {
            $user = User::lockForUpdate()->findOrFail($id);
            if ($user->role === Role::Admin) {
                throw ValidationException::withMessages(['two_step' => 'Admin accounts are managed from the command line.']);
            }
            if ($user->two_step_enabled_at === null && $user->phone_verified_at === null) {
                throw ValidationException::withMessages(['two_step' => 'This account has no two-step sign-in or checked phone to reset.']);
            }
            $user->forceFill(['two_step_enabled_at' => null, 'phone_verified_at' => null])->save();
            SignInCode::where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
            $audit->log('user.two_step_reset', $user, ['reason' => $data['reason'], 'by' => $request->user()->id]);
            Notification::route('mail', $user->email)->notify(new SecurityNotice('two_step_reset', $user->name));

            return $user;
        });

        return response()->json(['data' => $this->row($user->load('sellerProfile:id,user_id,store_name,slug,status'))]);
    }

    private function row(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role->value,
            'status' => $u->status->value,
            'phone_verified' => $u->phone_verified_at !== null,
            'two_step' => $u->usesTwoStep(),
            'store' => $u->sellerProfile ? ['id' => $u->sellerProfile->id, 'store_name' => $u->sellerProfile->store_name, 'slug' => $u->sellerProfile->slug, 'status' => $u->sellerProfile->status->value] : null,
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
