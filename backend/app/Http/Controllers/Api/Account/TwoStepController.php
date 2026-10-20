<?php

namespace App\Http\Controllers\Api\Account;

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
use Illuminate\Validation\ValidationException;

/**
 * Checking the account phone with a texted code, and turning two-step sign-in on or off.
 * While two-step sign-in is on, a new number needs the password as well as a code sent to it.
 */
class TwoStepController extends Controller
{
    use ChecksCurrentPassword;

    public function __construct(private readonly SignInCodes $codes) {}

    /** Text a code to the number the user wants to use. */
    public function sendPhoneCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'current_password' => ['nullable', 'string'],
        ]);
        $this->ensureAvailable();
        $phone = PhoneNumber::ethiopianMobile($data['phone'])
            ?? throw ValidationException::withMessages(['phone' => 'Enter an Ethiopian mobile number, like 0911 234 567. Codes cannot be texted to a landline.']);
        if ($phone === $user->twoStepPhone()) {
            throw ValidationException::withMessages(['phone' => 'This number is already checked.']);
        }
        if ($user->usesTwoStep() || $user->mustUseTwoStep()) {
            $this->checkCurrentPassword($user, $data['current_password'] ?? null);
        }

        $code = $this->codes->startPhoneCheck($user, $phone);

        return response()->json(['data' => $this->pending($code->phone)]);
    }

    public function resendPhoneCode(Request $request): JsonResponse
    {
        $code = $this->codes->openPhoneCheck($request->user())
            ?? throw ValidationException::withMessages(['code' => 'This code has expired. Ask for a new one.']);
        $code = $this->codes->resend($code);

        return response()->json(['data' => [...$this->pending($code->phone), 'resends_left' => SignInCodes::MAX_SENDS - $code->sends]]);
    }

    public function verifyPhone(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $code = $this->codes->openPhoneCheck($user)
            ?? throw ValidationException::withMessages(['code' => 'This code has expired. Ask for a new one.']);
        $this->codes->verify($code, $data['code']);

        $codesWentThere = $user->usesTwoStep() || $user->mustUseTwoStep();
        $old = $user->twoStepPhone();
        DB::transaction(function () use ($user, $code, $audit, $old) {
            $user->forceFill(['phone' => $code->phone, 'phone_verified_at' => now()])->save();
            $audit->log('account.phone_verified', $user, ['phone' => PhoneNumber::mask($code->phone), 'replaced' => $old ? PhoneNumber::mask($old) : null]);
        });
        if ($codesWentThere && $old !== null && $old !== $code->phone) {
            $this->notify($user, new SecurityNotice('phone_changed', $user->name, PhoneNumber::mask($code->phone)));
        }

        return $this->account($request);
    }

    public function setTwoStep(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'current_password' => ['nullable', 'string'],
        ]);
        $on = (bool) $data['enabled'];
        if ($on ? $user->usesTwoStep() : $user->two_step_enabled_at === null) {
            return $this->account($request);
        }
        if ($on) {
            $this->ensureAvailable();
            if ($user->twoStepPhone() === null) {
                throw ValidationException::withMessages(['enabled' => 'Check your phone number first, so we know where to text your codes.']);
            }
        } elseif ($user->mustUseTwoStep()) {
            throw ValidationException::withMessages(['enabled' => 'Administrators must use two-step sign-in. It can be changed in Admin > Settings.']);
        }
        $this->checkCurrentPassword($user, $data['current_password'] ?? null);

        DB::transaction(function () use ($user, $on, $audit) {
            $user->forceFill(['two_step_enabled_at' => $on ? now() : null])->save();
            $audit->log($on ? 'account.two_step_on' : 'account.two_step_off', $user);
        });
        $this->notify($user, $on
            ? new SecurityNotice('two_step_on', $user->name, PhoneNumber::mask((string) $user->twoStepPhone()))
            : new SecurityNotice('two_step_off', $user->name));

        return $this->account($request);
    }

    private function ensureAvailable(): void
    {
        if (! $this->codes->available()) {
            throw ValidationException::withMessages(['phone' => 'Texting codes is not set up on this site yet.']);
        }
    }

    private function pending(string $phone): array
    {
        return [
            'phone' => PhoneNumber::mask($phone),
            'expires_in' => SignInCodes::MINUTES * 60,
            'resend_in' => SignInCodes::RESEND_SECONDS,
        ];
    }

    private function account(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            ...(new UserResource($request->user()->loadMissing('sellerProfile')))->resolve($request),
            'codes_available' => $this->codes->available(),
        ]]);
    }

    private function notify(User $user, SecurityNotice $notice): void
    {
        try {
            Notification::route('mail', $user->email)->notify($notice);
        } catch (\Throwable $e) {
            // The change is saved; a mail failure must not undo it or show the user an error.
            report($e);
        }
    }
}
