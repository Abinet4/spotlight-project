<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Abebe Kebede',
            'email' => 'abebe@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $overrides);
    }

    public function test_customer_can_register_and_receives_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonMissingPath('user.password');

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$response->json('token')])
            ->assertOk()
            ->assertJsonPath('data.email', 'abebe@example.com');
    }

    public function test_registration_cannot_self_assign_admin_role_or_status(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['role' => 'admin', 'status' => 'active']))
            ->assertCreated()
            ->assertJsonPath('user.role', 'customer');

        $this->assertSame('customer', User::firstWhere('email', 'abebe@example.com')->role->value);
    }

    public function test_registration_validates_password_strength_and_confirmation(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/auth/register', $this->registration(['password' => 'onlyletters', 'password_confirmation' => 'onlyletters']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/auth/register', $this->registration(['password_confirmation' => 'different1']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_email_uniqueness_is_case_insensitive(): void
    {
        $this->postJson('/api/auth/register', $this->registration())->assertCreated();

        $this->postJson('/api/auth/register', $this->registration(['email' => 'ABEBE@Example.com']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_with_mixed_case_email_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'sara@example.com', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', ['email' => 'Sara@Example.com', 'password' => 'secret123'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'role']]);

        $this->postJson('/api/auth/login', ['email' => 'sara@example.com', 'password' => 'wrong-pass1'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'secret123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_suspended_user_cannot_log_in_or_use_existing_token(): void
    {
        $user = User::factory()->create(['email' => 'x@example.com', 'password' => 'secret123']);
        $token = $user->createToken('web')->plainTextToken;
        $user->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/auth/login', ['email' => 'x@example.com', 'password' => 'secret123'])
            ->assertUnprocessable();

        $this->getJson('/api/auth/me', ['Authorization' => "Bearer {$token}"])->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'rl@example.com', 'password' => 'secret123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'rl@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => 'rl@example.com', 'password' => 'secret123'])
            ->assertStatus(429);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->postJson('/api/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Reset the auth guard so the next request re-resolves the (now deleted) token.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_guest_gets_401_on_protected_routes(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/favorites')->assertUnauthorized();
        $this->getJson('/api/seller/products')->assertUnauthorized();
        $this->getJson('/api/admin/summary')->assertUnauthorized();
    }
}
