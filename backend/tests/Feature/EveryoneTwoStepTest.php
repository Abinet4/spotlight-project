<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\City;
use App\Models\Setting;
use App\Models\SignInCode;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Sms\SmsFailed;
use App\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\CodeCatcher;
use Tests\TestCase;

/** Admin > Settings "everyone uses two-step": a checked phone at sign-up and a code at every sign-in. */
class EveryoneTwoStepTest extends TestCase
{
    use RefreshDatabase;

    private CodeCatcher $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->sms = new CodeCatcher;
        $this->app->instance(SmsGateway::class, $this->sms);
        Setting::create(['key' => 'everyone_must_use_two_step', 'value' => '1']);
    }

    private function json2(string $uri, array $data): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson($uri, $data);
    }

    private function register(array $over = []): TestResponse
    {
        return $this->json2('/api/auth/register', [
            'name' => 'Sara Tesfaye', 'email' => 'sara@example.com', 'phone' => '0912 345 678',
            'password' => 'secret123', 'password_confirmation' => 'secret123', ...$over,
        ]);
    }

    public function test_the_shop_pages_learn_that_a_phone_is_needed(): void
    {
        $this->getJson('/api/config')->assertJsonPath('phone_required', true);
        Setting::where('key', 'everyone_must_use_two_step')->update(['value' => '0']);
        $this->getJson('/api/config')->assertJsonPath('phone_required', false);
    }

    public function test_sign_up_needs_a_mobile_and_the_texted_code_before_any_token(): void
    {
        $this->register(['phone' => null])->assertJsonValidationErrors('phone');
        $this->register(['phone' => '0111 23 45 67'])->assertJsonPath('errors.phone.0', 'Enter an Ethiopian mobile number, like 0911 234 567. We text you a code to check it.');
        $this->assertSame(0, User::count());

        $res = $this->register()->assertCreated()->assertJsonMissingPath('token')
            ->assertJsonPath('two_step.needs_phone', false)->assertJsonPath('two_step.phone', '+251******678')->assertJsonPath('two_step.error', null);
        $this->assertSame('+251912345678', $this->sms->lastTo);
        $user = User::sole();
        $this->assertNull($user->phone_verified_at);

        $this->json2('/api/auth/two-step', ['challenge' => $res->json('two_step.challenge'), 'code' => $this->sms->code()])->assertOk()
            ->assertJsonStructure(['token'])->assertJsonPath('user.phone_verified', true)->assertJsonPath('user.phone', '+251912345678')
            ->assertJsonPath('user.two_step_required', true);
        // A brand-new account gets no "phone added" email.
        Notification::assertNothingSent();
    }

    public function test_a_store_application_also_needs_the_code(): void
    {
        $this->seed();
        $area = Area::where('is_active', true)->firstOrFail();
        $res = $this->json2('/api/auth/register-seller', [
            'name' => 'Kebede Alemu', 'email' => 'kebede@example.com', 'phone' => '0922 111 222',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'store_name' => 'Kebede Woodworks', 'business_type' => 'workshop', 'store_phone' => '0922 111 222', 'area_id' => $area->id,
        ])->assertCreated()->assertJsonMissingPath('token');
        $this->json2('/api/auth/two-step', ['challenge' => $res->json('two_step.challenge'), 'code' => $this->sms->code()])->assertOk()
            ->assertJsonPath('user.role', 'seller');
        $this->assertNotNull(City::first());
    }

    public function test_when_the_sign_up_text_fails_the_account_waits_and_can_try_again_at_once(): void
    {
        $this->sms->delivers = true;
        $this->sms->fail = new SmsFailed('refused', retryable: false);
        $res = $this->register()->assertCreated()
            ->assertJsonPath('two_step.error', 'The text with your code could not be sent. Try again in a minute.')
            ->assertJsonPath('two_step.resend_in', 0);
        $this->sms->fail = null;

        $this->json2('/api/auth/two-step/resend', ['challenge' => $res->json('two_step.challenge')])->assertOk();
        $this->json2('/api/auth/two-step', ['challenge' => $res->json('two_step.challenge'), 'code' => $this->sms->code()])->assertOk();
    }

    public function test_an_existing_account_without_a_checked_phone_adds_one_at_sign_in(): void
    {
        // A phone typed long ago but never checked is not texted unasked; the person confirms the number.
        $user = User::factory()->create(['email' => 'old@example.com', 'phone' => '0911 000 001']);
        $this->travel(2)->days();
        $res = $this->json2('/api/auth/login', ['email' => 'old@example.com', 'password' => 'password'])->assertOk()
            ->assertJsonPath('two_step.needs_phone', true)->assertJsonPath('two_step.expires_in', 900);
        $challenge = $res->json('two_step.challenge');
        $this->assertSame(0, $this->sms->calls);

        // A code typed before any number was given cannot work.
        $this->json2('/api/auth/two-step', ['challenge' => $challenge, 'code' => '000000'])->assertUnprocessable();
        $this->json2('/api/auth/two-step/resend', ['challenge' => $challenge])->assertJsonPath('errors.phone.0', 'Enter your mobile number first.');

        // A typo: the second number waits out the minute, and the first number's code stops working.
        $this->json2('/api/auth/two-step/phone', ['challenge' => $challenge, 'phone' => '0911 000 009'])->assertOk();
        $typo = $this->sms->code();
        $this->json2('/api/auth/two-step/phone', ['challenge' => $challenge, 'phone' => '0911 000 001'])->assertStatus(429);
        $this->travel(61)->seconds();
        $this->json2('/api/auth/two-step/phone', ['challenge' => $challenge, 'phone' => '0911 000 001'])->assertOk()->assertJsonPath('two_step.resends_left', 1);
        $this->assertSame('+251911000001', $this->sms->lastTo);
        if ($typo !== $this->sms->code()) {
            $this->json2('/api/auth/two-step', ['challenge' => $challenge, 'code' => $typo])->assertUnprocessable();
        }
        $this->json2('/api/auth/two-step', ['challenge' => $challenge, 'code' => $this->sms->code()])->assertOk()->assertJsonStructure(['token']);

        $this->assertSame('+251911000001', $user->fresh()->twoStepPhone());
        Notification::assertSentOnDemand(SecurityNotice::class, fn (SecurityNotice $n) => $n->kind === 'phone_added');

        // From now on the code goes straight to the checked phone.
        $this->json2('/api/auth/login', ['email' => 'old@example.com', 'password' => 'password'])->assertJsonPath('two_step.needs_phone', false)
            ->assertJsonPath('two_step.phone', '+251******001');
    }

    public function test_the_number_step_expires_and_cannot_be_used_once_a_phone_is_checked(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $challenge = $this->json2('/api/auth/login', ['email' => 'old@example.com', 'password' => 'password'])->json('two_step.challenge');
        $this->travel(16)->minutes();
        $this->json2('/api/auth/two-step/phone', ['challenge' => $challenge, 'phone' => '0911 000 001'])->assertJsonPath('errors.code.0', 'This code has expired. Start again.');

        $challenge = $this->json2('/api/auth/login', ['email' => 'old@example.com', 'password' => 'password'])->json('two_step.challenge');
        $user->forceFill(['phone' => '+251911000005', 'phone_verified_at' => now()])->save();
        $this->json2('/api/auth/two-step/phone', ['challenge' => $challenge, 'phone' => '0911 000 001'])->assertJsonPath('errors.code.0', 'This account already has a checked phone. Sign in again.');
        $this->assertSame(0, $this->sms->calls);
    }

    public function test_one_sign_in_cannot_text_many_numbers(): void
    {
        User::factory()->create(['email' => 'old@example.com']);
        $challenge = $this->json2('/api/auth/login', ['email' => 'old@example.com', 'password' => 'password'])->json('two_step.challenge');
        foreach (['0911000001', '0911000002', '0911000003'] as $phone) {
            $this->json2('/api/auth/two-step/phone', ['challenge' => $challenge, 'phone' => $phone])->assertOk();
            $this->travel(61)->seconds();
        }
        $this->json2('/api/auth/two-step/phone', ['challenge' => $challenge, 'phone' => '0911000004'])->assertStatus(429);
        $this->assertSame(3, $this->sms->calls);
    }

    public function test_nobody_can_turn_it_off_and_a_checked_phone_signs_in_with_a_code(): void
    {
        $user = User::factory()->create(['email' => 'c@example.com', 'phone' => '+251911222333', 'phone_verified_at' => now(), 'two_step_enabled_at' => now()]);
        $this->json2('/api/auth/login', ['email' => 'c@example.com', 'password' => 'password'])->assertJsonPath('two_step.phone', '+251******333');

        $token = $user->createToken('web')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/account/two-step', ['enabled' => false, 'current_password' => 'password'])
            ->assertJsonPath('errors.enabled.0', 'Two-step sign-in is required for every account on this site.');
        $this->withToken($token)->getJson('/api/auth/me')->assertJsonPath('data.two_step_required', true);
    }

    public function test_with_the_switch_off_sign_up_stays_as_before(): void
    {
        Setting::where('key', 'everyone_must_use_two_step')->update(['value' => '0']);
        $this->register(['phone' => null])->assertCreated()->assertJsonStructure(['token']);
        $this->assertSame(0, $this->sms->calls);
    }

    public function test_the_switch_needs_codes_that_reach_phones(): void
    {
        Setting::where('key', 'everyone_must_use_two_step')->delete();
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('web')->plainTextToken;
        $this->app['env'] = 'production';
        try {
            $this->withToken($token)->putJson('/api/admin/settings', ['everyone_must_use_two_step' => true])
                ->assertJsonValidationErrors('everyone_must_use_two_step');
            // And on a live site without a provider, sign-up does not ask for a code nobody can get.
            $this->assertFalse($this->getJson('/api/config')->json('phone_required'));
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->withToken($token)->putJson('/api/admin/settings', ['everyone_must_use_two_step' => true])->assertOk()
            ->assertJsonPath('data.everyone_must_use_two_step', true)->assertJsonPath('meta.accounts_without_checked_phone', 1);
        $this->assertTrue(SignInCode::query()->doesntExist());
    }
}
