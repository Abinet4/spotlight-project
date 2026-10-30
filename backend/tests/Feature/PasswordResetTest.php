<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\CodeCatcher;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private CodeCatcher $sms;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->sms = new CodeCatcher;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->user = User::factory()->create(['email' => 'meron@example.com', 'phone' => '+251911444555', 'phone_verified_at' => now()]);
    }

    private function ask(string $uri, array $data): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson('/api/auth/forgot-password'.$uri, $data);
    }

    private function reset(string $challenge, string $code, string $password = 'newpass123'): TestResponse
    {
        return $this->ask('/reset', ['challenge' => $challenge, 'code' => $code, 'password' => $password, 'password_confirmation' => $password]);
    }

    public function test_a_texted_code_resets_the_password_signs_out_everywhere_and_tells_the_owner(): void
    {
        $old = $this->user->createToken('web')->plainTextToken;
        $res = $this->ask('', ['login' => ' Meron@Example.com '])->assertOk()->assertJsonPath('reset.phone', '+251******555');
        $this->assertMatchesRegularExpression('/^Your Betoch Marketplace password reset code is \d{6}\./', $this->sms->lastBody);
        $this->assertSame('reset_code', SmsMessage::sole()->kind);

        $this->reset($res->json('reset.challenge'), $this->sms->code())->assertOk()->assertJsonStructure(['token', 'user']);
        $this->assertTrue(Hash::check('newpass123', $this->user->fresh()->password));
        // The old device is signed out; only the new token remains.
        $this->assertSame(1, $this->user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/auth/me')->assertUnauthorized();
        Notification::assertSentOnDemand(SecurityNotice::class, fn (SecurityNotice $n, array $c, $to) => $n->kind === 'password_reset' && $to->routes['mail'] === 'meron@example.com');
        $this->assertTrue(AuditLog::where('action', 'account.password_reset')->exists());
        // The code works once.
        $this->reset($res->json('reset.challenge'), $this->sms->code(), 'other123x')->assertJsonPath('errors.code.0', 'This code has expired. Start again.');
    }

    public function test_the_checked_phone_also_finds_the_account(): void
    {
        $this->ask('', ['login' => '0911 444 555'])->assertOk();
        $this->assertSame('+251911444555', $this->sms->lastTo);
    }

    public function test_a_phone_two_accounts_share_asks_for_the_email(): void
    {
        User::factory()->create(['phone' => '+251911444555', 'phone_verified_at' => now()]);
        $this->ask('', ['login' => '0911444555'])->assertJsonPath('errors.login.0', 'More than one account uses this phone. Enter your email instead.');
        $this->assertSame(0, $this->sms->calls);
    }

    public function test_who_cannot_reset_by_text(): void
    {
        $this->ask('', ['login' => 'nobody@example.com'])->assertJsonPath('errors.login.0', 'We could not find an account with that email or checked phone number.');
        // A phone typed but never checked is not used.
        User::factory()->create(['email' => 'nophone@example.com', 'phone' => '+251911777888']);
        $this->ask('', ['login' => 'nophone@example.com'])->assertJsonPath('errors.login.0', 'This account has no checked phone, so we cannot text a code. Contact support.');
        $this->ask('', ['login' => '0911777888'])->assertJsonValidationErrors('login');
        User::factory()->admin()->create(['email' => 'boss@example.com', 'phone' => '+251911999000', 'phone_verified_at' => now()]);
        $this->ask('', ['login' => 'boss@example.com'])->assertJsonPath('errors.login.0', 'Administrators reset their password from the server (php artisan marketplace:create-admin).');
        $this->user->forceFill(['status' => 'suspended'])->save();
        $this->ask('', ['login' => 'meron@example.com'])->assertJsonPath('errors.login.0', 'This account is suspended. Contact support.');
        $this->assertSame(0, $this->sms->calls);
    }

    public function test_a_weak_password_does_not_use_up_the_code_and_wrong_codes_are_limited(): void
    {
        $challenge = $this->ask('', ['login' => 'meron@example.com'])->json('reset.challenge');
        $code = $this->sms->code();
        $this->reset($challenge, $code, 'short')->assertJsonValidationErrors('password');
        $this->ask('/reset', ['challenge' => $challenge, 'code' => $code, 'password' => 'newpass123', 'password_confirmation' => 'different1'])->assertJsonValidationErrors('password');

        $wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        for ($i = 0; $i < 5; $i++) {
            $this->reset($challenge, $wrong)->assertUnprocessable();
        }
        $this->reset($challenge, $code)->assertJsonPath('errors.code.0', 'Too many wrong codes. Start again.');
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_resend_and_expiry(): void
    {
        $challenge = $this->ask('', ['login' => 'meron@example.com'])->json('reset.challenge');
        $this->ask('/resend', ['challenge' => $challenge])->assertStatus(429);
        $this->travel(61)->seconds();
        $this->ask('/resend', ['challenge' => $challenge])->assertOk()->assertJsonPath('reset.resends_left', 1);
        $this->travel(6)->minutes();
        $this->reset($challenge, $this->sms->code())->assertJsonPath('errors.code.0', 'This code has expired. Start again.');
        $this->ask('/resend', ['challenge' => 'made-up'])->assertJsonPath('errors.code.0', 'This code has expired. Start again.');
    }

    public function test_a_reset_code_does_not_work_as_a_sign_in_code(): void
    {
        $this->user->forceFill(['two_step_enabled_at' => now()])->save();
        $reset = $this->ask('', ['login' => 'meron@example.com'])->json('reset.challenge');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/two-step', ['challenge' => $reset, 'code' => $this->sms->code()])
            ->assertJsonPath('errors.code.0', 'This sign-in has expired. Enter your email and password again.');
    }

    public function test_asking_is_limited_per_account_and_per_address(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->ask('', ['login' => 'nobody'.$i.'@example.com']);
        }
        $this->ask('', ['login' => 'meron@example.com'])->assertStatus(429);
        $this->travel(2)->minutes();
        for ($i = 0; $i < 5; $i++) {
            $this->ask('', ['login' => 'MERON@example.com']);
            $this->travel(61)->seconds();
        }
        $this->ask('', ['login' => 'meron@example.com'])->assertStatus(429);
    }

    public function test_without_a_way_to_text_the_page_says_so(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->ask('', ['login' => 'meron@example.com'])->assertJsonPath('errors.login.0', 'Resetting a password by text is not set up on this site yet. Contact support.');
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
