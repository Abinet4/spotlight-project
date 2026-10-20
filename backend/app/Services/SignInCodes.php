<?php

namespace App\Services;

use App\Models\SignInCode;
use App\Models\SmsMessage;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Sms\SmsFailed;
use App\Sms\SmsGateway;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Six-digit codes texted for the second sign-in step and to check a phone number.
 *
 * Only an HMAC of each code is stored. A code works once, for 5 minutes, and allows 5 wrong
 * tries in total however often it is sent again, so guessing stays at 5 in a million per sign-in.
 * Every text costs money, so sends are limited per account, per phone and for the whole site.
 */
class SignInCodes
{
    public const MINUTES = 5;

    public const RESEND_SECONDS = 60;

    /** Texts per code: the first one and two resends. */
    public const MAX_SENDS = 3;

    public const MAX_ATTEMPTS = 5;

    private const TEXTS_PER_ACCOUNT_HOUR = 8;

    private const TEXTS_PER_PHONE_HOUR = 8;

    public function __construct(private readonly SmsGateway $gateway) {}

    /**
     * Whether codes reach anyone: a real SMS provider, or a test site, where the codes can be read in
     * Admin > Text messages. On a live site without a provider two-step sign-in is skipped rather
     * than locking everyone out.
     */
    public function available(): bool
    {
        return $this->gateway->delivers() || AppServiceProvider::isTestSite();
    }

    /**
     * Text a sign-in code to the user's verified phone.
     *
     * @return array{0: SignInCode, 1: string} the code row and the challenge the browser keeps
     */
    public function startSignIn(User $user): array
    {
        $challenge = Str::random(48);
        $code = $this->issue($user, SignInCode::SIGN_IN, (string) $user->twoStepPhone(), ['challenge_hash' => self::challengeHash($challenge)]);

        return [$code, $challenge];
    }

    /**
     * Sign-in (or sign-up) for an account that must use two-step but has no checked phone yet: the
     * password is right, and the code goes to a number the person gives next (sendTo). That number
     * becomes the account's checked phone once the code is typed.
     *
     * @return array{0: SignInCode, 1: string} the code row and the challenge the browser keeps
     */
    public function startEnrollment(User $user): array
    {
        $challenge = Str::random(48);
        $code = DB::transaction(function () use ($user, $challenge) {
            SignInCode::where('user_id', $user->id)->where('purpose', SignInCode::SIGN_IN)->whereNull('used_at')
                ->where('expires_at', '>', now())->update(['expires_at' => now()]);

            // No code yet: it is made when a number is given. Time to type the number is included.
            return SignInCode::create([
                'user_id' => $user->id,
                'purpose' => SignInCode::SIGN_IN,
                'phone' => '',
                'code_hash' => hash('sha256', Str::random(40)),
                'challenge_hash' => self::challengeHash($challenge),
                'sends' => 0,
                'last_sent_at' => now()->subSeconds(self::RESEND_SECONDS),
                'expires_at' => now()->addMinutes(15),
            ]);
        });

        return [$code, $challenge];
    }

    /**
     * For a sign-in with no checked phone yet: text the code to the number given (again, for a typo).
     * Counts as one of the step's texts and waits out the same minute between texts.
     */
    public function sendTo(SignInCode $code, string $phone): SignInCode
    {
        $mobile = PhoneNumber::ethiopianMobile($phone)
            ?? throw self::error('Enter an Ethiopian mobile number, like 0911 234 567. Codes cannot be texted to a landline.', 422, 'phone');
        if ($code->user()->firstOrFail()->twoStepPhone() !== null) {
            throw self::error('This account already has a checked phone. Sign in again.');
        }
        $this->claimSend($code, ['phone' => $mobile]);
        $this->sendNewCode($code);

        return $code;
    }

    /** Text a code to a number the user wants to add, to prove it is theirs. */
    public function startPhoneCheck(User $user, string $phone): SignInCode
    {
        return $this->issue($user, SignInCode::PHONE, $phone);
    }

    public function findSignIn(string $challenge): ?SignInCode
    {
        return SignInCode::where('challenge_hash', self::challengeHash($challenge))->where('purpose', SignInCode::SIGN_IN)->first();
    }

    /**
     * Forgot password: text a code to the account's checked phone.
     *
     * @return array{0: SignInCode, 1: string} the code row and the challenge the browser keeps
     */
    public function startReset(User $user): array
    {
        $challenge = Str::random(48);
        $code = $this->issue($user, SignInCode::RESET, (string) $user->twoStepPhone(), ['challenge_hash' => self::challengeHash($challenge)]);

        return [$code, $challenge];
    }

    public function findReset(string $challenge): ?SignInCode
    {
        return SignInCode::where('challenge_hash', self::challengeHash($challenge))->where('purpose', SignInCode::RESET)->first();
    }

    public function openPhoneCheck(User $user): ?SignInCode
    {
        $code = SignInCode::where('user_id', $user->id)->where('purpose', SignInCode::PHONE)->latest('id')->first();

        return $code?->isOpen() ? $code : null;
    }

    /** Text a new code for the same step. The wrong tries so far still count. */
    public function resend(SignInCode $code): SignInCode
    {
        if ($code->phone === '') {
            throw self::error('Enter your mobile number first.', 422, 'phone');
        }
        $this->claimSend($code);
        $this->sendNewCode($code);

        return $code;
    }

    /** Claim one text for this step under a row lock, so a double click texts once. */
    private function claimSend(SignInCode $code, array $changes = []): void
    {
        DB::transaction(function () use ($code, $changes) {
            $row = SignInCode::whereKey($code->id)->lockForUpdate()->first();
            if (! $row?->isOpen()) {
                throw self::error('This code has expired. Start again.');
            }
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                throw self::error('Too many wrong codes. Start again.');
            }
            if ($row->sends >= self::MAX_SENDS) {
                throw self::error('No more codes can be sent for this step. Start again in a few minutes.', 429);
            }
            $wait = self::RESEND_SECONDS - (int) $row->last_sent_at->diffInSeconds(now());
            if ($wait > 0) {
                throw self::error("You can ask for a new code in {$wait} seconds.", 429);
            }
            $row->forceFill(['sends' => $row->sends + 1, 'last_sent_at' => now(), ...$changes])->save();
        });
        $code->refresh();
    }

    private function sendNewCode(SignInCode $code): void
    {
        $this->countText($code->user_id, $code->phone);

        $plain = self::newCode();
        $code->forceFill([
            'code_hash' => self::codeHash($code->user_id, $code->purpose, $plain),
            'expires_at' => now()->addMinutes(self::MINUTES),
        ])->save();
        try {
            $this->text($code, $plain);
        } catch (ValidationException $e) {
            // Nothing reached the phone, so the next try need not wait out the minute.
            $code->forceFill(['last_sent_at' => now()->subSeconds(self::RESEND_SECONDS)])->save();
            throw $e;
        }
    }

    /** Whether typing this code also proves a new number (a sign-in with no checked phone yet). */
    public static function provesNewPhone(SignInCode $code, User $user): bool
    {
        return $code->purpose === SignInCode::SIGN_IN && $code->phone !== '' && $code->phone !== $user->twoStepPhone();
    }

    /**
     * Check a typed code. Marks it used when right; otherwise counts the try and throws.
     *
     * @throws ValidationException
     */
    public function verify(SignInCode $code, string $typed): void
    {
        $typed = preg_replace('/\D/', '', $typed);

        // Decided under a row lock so parallel guesses all count, then thrown after the commit so a
        // wrong guess is not rolled back with the error.
        $outcome = DB::transaction(function () use ($code, $typed) {
            $row = SignInCode::whereKey($code->id)->lockForUpdate()->first();
            if (! $row?->isOpen()) {
                return 'expired';
            }
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                return 'locked';
            }
            if (! hash_equals($row->code_hash, self::codeHash($row->user_id, $row->purpose, $typed))) {
                $row->increment('attempts');

                return self::MAX_ATTEMPTS - $row->attempts;
            }
            $row->forceFill(['used_at' => now()])->save();

            return 'ok';
        });

        match (true) {
            $outcome === 'ok' => null,
            $outcome === 'expired' => throw self::error('This code has expired. Start again.'),
            $outcome === 'locked', $outcome === 0 => throw self::error('Too many wrong codes. Start again.'),
            default => throw self::error("That code is not right. {$outcome} ".Str::plural('try', $outcome).' left.'),
        };
        $code->refresh();
    }

    /** Old codes are only kept a day, for questions about a sign-in. */
    public function prune(): int
    {
        return SignInCode::where('expires_at', '<', now()->subDay())->delete();
    }

    private function issue(User $user, string $purpose, string $phone, array $extra = []): SignInCode
    {
        if (PhoneNumber::ethiopianMobile($phone) === null) {
            throw self::error('Enter an Ethiopian mobile number, like 0911 234 567.', 422, 'phone');
        }
        $this->countText($user->id, $phone);

        $plain = self::newCode();
        $code = DB::transaction(function () use ($user, $purpose, $phone, $extra, $plain) {
            // One open code per step: an older one stops working when a new one is asked for.
            SignInCode::where('user_id', $user->id)->where('purpose', $purpose)->whereNull('used_at')
                ->where('expires_at', '>', now())->update(['expires_at' => now()]);

            return SignInCode::create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'phone' => $phone,
                'code_hash' => self::codeHash($user->id, $purpose, $plain),
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes(self::MINUTES),
                ...$extra,
            ]);
        });

        try {
            $this->text($code, $plain);
        } catch (ValidationException $e) {
            $code->forceFill(['expires_at' => now()])->save();
            throw $e;
        }

        return $code;
    }

    /** Count one text against the account, the phone and the whole site, or refuse it. */
    private function countText(int $userId, string $phone): void
    {
        // The account and phone limits come first, so one busy account cannot use up the site's share.
        foreach (['sms-codes|user|'.$userId => self::TEXTS_PER_ACCOUNT_HOUR, 'sms-codes|phone|'.$phone => self::TEXTS_PER_PHONE_HOUR] as $key => $max) {
            if (RateLimiter::hit($key, 3600) > $max) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
                throw self::error("Too many codes were sent. Try again in {$minutes} min.", 429);
            }
        }
        if (RateLimiter::hit('sms-codes|site', 3600) > max(1, (int) config('marketplace.sign_in.codes_per_hour'))) {
            Log::warning('Sign-in codes paused: the hourly limit of texts was reached.');
            throw self::error('Codes cannot be sent right now. Try again in a few minutes.', 429);
        }
    }

    private function text(SignInCode $code, string $plain): void
    {
        $brand = config('marketplace.name');
        $what = $code->purpose === SignInCode::RESET ? 'password reset code' : 'code';
        $body = "Your {$brand} {$what} is {$plain}. It expires in ".self::MINUTES." minutes. Never share it; {$brand} staff will never ask for it.";

        // The admin text log keeps codes only on a test site, where reading them there is the point.
        $message = SmsMessage::create([
            'user_id' => $code->user_id,
            'kind' => match ($code->purpose) {
                SignInCode::SIGN_IN => 'sign_in_code',
                SignInCode::RESET => 'reset_code',
                default => 'phone_code',
            },
            'to' => $code->phone,
            'body' => $this->gateway->delivers() ? str_replace($plain, '******', $body) : $body,
            'driver' => $this->gateway->name(),
            'status' => 'pending',
            'attempts' => 1,
        ]);

        try {
            $providerId = $this->gateway->send($code->phone, $body);
        } catch (SmsFailed $e) {
            $message->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            report($e);
            throw self::error('The text with your code could not be sent. Try again in a minute.', 503);
        }

        $message->forceFill([
            'status' => $this->gateway->delivers() ? 'sent' : 'logged',
            'provider_message_id' => $providerId,
            'sent_at' => now(),
        ])->save();
    }

    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function codeHash(int $userId, string $purpose, string $code): string
    {
        return hash_hmac('sha256', $userId.'|'.$purpose.'|'.$code, (string) config('app.key'));
    }

    private static function challengeHash(string $challenge): string
    {
        return hash('sha256', $challenge);
    }

    private static function error(string $message, int $status = 422, string $field = 'code'): ValidationException
    {
        return ValidationException::withMessages([$field => $message])->status($status);
    }
}
