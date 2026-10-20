<?php

namespace App\Http\Controllers\Api;

use App\Enums\BusinessType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Area;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Models\SignInCode;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Services\Alerts;
use App\Services\AuditLogger;
use App\Services\SignInCodes;
use App\Support\PhoneNumber;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Whether new accounts must give a mobile number and type a code texted to it. */
    private static function phoneRequired(): bool
    {
        return Setting::everyoneMustUseTwoStep() && app(SignInCodes::class)->available();
    }

    private function accountRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => self::phoneRequired()
                ? ['required', 'string', 'max:30', function ($attribute, $value, $fail) {
                    if (! is_string($value) || PhoneNumber::ethiopianMobile($value) === null) {
                        $fail('Enter an Ethiopian mobile number, like 0911 234 567. We text you a code to check it.');
                    }
                }]
                : ['nullable', 'string', 'regex:/^\+?[0-9 ]{9,16}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * Emails are stored lowercase. Normalize before validation so the unique check
     * behaves the same on case-sensitive (SQLite) and case-insensitive (MySQL) collations.
     */
    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
    }

    public function register(Request $request, SignInCodes $codes): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate($this->accountRules());
        $user = User::create($data);

        return $this->afterSignUp($user, $codes);
    }

    /**
     * Seller application: creates a seller account with a pending storefront awaiting admin approval.
     */
    public function registerSeller(Request $request, AuditLogger $audit, SignInCodes $codes): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            ...$this->accountRules(),
            'store_name' => ['required', 'string', 'min:2', 'max:120'],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            'store_phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{9,16}$/'],
            'area_id' => ['required', 'integer', Rule::exists('areas', 'id')->where('is_active', true)],
            'address_line' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tin_number' => ['nullable', 'string', 'max:32'],
            'offers_custom_orders' => ['sometimes', 'boolean'],
            'offers_delivery' => ['sometimes', 'boolean'],
        ]);

        $user = DB::transaction(function () use ($data, $audit) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);
            $user->role = Role::Seller;
            $user->save();

            $area = Area::findOrFail($data['area_id']);

            $profile = new SellerProfile([
                'store_name' => $data['store_name'],
                'description' => $data['description'] ?? null,
                'business_type' => $data['business_type'],
                'phone' => $data['store_phone'],
                'tin_number' => $data['tin_number'] ?? null,
                'city_id' => $area->city_id,
                'area_id' => $area->id,
                'address_line' => $data['address_line'] ?? null,
                'offers_custom_orders' => $data['offers_custom_orders'] ?? false,
                'offers_delivery' => $data['offers_delivery'] ?? true,
            ]);
            $profile->slug = Slug::unique(SellerProfile::class, $data['store_name']);
            $user->sellerProfile()->save($profile);

            $audit->log('seller.applied', $profile, ['user_id' => $user->id]);
            app(Alerts::class)->sellerApplied($profile);

            return $user;
        });

        return $this->afterSignUp($user, $codes);
    }

    /** A new account signs in at once, or first types the code texted to the number it gave. */
    private function afterSignUp(User $user, SignInCodes $codes): JsonResponse
    {
        if ($user->mustUseTwoStep() && $codes->available()) {
            return $this->startTwoStep($user, $codes, $user->phone, 201);
        }

        return $this->tokenResponse($user, 201);
    }

    public function login(Request $request, SignInCodes $codes): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Hash even when the user does not exist so response time does not reveal registered emails.
        $passwordOk = Hash::check($data['password'], $user?->password ?? self::dummyHash());

        if (! $user || ! $passwordOk) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is suspended. Contact support.']);
        }

        if ($user->usesTwoStep() || $user->mustUseTwoStep()) {
            if ($codes->available()) {
                return $this->startTwoStep($user, $codes);
            }
            // A live site with no SMS provider: refusing would lock every such account out.
            Log::warning('Two-step sign-in skipped for user '.$user->id.': no SMS provider is set up.');
        }

        return $this->tokenResponse($user);
    }

    /**
     * The code step instead of a token. An account with no checked phone yet (new, or from before
     * the rule) gives a number first, unless the sign-up form already had one.
     */
    private function startTwoStep(User $user, SignInCodes $codes, ?string $phone = null, int $status = 200): JsonResponse
    {
        if ($user->twoStepPhone() !== null) {
            [$code, $challenge] = $codes->startSignIn($user);

            return response()->json(['two_step' => [...$this->pending($code), 'challenge' => $challenge]], $status);
        }

        [$code, $challenge] = $codes->startEnrollment($user);
        $error = null;
        if ($phone !== null) {
            try {
                $codes->sendTo($code, $phone);
            } catch (ValidationException $e) {
                // The account exists by now; the page shows why the text failed and offers to try again.
                $error = collect($e->errors())->flatten()->first();
            }
        }

        return response()->json(['two_step' => [...$this->pending($code->refresh()), 'challenge' => $challenge, 'error' => $error]], $status);
    }

    private function pending(SignInCode $code): array
    {
        $sent = $code->phone !== '' && $code->sends > 0;

        return [
            'needs_phone' => $code->phone === '',
            'phone' => $code->phone !== '' ? PhoneNumber::mask($code->phone) : null,
            'expires_in' => max(0, (int) ceil(now()->diffInSeconds($code->expires_at))),
            'resend_in' => max(0, SignInCodes::RESEND_SECONDS - (int) $code->last_sent_at->diffInSeconds(now())),
            'resends_left' => $sent ? SignInCodes::MAX_SENDS - $code->sends : null,
        ];
    }

    /** Second sign-in step: the code texted after the password. */
    public function twoStep(Request $request, SignInCodes $codes, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20'],
        ]);
        $code = $codes->findSignIn($data['challenge']) ?? throw self::startAgain();
        $codes->verify($code, $data['code']);

        // The account may have been suspended while the code was on its way.
        $user = $code->user()->firstOrFail();
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['code' => 'This account is suspended. Contact support.']);
        }

        if (SignInCodes::provesNewPhone($code, $user)) {
            // First sign-in with a checked phone: the number the code went to becomes the account's.
            $isNew = $user->created_at->gt($code->created_at->copy()->subMinutes(2));
            DB::transaction(function () use ($user, $code, $audit) {
                $user->forceFill(['phone' => $code->phone, 'phone_verified_at' => now()])->save();
                $audit->log('account.phone_verified', $user, ['phone' => PhoneNumber::mask($code->phone), 'at_sign_in' => true]);
            });
            if (! $isNew) {
                // An older account: its owner hears about a number added by whoever knew the password.
                try {
                    Notification::route('mail', $user->email)->notify(new SecurityNotice('phone_added', $user->name, PhoneNumber::mask($code->phone)));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return $this->tokenResponse($user);
    }

    public function resendTwoStep(Request $request, SignInCodes $codes): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'max:100']]);
        $code = $codes->resend($codes->findSignIn($data['challenge']) ?? throw self::startAgain());

        return response()->json(['two_step' => $this->pending($code)]);
    }

    /** For an account with no checked phone yet: the number to text the code to. */
    public function twoStepPhone(Request $request, SignInCodes $codes): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
        ]);
        $code = $codes->sendTo($codes->findSignIn($data['challenge']) ?? throw self::startAgain(), $data['phone']);

        return response()->json(['two_step' => $this->pending($code)]);
    }

    private static function startAgain(): ValidationException
    {
        return ValidationException::withMessages(['code' => 'This sign-in has expired. Enter your email and password again.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('sellerProfile.city', 'sellerProfile.area'));
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equalizer');
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('web', expiresAt: now()->addDays(30))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('sellerProfile')),
        ], $status);
    }
}
