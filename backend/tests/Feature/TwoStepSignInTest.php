<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\SignInCode;
use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Providers\AppServiceProvider;
use App\Services\SignInCodes;
use App\Sms\SmsEthiopiaGateway;
use App\Sms\SmsFailed;
use App\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\CodeCatcher;
use Tests\TestCase;

class TwoStepSignInTest extends TestCase
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
        $this->user = User::factory()->create(['email' => 'abebe@example.com', 'phone' => '0911223344']);
    }

    private function withTwoStep(User $user, string $phone = '+251911223344'): User
    {
        $user->forceFill(['phone' => $phone, 'phone_verified_at' => now(), 'two_step_enabled_at' => now()])->save();

        return $user;
    }

    private function login(string $email = 'abebe@example.com', string $password = 'password'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function send(string $method, string $uri, array $data = [], ?User $as = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $token = ($as ?? $this->user)->createToken('web', expiresAt: now()->addDays(30))->plainTextToken;

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function step(string $challenge, string $code): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson('/api/auth/two-step', ['challenge' => $challenge, 'code' => $code]);
    }

    private function wrong(string $right): string
    {
        return str_pad((string) (((int) $right + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    // Sign-in

    public function test_without_two_step_the_password_is_enough(): void
    {
        $this->login()->assertOk()->assertJsonStructure(['token', 'user'])->assertJsonPath('user.two_step', false);
        $this->assertSame(0, $this->sms->calls);
    }

    public function test_a_checked_phone_alone_does_not_turn_two_step_on(): void
    {
        $this->user->forceFill(['phone_verified_at' => now()])->save();
        $this->login()->assertOk()->assertJsonStructure(['token']);
    }

    public function test_with_two_step_the_password_gets_a_texted_code_and_no_token(): void
    {
        $this->withTwoStep($this->user);
        $res = $this->login()->assertOk()->assertJsonMissingPath('token')->assertJsonMissingPath('user');
        $this->assertSame('+251******344', $res->json('two_step.phone'));
        $this->assertSame(300, $res->json('two_step.expires_in'));
        $this->assertSame(60, $res->json('two_step.resend_in'));
        $this->assertSame('+251911223344', $this->sms->lastTo);
        $this->assertMatchesRegularExpression('/^Your Betoch Furniture code is \d{6}\. It expires in 5 minutes\./', $this->sms->lastBody);

        $this->step($res->json('two_step.challenge'), $this->sms->code())->assertOk()
            ->assertJsonStructure(['token', 'user'])->assertJsonPath('user.email', 'abebe@example.com');
    }

    public function test_a_wrong_password_sends_no_code(): void
    {
        $this->withTwoStep($this->user);
        $this->login(password: 'nope')->assertUnprocessable()->assertJsonMissingPath('two_step');
        $this->assertSame(0, $this->sms->calls);
    }

    public function test_a_suspended_account_gets_no_code(): void
    {
        $this->withTwoStep($this->user)->forceFill(['status' => 'suspended'])->save();
        $this->login()->assertUnprocessable();
        $this->assertSame(0, $this->sms->calls);
    }

    public function test_a_code_works_once(): void
    {
        $this->withTwoStep($this->user);
        $challenge = $this->login()->json('two_step.challenge');
        $code = $this->sms->code();
        $this->step($challenge, $code)->assertOk();
        $this->step($challenge, $code)->assertUnprocessable()->assertJsonPath('errors.code.0', 'This code has expired. Start again.');
    }

    public function test_spaces_in_the_typed_code_are_ignored(): void
    {
        $this->withTwoStep($this->user);
        $challenge = $this->login()->json('two_step.challenge');
        $code = $this->sms->code();
        $this->step($challenge, substr($code, 0, 3).' '.substr($code, 3))->assertOk();
    }

    public function test_five_wrong_codes_lock_the_step_even_for_the_right_code(): void
    {
        $this->withTwoStep($this->user);
        $challenge = $this->login()->json('two_step.challenge');
        $code = $this->sms->code();

        $this->step($challenge, $this->wrong($code))->assertUnprocessable()->assertJsonPath('errors.code.0', 'That code is not right. 4 tries left.');
        foreach ([3, 2, 1] as $left) {
            $this->step($challenge, $this->wrong($code))->assertJsonPath('errors.code.0', "That code is not right. {$left} ".($left === 1 ? 'try' : 'tries').' left.');
        }
        $this->step($challenge, $this->wrong($code))->assertJsonPath('errors.code.0', 'Too many wrong codes. Start again.');
        $this->step($challenge, $code)->assertUnprocessable()->assertJsonPath('errors.code.0', 'Too many wrong codes. Start again.');
        $this->assertSame(5, SignInCode::sole()->attempts);
    }

    public function test_a_code_expires_after_five_minutes(): void
    {
        $this->withTwoStep($this->user);
        $challenge = $this->login()->json('two_step.challenge');
        $code = $this->sms->code();
        $this->travel(5)->minutes();
        $this->travel(1)->seconds();
        $this->step($challenge, $code)->assertUnprocessable()->assertJsonPath('errors.code.0', 'This code has expired. Start again.');
    }

    public function test_an_unknown_or_missing_challenge_is_refused(): void
    {
        $this->step('made-up', '123456')->assertUnprocessable()->assertJsonPath('errors.code.0', 'This sign-in has expired. Enter your email and password again.');
        $this->postJson('/api/auth/two-step', ['code' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('challenge');
        $this->postJson('/api/auth/two-step', ['challenge' => ['x'], 'code' => '1'])->assertUnprocessable();
    }

    public function test_a_new_sign_in_cancels_the_previous_code(): void
    {
        $this->withTwoStep($this->user);
        $first = $this->login()->json('two_step.challenge');
        $firstCode = $this->sms->code();
        $second = $this->login()->json('two_step.challenge');

        $this->step($first, $firstCode)->assertJsonPath('errors.code.0', 'This code has expired. Start again.');
        $this->step($second, $this->sms->code())->assertOk();
    }

    public function test_an_account_suspended_while_the_code_was_on_its_way_is_refused(): void
    {
        $this->withTwoStep($this->user);
        $challenge = $this->login()->json('two_step.challenge');
        $this->user->forceFill(['status' => 'suspended'])->save();
        $this->step($challenge, $this->sms->code())->assertUnprocessable()->assertJsonPath('errors.code.0', 'This account is suspended. Contact support.');
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_resend_waits_a_minute_allows_two_resends_and_keeps_the_wrong_tries(): void
    {
        $this->withTwoStep($this->user);
        $challenge = $this->login()->json('two_step.challenge');
        $first = $this->sms->code();
        $this->step($challenge, $this->wrong($first));

        $this->postJson('/api/auth/two-step/resend', ['challenge' => $challenge])->assertStatus(429)
            ->assertJsonPath('errors.code.0', 'You can ask for a new code in 60 seconds.');
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/two-step/resend', ['challenge' => $challenge])->assertOk()->assertJsonPath('two_step.resends_left', 1);
        $second = $this->sms->code();
        $this->assertSame(2, $this->sms->calls);

        // The old code stops working and the wrong try still counts.
        if ($first !== $second) {
            $this->step($challenge, $first)->assertJsonPath('errors.code.0', 'That code is not right. 3 tries left.');
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/two-step/resend', ['challenge' => $challenge])->assertOk()->assertJsonPath('two_step.resends_left', 0);
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/two-step/resend', ['challenge' => $challenge])->assertStatus(429)
            ->assertJsonPath('errors.code.0', 'No more codes can be sent for this step. Start again in a few minutes.');
        $this->step($challenge, $this->sms->code())->assertOk();
    }

    public function test_a_resend_gives_the_code_five_more_minutes(): void
    {
        $this->withTwoStep($this->user);
        $challenge = $this->login()->json('two_step.challenge');
        $this->travel(4)->minutes();
        $this->postJson('/api/auth/two-step/resend', ['challenge' => $challenge])->assertOk();
        $this->travel(4)->minutes();
        $this->step($challenge, $this->sms->code())->assertOk();
    }

    public function test_a_locked_or_used_code_cannot_be_resent(): void
    {
        $this->withTwoStep($this->user);
        $challenge = $this->login()->json('two_step.challenge');
        $code = $this->sms->code();
        $this->step($challenge, $code)->assertOk();
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/two-step/resend', ['challenge' => $challenge])->assertUnprocessable();
        $this->assertSame(1, $this->sms->calls);

        $challenge = $this->login()->json('two_step.challenge');
        for ($i = 0; $i < 5; $i++) {
            $this->step($challenge, $this->wrong($this->sms->code()));
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/two-step/resend', ['challenge' => $challenge])->assertJsonPath('errors.code.0', 'Too many wrong codes. Start again.');
    }

    public function test_an_account_gets_at_most_eight_texts_an_hour(): void
    {
        $this->withTwoStep($this->user);
        for ($i = 0; $i < 8; $i++) {
            $this->login()->assertOk()->assertJsonPath('two_step.phone', '+251******344');
            // The sign-in limiter (5 a minute) is a separate guard; step past it.
            $this->travel(61)->seconds();
        }
        $this->login()->assertStatus(429)->assertJsonPath('errors.code.0', fn ($m) => str_starts_with($m, 'Too many codes were sent. Try again in '));
        $this->assertSame(8, $this->sms->calls);
        $this->travel(1)->hours();
        $this->login()->assertOk()->assertJsonPath('two_step.phone', '+251******344');
    }

    public function test_one_phone_gets_at_most_eight_texts_an_hour_across_accounts(): void
    {
        $other = $this->withTwoStep(User::factory()->create(['email' => 'other@example.com']));
        $this->withTwoStep($this->user);
        for ($i = 0; $i < 8; $i++) {
            $this->login($i % 2 ? 'other@example.com' : 'abebe@example.com')->assertOk();
            $this->travel(61)->seconds();
        }
        $this->login('other@example.com')->assertStatus(429);
        $this->assertNotNull($other);
    }

    public function test_the_whole_site_has_an_hourly_cap_on_code_texts(): void
    {
        config(['marketplace.sign_in.codes_per_hour' => 2]);
        foreach (['a', 'b', 'c'] as $i => $name) {
            $this->withTwoStep(User::factory()->create(['email' => "{$name}@example.com"]), '+25191100000'.$i);
        }
        $this->login('a@example.com')->assertOk();
        $this->login('b@example.com')->assertOk();
        $this->login('c@example.com')->assertStatus(429)->assertJsonPath('errors.code.0', 'Codes cannot be sent right now. Try again in a few minutes.');
    }

    public function test_when_the_text_fails_the_code_is_dropped_and_logged(): void
    {
        $this->withTwoStep($this->user);
        $this->sms->delivers = true;
        $this->sms->fail = new SmsFailed('SMS Ethiopia refused the SMS (HTTP 401): bad key', retryable: false);

        $this->login()->assertStatus(503)->assertJsonPath('errors.code.0', 'The text with your code could not be sent. Try again in a minute.');
        $this->assertFalse(SignInCode::sole()->isOpen());
        $row = SmsMessage::sole();
        $this->assertSame(['failed', 'sign_in_code'], [$row->status, $row->kind]);
        $this->assertStringContainsString('bad key', $row->error);
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_a_real_provider_never_keeps_the_code_in_the_text_log(): void
    {
        $this->withTwoStep($this->user);
        $this->sms->delivers = true;
        $this->login()->assertOk();
        $code = $this->sms->code();
        $row = SmsMessage::sole();
        $this->assertSame('sent', $row->status);
        $this->assertStringNotContainsString($code, $row->body);
        $this->assertStringContainsString('******', $row->body);
        // Only a keyed hash of the code is stored.
        $stored = json_encode(DB::table('sign_in_codes')->get());
        $this->assertStringNotContainsString($code, $stored);
    }

    public function test_on_a_test_site_the_code_can_be_read_in_the_text_log(): void
    {
        $this->withTwoStep($this->user);
        $this->login()->assertOk();
        $row = SmsMessage::sole();
        $this->assertSame('logged', $row->status);
        $this->assertStringContainsString($this->sms->code(), $row->body);
    }

    public function test_codes_go_out_even_when_sms_alerts_are_paused(): void
    {
        Setting::create(['key' => 'sms_enabled', 'value' => '0']);
        $this->withTwoStep($this->user);
        $this->login()->assertOk()->assertJsonStructure(['two_step']);
        $this->assertSame(1, $this->sms->calls);
    }

    public function test_a_live_site_without_an_sms_provider_skips_the_code_step(): void
    {
        $this->withTwoStep($this->user);
        $this->app['env'] = 'production';
        try {
            $this->login()->assertOk()->assertJsonStructure(['token']);
            $this->assertSame(0, $this->sms->calls);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_the_code_step_has_its_own_request_limit(): void
    {
        $this->withTwoStep($this->user);
        $challenge = $this->login()->json('two_step.challenge');
        for ($i = 0; $i < 10; $i++) {
            $this->step($challenge, 'abc');
        }
        $this->step($challenge, $this->sms->code())->assertStatus(429);
    }

    // Checking a phone

    public function test_checking_a_phone_saves_it_in_one_format(): void
    {
        $this->send('POST', '/api/account/phone/code', ['phone' => '0922 33 44 55'])->assertOk()
            ->assertJsonPath('data.phone', '+251******455')->assertJsonPath('data.resend_in', 60);
        $this->assertSame('+251922334455', $this->sms->lastTo);

        $this->send('POST', '/api/account/phone/verify', ['code' => $this->wrong($this->sms->code())])->assertUnprocessable();
        $this->send('POST', '/api/account/phone/verify', ['code' => $this->sms->code()])->assertOk()
            ->assertJsonPath('data.phone', '+251922334455')->assertJsonPath('data.phone_verified', true)->assertJsonPath('data.two_step', false);
        $this->assertNotNull($this->user->fresh()->phone_verified_at);
        $this->assertTrue(AuditLog::where('action', 'account.phone_verified')->exists());
        $this->assertSame('phone_code', SmsMessage::sole()->kind);
    }

    public function test_only_ethiopian_mobiles_can_be_checked(): void
    {
        foreach (['0111234567', '12345', '+1 202 555 0100', '09112233'] as $phone) {
            $this->send('POST', '/api/account/phone/code', ['phone' => $phone])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->send('POST', '/api/account/phone/code', [])->assertJsonValidationErrors('phone');
        $this->assertSame(0, $this->sms->calls);
    }

    public function test_the_already_checked_number_is_not_texted_again(): void
    {
        $this->user->forceFill(['phone' => '+251911223344', 'phone_verified_at' => now()])->save();
        $this->send('POST', '/api/account/phone/code', ['phone' => '0911223344'])->assertJsonPath('errors.phone.0', 'This number is already checked.');
    }

    public function test_verifying_without_a_code_sent_or_after_it_expired_fails(): void
    {
        $this->send('POST', '/api/account/phone/verify', ['code' => '123456'])->assertJsonPath('errors.code.0', 'This code has expired. Ask for a new one.');
        $this->send('POST', '/api/account/phone/code', ['phone' => '0922334455'])->assertOk();
        $this->travel(6)->minutes();
        $this->send('POST', '/api/account/phone/verify', ['code' => $this->sms->code()])->assertJsonPath('errors.code.0', 'This code has expired. Ask for a new one.');
        $this->assertNull($this->user->fresh()->phone_verified_at);
    }

    public function test_a_phone_code_does_not_sign_anyone_in_and_a_sign_in_code_does_not_check_a_phone(): void
    {
        $this->send('POST', '/api/account/phone/code', ['phone' => '0922334455'])->assertOk();
        $phoneCode = SignInCode::sole();
        $this->assertNull($phoneCode->challenge_hash);

        $this->withTwoStep($this->user);
        $this->login()->assertOk();
        // The sign-in code went to the checked phone; it cannot finish the open phone check.
        $this->send('POST', '/api/account/phone/verify', ['code' => $this->sms->code()])->assertUnprocessable();
    }

    public function test_with_two_step_on_a_new_number_needs_the_password_and_the_old_one_is_told(): void
    {
        $this->withTwoStep($this->user);
        $this->send('POST', '/api/account/phone/code', ['phone' => '0922334455'])->assertJsonValidationErrors('current_password');
        $this->send('POST', '/api/account/phone/code', ['phone' => '0922334455', 'current_password' => 'wrong'])->assertJsonPath('errors.current_password.0', 'Your current password is not correct.');
        $this->assertSame(0, $this->sms->calls);

        $this->send('POST', '/api/account/phone/code', ['phone' => '0922334455', 'current_password' => 'password'])->assertOk();
        // Until the new number is proven, codes still go to the old one.
        $this->assertSame('+251911223344', $this->user->fresh()->twoStepPhone());
        $this->send('POST', '/api/account/phone/verify', ['code' => $this->sms->code()])->assertOk()->assertJsonPath('data.two_step', true);
        $this->assertSame('+251922334455', $this->user->fresh()->twoStepPhone());

        Notification::assertSentOnDemand(SecurityNotice::class, fn (SecurityNotice $n, array $ch, AnonymousNotifiable $to) => $n->kind === 'phone_changed' && $n->detail === '+251******455' && $to->routes['mail'] === 'abebe@example.com');
    }

    public function test_phone_resend_waits_and_needs_an_open_code(): void
    {
        $this->send('POST', '/api/account/phone/resend')->assertUnprocessable();
        $this->send('POST', '/api/account/phone/code', ['phone' => '0922334455'])->assertOk();
        $this->send('POST', '/api/account/phone/resend')->assertStatus(429);
        $this->travel(61)->seconds();
        $this->send('POST', '/api/account/phone/resend')->assertOk()->assertJsonPath('data.resends_left', 1);
        $this->send('POST', '/api/account/phone/verify', ['code' => $this->sms->code()])->assertOk();
    }

    // Turning two-step on and off

    public function test_turning_two_step_on_needs_a_checked_phone_and_the_password(): void
    {
        $this->send('PUT', '/api/account/two-step', ['enabled' => true, 'current_password' => 'password'])
            ->assertJsonPath('errors.enabled.0', 'Check your phone number first, so we know where to text your codes.');

        $this->user->forceFill(['phone' => '+251911223344', 'phone_verified_at' => now()])->save();
        $this->send('PUT', '/api/account/two-step', ['enabled' => true])->assertJsonValidationErrors('current_password');
        $this->send('PUT', '/api/account/two-step', ['enabled' => true, 'current_password' => 'nope'])->assertJsonValidationErrors('current_password');
        $this->assertFalse($this->user->fresh()->usesTwoStep());

        $this->send('PUT', '/api/account/two-step', ['enabled' => true, 'current_password' => 'password'])->assertOk()->assertJsonPath('data.two_step', true);
        $this->assertTrue($this->user->fresh()->usesTwoStep());
        Notification::assertSentOnDemand(SecurityNotice::class, fn (SecurityNotice $n) => $n->kind === 'two_step_on' && $n->detail === '+251******344');
        $this->assertTrue(AuditLog::where('action', 'account.two_step_on')->exists());

        $this->send('PUT', '/api/account/two-step', ['enabled' => false])->assertJsonValidationErrors('current_password');
        $this->send('PUT', '/api/account/two-step', ['enabled' => false, 'current_password' => 'password'])->assertOk()->assertJsonPath('data.two_step', false);
        Notification::assertSentOnDemand(SecurityNotice::class, fn (SecurityNotice $n) => $n->kind === 'two_step_off');
        $this->login()->assertJsonStructure(['token']);
    }

    public function test_asking_for_the_current_state_changes_nothing(): void
    {
        $this->send('PUT', '/api/account/two-step', ['enabled' => false])->assertOk();
        Notification::assertNothingSent();
    }

    public function test_wrong_passwords_on_the_switch_are_limited(): void
    {
        $this->user->forceFill(['phone' => '+251911223344', 'phone_verified_at' => now()])->save();
        for ($i = 0; $i < 5; $i++) {
            $this->send('PUT', '/api/account/two-step', ['enabled' => true, 'current_password' => 'nope'])->assertUnprocessable();
        }
        $this->send('PUT', '/api/account/two-step', ['enabled' => true, 'current_password' => 'password'])->assertStatus(429);
    }

    public function test_two_step_cannot_be_turned_on_when_codes_reach_no_one(): void
    {
        $this->user->forceFill(['phone' => '+251911223344', 'phone_verified_at' => now()])->save();
        $this->app['env'] = 'production';
        try {
            $this->send('PUT', '/api/account/two-step', ['enabled' => true, 'current_password' => 'password'])
                ->assertJsonPath('errors.phone.0', 'Texting codes is not set up on this site yet.');
            $this->send('POST', '/api/account/phone/code', ['phone' => '0922334455'])->assertJsonPath('errors.phone.0', 'Texting codes is not set up on this site yet.');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    // The profile form

    public function test_changing_the_phone_on_the_profile_drops_the_check(): void
    {
        $this->user->forceFill(['phone' => '+251911223344', 'phone_verified_at' => now()])->save();
        // The same number written differently keeps it.
        $this->send('PUT', '/api/account/profile', ['phone' => '0911 223 344'])->assertOk()->assertJsonPath('data.phone_verified', true);
        $this->send('PUT', '/api/account/profile', ['phone' => '0922334455'])->assertOk()->assertJsonPath('data.phone_verified', false);
        $this->assertNull($this->user->fresh()->phone_verified_at);
    }

    public function test_with_two_step_on_the_profile_cannot_change_the_phone(): void
    {
        $this->withTwoStep($this->user);
        $this->send('PUT', '/api/account/profile', ['phone' => '0922334455'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->send('PUT', '/api/account/profile', ['phone' => null])->assertUnprocessable();
        $this->send('PUT', '/api/account/profile', ['name' => 'New Name', 'phone' => '+251 911 223 344'])->assertOk();
        $this->assertTrue($this->user->fresh()->usesTwoStep());
    }

    // Administrators

    public function test_the_admin_rule_needs_codes_and_a_checked_phone_for_every_active_admin(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
        User::factory()->admin()->create(['email' => 'gone@example.com', 'status' => 'suspended']);

        $res = $this->send('GET', '/api/admin/settings', as: $admin)->assertOk()
            ->assertJsonPath('data.admins_must_use_two_step', false)
            ->assertJsonPath('meta.sign_in_codes_available', true);
        $this->assertSame(['admin@example.com'], $res->json('meta.admins_without_checked_phone'));

        $this->send('PUT', '/api/admin/settings', ['admins_must_use_two_step' => true], $admin)
            ->assertJsonPath('errors.admins_must_use_two_step.0', 'These administrators must check their phone in My account first: admin@example.com.');

        $admin->forceFill(['phone' => '+251933445566', 'phone_verified_at' => now()])->save();
        $this->app['env'] = 'production';
        try {
            $this->send('PUT', '/api/admin/settings', ['admins_must_use_two_step' => true], $admin)
                ->assertJsonValidationErrors('admins_must_use_two_step');
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->send('PUT', '/api/admin/settings', ['admins_must_use_two_step' => true], $admin)->assertOk()
            ->assertJsonPath('data.admins_must_use_two_step', true)->assertJsonPath('meta.admins_without_checked_phone', []);
        // Saving other settings later is not blocked by the check.
        $this->send('PUT', '/api/admin/settings', ['admins_must_use_two_step' => true, 'cod_enabled' => true], $admin)->assertOk();

        // The admin never turned two-step on, but the rule asks for a code anyway.
        $this->login('admin@example.com')->assertOk()->assertJsonPath('two_step.phone', '+251******566');
        $this->send('PUT', '/api/account/two-step', ['enabled' => false, 'current_password' => 'password'], $admin)->assertOk();
        $this->send('GET', '/api/auth/me', as: $admin)->assertJsonPath('data.two_step_required', true);
    }

    public function test_under_the_rule_an_admin_cannot_turn_two_step_off_or_change_the_phone_without_a_code(): void
    {
        Setting::create(['key' => 'admins_must_use_two_step', 'value' => '1']);
        $admin = $this->withTwoStep(User::factory()->admin()->create(['email' => 'admin@example.com']), '+251933445566');
        $this->send('PUT', '/api/account/two-step', ['enabled' => false, 'current_password' => 'password'], $admin)
            ->assertJsonPath('errors.enabled.0', 'Administrators must use two-step sign-in. It can be changed in Admin > Settings.');
        $this->send('PUT', '/api/account/profile', ['phone' => '0922334455'], $admin)->assertJsonValidationErrors('phone');
        $this->send('POST', '/api/account/phone/code', ['phone' => '0922334455'], $admin)->assertJsonValidationErrors('current_password');
    }

    public function test_an_admin_with_no_checked_phone_under_the_rule_adds_one_while_signing_in(): void
    {
        Setting::create(['key' => 'admins_must_use_two_step', 'value' => '1']);
        $admin = User::factory()->admin()->create(['email' => 'new-admin@example.com']);
        $this->travel(1)->days();

        $res = $this->login('new-admin@example.com')->assertOk()->assertJsonMissingPath('token')
            ->assertJsonPath('two_step.needs_phone', true)->assertJsonPath('two_step.phone', null);
        $challenge = $res->json('two_step.challenge');
        $this->assertSame(0, $this->sms->calls);

        $this->postJson('/api/auth/two-step/phone', ['challenge' => $challenge, 'phone' => '011 123 4567'])->assertJsonValidationErrors('phone');
        $this->postJson('/api/auth/two-step/phone', ['challenge' => $challenge, 'phone' => '0933 44 55 66'])->assertOk()
            ->assertJsonPath('two_step.phone', '+251******566')->assertJsonPath('two_step.needs_phone', false)->assertJsonPath('two_step.resends_left', 2);
        $this->assertSame('+251933445566', $this->sms->lastTo);

        $this->step($challenge, $this->sms->code())->assertOk()->assertJsonStructure(['token'])
            ->assertJsonPath('user.phone', '+251933445566')->assertJsonPath('user.phone_verified', true);
        $this->assertNotNull($admin->fresh()->phone_verified_at);
        // An older account hears that a phone was added, in case it was not the owner.
        Notification::assertSentOnDemand(SecurityNotice::class, fn (SecurityNotice $n) => $n->kind === 'phone_added' && $n->detail === '+251******566');
        $this->assertTrue(AuditLog::where('action', 'account.phone_verified')->exists());
    }

    public function test_an_admin_can_reset_two_step_for_someone_who_lost_their_phone(): void
    {
        $admin = User::factory()->admin()->create();
        $this->withTwoStep($this->user);
        $this->login()->assertOk();
        $this->assertTrue(SignInCode::sole()->isOpen());

        $this->send('POST', "/api/admin/users/{$this->user->id}/two-step-off", [], $admin)->assertJsonValidationErrors('reason');
        $this->send('POST', "/api/admin/users/{$this->user->id}/two-step-off", ['reason' => 'Lost phone, called from office'], $this->user)->assertForbidden();
        $this->send('POST', "/api/admin/users/{$this->user->id}/two-step-off", ['reason' => 'Lost phone, called from office'], $admin)->assertOk()
            ->assertJsonPath('data.two_step', false)->assertJsonPath('data.phone_verified', false);

        $this->assertFalse(SignInCode::sole()->isOpen());
        $this->assertSame('Lost phone, called from office', AuditLog::where('action', 'user.two_step_reset')->sole()->meta['reason']);
        Notification::assertSentOnDemand(SecurityNotice::class, fn (SecurityNotice $n) => $n->kind === 'two_step_reset');
        $this->login()->assertJsonStructure(['token']);

        $this->send('POST', "/api/admin/users/{$this->user->id}/two-step-off", ['reason' => 'Again'], $admin)->assertUnprocessable();
        $other = $this->withTwoStep(User::factory()->admin()->create());
        $this->send('POST', "/api/admin/users/{$other->id}/two-step-off", ['reason' => 'Lost phone'], $admin)
            ->assertJsonPath('errors.two_step.0', 'Admin accounts are managed from the command line.');
    }

    public function test_the_command_line_resets_any_account(): void
    {
        $admin = $this->withTwoStep(User::factory()->admin()->create(['email' => 'boss@example.com']));
        $this->artisan('marketplace:two-step-off', ['email' => 'Boss@Example.com '])->assertExitCode(0);
        $this->assertFalse($admin->fresh()->usesTwoStep());
        $this->assertNull($admin->fresh()->phone_verified_at);
        $this->artisan('marketplace:two-step-off', ['email' => 'nobody@example.com'])->assertExitCode(1);
    }

    public function test_the_admin_user_list_shows_who_uses_two_step(): void
    {
        $admin = User::factory()->admin()->create();
        $this->withTwoStep($this->user);
        $row = collect($this->send('GET', '/api/admin/users', as: $admin)->json('data'))->firstWhere('id', $this->user->id);
        $this->assertTrue($row['two_step']);
        $this->assertTrue($row['phone_verified']);
    }

    // Housekeeping and the provider

    public function test_old_codes_are_pruned_after_a_day(): void
    {
        $this->withTwoStep($this->user);
        $this->login();
        $this->travel(1)->days();
        $this->login();
        $this->travel(1)->hours();
        $this->assertSame(1, app(SignInCodes::class)->prune());
        $this->assertSame(1, SignInCode::count());
    }

    public function test_deleting_an_account_deletes_its_codes(): void
    {
        $this->withTwoStep($this->user);
        $this->login();
        $this->user->delete();
        $this->assertSame(0, SignInCode::count());
    }

    public function test_sms_ethiopia_request_and_replies(): void
    {
        $gateway = new SmsEthiopiaGateway(['base_url' => 'https://smsethiopia.com/api/', 'key' => 'k-123']);

        $this->fakeHttp(Http::response(['sent' => true, 'id' => 77]));
        $this->assertSame('77', $gateway->send('+251911223344', 'Hello'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://smsethiopia.com/api/sms/send' && $r->method() === 'POST'
            && $r->header('KEY') === ['k-123'] && $r['msisdn'] === '251911223344' && $r['text'] === 'Hello');

        // The reply their API reference shows.
        $this->fakeHttp(Http::response(['status' => 'success', 'message' => 'Accepted Successfully']));
        $this->assertNull($gateway->send('+251911223344', 'Hi'));
        // A 2xx reply in another shape still counts as sent, unless it says it failed.
        $this->fakeHttp(Http::response(['sent' => true, 'description' => 'Accepted for delivery']));
        $this->assertNull($gateway->send('+251911223344', 'Hi'));
        foreach ([['sent' => false], ['success' => false], ['status' => 'FAILED'], ['error' => 'Insufficient balance'], 'plain text'] as $reply) {
            $this->fakeHttp(Http::response($reply, 200));
            $this->assertFalse($this->failure(fn () => $gateway->send('+251911223344', 'Hi'))->retryable, json_encode($reply));
        }

        $this->fakeHttp(Http::response(['message' => 'Invalid API key'], 401));
        $e = $this->failure(fn () => $gateway->send('+251911223344', 'Hi'));
        $this->assertFalse($e->retryable);
        $this->assertStringContainsString('Invalid API key', $e->getMessage());

        $this->fakeHttp(Http::response('down', 503));
        $this->assertTrue($this->failure(fn () => $gateway->send('+251911223344', 'Hi'))->retryable);
        $this->fakeHttp(Http::failedConnection('timed out'));
        $this->assertTrue($this->failure(fn () => $gateway->send('+251911223344', 'Hi'))->retryable);

        $noKey = new SmsEthiopiaGateway(['base_url' => 'https://smsethiopia.com/api', 'key' => null]);
        $this->assertFalse($this->failure(fn () => $noKey->send('+251911223344', 'Hi'))->retryable);
    }

    public function test_the_smsethiopia_driver_comes_from_config(): void
    {
        config(['marketplace.alerts.sms_driver' => 'smsethiopia']);
        $this->app->forgetInstance(SmsGateway::class);
        (new AppServiceProvider($this->app))->register();
        $this->assertSame('smsethiopia', app(SmsGateway::class)->name());
        $this->assertTrue(app(SmsGateway::class)->delivers());
    }

    private function fakeHttp(mixed $response): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => $response]);
    }

    private function failure(\Closure $call): SmsFailed
    {
        try {
            $call();
        } catch (SmsFailed $e) {
            return $e;
        }
        $this->fail('Expected SmsFailed');
    }
}
