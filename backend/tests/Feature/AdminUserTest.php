<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    public function test_search_and_filters(): void
    {
        $this->buyer->forceFill(['name' => 'Selam 100%_Mekonnen', 'email' => 'selam@example.com'])->save();
        $this->as($this->admin());

        $this->getJson('/api/admin/users?q=SELAM')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'selam@example.com');
        // LIKE wildcards in the search are literal.
        $this->getJson('/api/admin/users?q=%25_')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/users?q=_')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/users?role=seller')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.store.id', $this->seller->id);
        $this->getJson('/api/admin/users?role=wizard')->assertUnprocessable();
    }

    public function test_suspend_signs_out_and_blocks_then_reactivate(): void
    {
        $this->deliver($this->placeOrder(1));
        $token = $this->buyer->createToken('phone')->plainTextToken;
        $admin = $this->admin();

        $this->as($admin)->getJson("/api/admin/users/{$this->buyer->id}")->assertOk()
            ->assertJsonPath('data.orders.count', 1)
            ->assertJsonPath('data.orders.delivered_spend_santim', 1_030_000);
        $this->postJson("/api/admin/users/{$this->buyer->id}/suspend")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/admin/users/{$this->buyer->id}/suspend", ['reason' => 'Repeated fake orders'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/admin/users/{$this->buyer->id}/suspend", ['reason' => 'Again'])->assertUnprocessable();
        $this->assertSame(0, $this->buyer->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/orders')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $this->buyer->email, 'password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->as($admin)->postJson("/api/admin/users/{$this->buyer->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.suspended', 'subject_id' => $this->buyer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.reactivated', 'subject_id' => $this->buyer->id]);
    }

    public function test_suspending_a_seller_account_hides_their_listings(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/products/{$this->sofa->slug}")->assertOk();
        $this->as($this->admin())->postJson("/api/admin/users/{$this->seller->user_id}/suspend", ['reason' => 'Fraud report'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/products/{$this->sofa->slug}")->assertNotFound();
    }

    public function test_admins_cannot_be_suspended_here_and_only_admins_manage(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $this->as($admin)->postJson("/api/admin/users/{$other->id}/suspend", ['reason' => 'Testing'])->assertUnprocessable();
        $this->postJson("/api/admin/users/{$admin->id}/suspend", ['reason' => 'Testing'])->assertUnprocessable();
        $this->postJson('/api/admin/users/999999/suspend', ['reason' => 'Testing'])->assertNotFound();
        $this->postJson("/api/admin/users/{$this->buyer->id}/delete")->assertNotFound();

        $this->as($this->seller->user)->getJson('/api/admin/users')->assertForbidden();
        $this->as($this->buyer)->postJson("/api/admin/users/{$this->buyer->id}/reactivate")->assertForbidden();
        $this->assertSame('active', User::find($other->id)->status->value);
    }
}
