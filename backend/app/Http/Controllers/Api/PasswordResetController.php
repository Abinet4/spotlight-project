<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Services\AuditLogger;
use App\Services\SignInCodes;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Forgot password: a code texted to the account's checked phone, then a new password. Every device
 * is signed out and the account's email is told. Administrators reset theirs from the command line,
 * so a stolen SIM card is never enough to take over the admin pages.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly SignInCodes $codes) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:190']]);
        if (! $this->codes->available()) {
            throw ValidationException::withMessages(['login' => 'Resetting a password by text is not set up on this site yet. Contact support.']);
        }
        $user = $this->find(trim($data['login']));

        if ($user === null) {
            throw ValidationException::withMessages(['login' => 'We could not find an account with that email or checked phone number.']);
        }
        if ($user->isAdmin()) {
            throw ValidationException::withMessages(['login' => 'Administrators reset their password from the server (php artisan marketplace:create-admin).']);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['login' => 'This account is suspended. Contact support.']);
        }
        if ($user->twoStepPhone() === null) {
            throw ValidationException::withMessages(['login' => 'This account has no checked phone, so we cannot text a code. Contact support.']);
        }

        [$code, $challenge] = $this->codes->startReset($user);

        return response()->json(['reset' => [
            'challenge' => $challenge,
            'phone' => PhoneNumber::mask($code->phone),
            'expires_in' => SignInCodes::MINUTES * 60,
            'resend_in' => SignInCodes::RESEND_SECONDS,
        ]]);
    }

    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'max:100']]);
        $code = $this->codes->resend($this->codes->findReset($data['challenge']) ?? throw self::startAgain());

        return response()->json(['reset' => [
            'phone' => PhoneNumber::mask($code->phone),
            'resend_in' => SignInCodes::RESEND_SECONDS,
            'resends_left' => SignInCodes::MAX_SENDS - $code->sends,
        ]]);
    }

    public function reset(Request $request, AuditLogger $audit): JsonResponse
    {
        // The new password is checked first, so a weak one does not use up the code.
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers(), 'max:200'],
        ]);
        $code = $this->codes->findReset($data['challenge']) ?? throw self::startAgain();
        $this->codes->verify($code, $data['code']);

        $user = $code->user()->firstOrFail();
        if (! $user->isActive() || $user->isAdmin()) {
            throw ValidationException::withMessages(['code' => 'This account cannot be reset here. Contact support.']);
        }

        DB::transaction(function () use ($user, $data, $audit) {
            $user->password = $data['password'];
            $user->save();
            // Whoever knew the old password is signed out everywhere.
            $user->tokens()->delete();
            $audit->log('account.password_reset', $user, ['by' => 'texted_code']);
        });
        try {
            Notification::route('mail', $user->email)->notify(new SecurityNotice('password_reset', $user->name));
        } catch (\Throwable $e) {
            report($e);
        }

        // The code proved the phone and the person just chose the password, so this counts as both steps.
        $token = $user->createToken('web', expiresAt: now()->addDays(30))->plainTextToken;

        return response()->json(['token' => $token, 'user' => new UserResource($user->load('sellerProfile'))]);
    }

    /** By email, or by a checked phone that only one account uses. */
    private function find(string $login): ?User
    {
        if (str_contains($login, '@')) {
            return User::where('email', mb_strtolower($login))->first();
        }
        $phone = PhoneNumber::ethiopianMobile($login);
        if ($phone === null) {
            return null;
        }
        $matches = User::where('phone', $phone)->whereNotNull('phone_verified_at')->limit(2)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['login' => 'More than one account uses this phone. Enter your email instead.']);
        }

        return $matches->first();
    }

    private static function startAgain(): ValidationException
    {
        return ValidationException::withMessages(['code' => 'This code has expired. Start again.']);
    }
}
