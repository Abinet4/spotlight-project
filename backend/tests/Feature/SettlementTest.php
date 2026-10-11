<?php

namespace Tests\Feature;

use App\Models\SellerLedgerEntry;
use App\Models\Setting;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    public function test_delivered_cash_order_charges_commission_once(): void
    {
        $order = $this->placeOrder(2);
        $this->as($this->seller->user)->getJson('/api/seller/balance')->assertOk()->assertJsonPath('data.balance_santim', 0);

        $this->deliver($order);
        $this->assertSame(200_000, $order->fresh()->commission_santim);
        $this->getJson('/api/seller/balance')->assertOk()
            ->assertJsonPath('data.balance_santim', 200_000)
            ->assertJsonPath('data.commission_total_santim', 200_000)
            ->assertJsonPath('data.over_limit', false);
        $this->getJson('/api/seller/balance/entries')->assertOk()
            ->assertJsonPath('data.0.type', 'commission')
            ->assertJsonPath('data.0.order.number', $order->number)
            ->assertJsonMissingPath('data.0.recorded_by');

        // A delivered order is final, and the ledger refuses a second charge for the same order.
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertUnprocessable();
        app(LedgerService::class)->chargeCommission($order->fresh());
        $this->assertSame(1, SellerLedgerEntry::where('order_id', $order->id)->count());
    }

    public function test_cancelled_and_undelivered_orders_owe_nothing(): void
    {
        $open = $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$open->id}/confirm")->assertOk();
        $cancelled = $this->placeOrder(1);
        $this->as($this->buyer)->postJson("/api/orders/{$cancelled->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();

        $this->assertSame(0, SellerLedgerEntry::count());
        $this->as($this->seller->user)->getJson('/api/seller/balance')->assertJsonPath('data.balance_santim', 0);
    }

    public function test_zero_commission_creates_no_entry(): void
    {
        Setting::updateOrCreate(['key' => 'commission_rate_percent'], ['value' => '0']);
        $this->deliver($this->placeOrder(1));
        $this->assertSame(0, SellerLedgerEntry::count());
    }

    public function test_admin_records_payments_with_reference_checks(): void
    {
        $this->deliver($this->placeOrder(2)); // owes 2,000 ETB
        $admin = $this->admin();
        $url = "/api/admin/settlements/{$this->seller->id}";

        $this->as($admin)->getJson('/api/admin/settlements')->assertOk()
            ->assertJsonPath('data.0.id', $this->seller->id)
            ->assertJsonPath('data.0.balance_santim', 200_000)
            ->assertJsonPath('meta.outstanding_santim', 200_000);

        // Validation: bank transfers need a reference; no zero, negative or over-balance payments.
        $this->postJson("$url/payments", ['amount' => '500', 'method' => 'bank_transfer'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson("$url/payments", ['amount' => '0', 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("$url/payments", ['amount' => '-5', 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("$url/payments", ['amount' => '2000.01', 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("$url/payments", ['amount' => '500', 'method' => 'crypto'])->assertUnprocessable()->assertJsonValidationErrors('method');

        $this->postJson("$url/payments", ['amount' => '1500.50', 'method' => 'bank_transfer', 'reference' => ' ft2610abc ', 'note' => 'CBE'])->assertCreated()
            ->assertJsonPath('data.balance_santim', 49_950)
            ->assertJsonPath('data.paid_total_santim', 150_050)
            ->assertJsonPath('entries.data.0.reference', 'FT2610ABC')
            ->assertJsonPath('entries.data.0.recorded_by', $admin->name);
        // The same reference again (any case) is refused, so a transfer cannot be counted twice.
        $this->postJson("$url/payments", ['amount' => '10', 'method' => 'bank_transfer', 'reference' => 'FT2610ABC'])->assertUnprocessable()->assertJsonValidationErrors('reference');

        $this->postJson("$url/payments", ['amount' => '499.50', 'method' => 'cash'])->assertCreated()->assertJsonPath('data.balance_santim', 0);
        $this->getJson('/api/admin/settlements?owing=1')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.collected_santim', 200_000);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settlement.payment_recorded', 'subject_id' => $this->seller->id]);
    }

    public function test_adjustments_need_a_reason_and_can_credit(): void
    {
        $this->deliver($this->placeOrder(1)); // owes 1,000 ETB
        $url = "/api/admin/settlements/{$this->seller->id}/adjustments";
        $this->as($this->admin());

        $this->postJson($url, ['amount' => '-200'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($url, ['amount' => '0', 'note' => 'Nothing at all'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($url, ['amount' => '-200', 'note' => 'Goodwill for a late payout'])->assertCreated()->assertJsonPath('data.balance_santim', 80_000);
        $this->postJson($url, ['amount' => '-1000', 'note' => 'Promotion credit'])->assertCreated()->assertJsonPath('data.balance_santim', -20_000);
        // A store in credit is not "outstanding" and cannot be paid against.
        $this->getJson('/api/admin/settlements')->assertJsonPath('meta.outstanding_santim', 0);
        $this->postJson("/api/admin/settlements/{$this->seller->id}/payments", ['amount' => '1', 'method' => 'cash'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settlement.adjusted']);
    }

    public function test_credit_limit_blocks_confirming_new_orders_until_paid(): void
    {
        $this->deliver($this->placeOrder(2)); // owes 2,000 ETB
        $admin = $this->admin();
        $this->as($admin)->putJson('/api/admin/settings', ['commission_credit_limit_birr' => 1500, 'settlement_instructions' => 'Pay to CBE 1000123456 with your store name.'])->assertOk()
            ->assertJsonPath('data.commission_credit_limit_birr', 1500);

        $next = $this->placeOrder(1);
        $this->as($this->seller->user)->getJson('/api/seller/balance')
            ->assertJsonPath('data.over_limit', true)
            ->assertJsonPath('data.credit_limit_santim', 150_000)
            ->assertJsonPath('data.instructions', 'Pay to CBE 1000123456 with your store name.');
        $this->postJson("/api/seller/orders/{$next->id}/confirm")->assertUnprocessable()->assertJsonValidationErrors('status');
        // Cancelling is still allowed, so customers are never stuck.
        $this->as($this->buyer)->getJson("/api/orders/{$next->id}")->assertJsonPath('data.status', 'pending_confirmation');

        $this->as($admin)->postJson("/api/admin/settlements/{$this->seller->id}/payments", ['amount' => '600', 'method' => 'cash'])->assertCreated();
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$next->id}/confirm")->assertOk();

        // 0 turns the limit off again.
        $this->as($admin)->putJson('/api/admin/settings', ['commission_credit_limit_birr' => 0])->assertOk();
        $this->as($admin)->putJson('/api/admin/settings', ['commission_credit_limit_birr' => -1])->assertUnprocessable();
    }

    public function test_ledger_is_private(): void
    {
        $this->deliver($this->placeOrder(1));
        $other = $this->approvedSeller();
        $this->as($other->user)->getJson('/api/seller/balance')->assertOk()->assertJsonPath('data.balance_santim', 0);
        $this->getJson('/api/seller/balance/entries')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/settlements')->assertForbidden();
        $this->as($this->buyer)->getJson('/api/seller/balance')->assertForbidden();
        $this->postJson("/api/admin/settlements/{$this->seller->id}/payments", ['amount' => '1', 'method' => 'cash'])->assertForbidden();
        $this->as($this->admin())->getJson('/api/admin/settlements/999999')->assertNotFound();
    }

    public function test_seller_order_summary_includes_balance(): void
    {
        $this->deliver($this->placeOrder(1));
        $this->as($this->seller->user)->getJson('/api/seller/orders/summary')->assertOk()
            ->assertJsonPath('data.balance.balance_santim', 100_000);
        $this->as($this->admin())->getJson('/api/admin/summary')->assertOk()
            ->assertJsonPath('data.money.commission_outstanding_santim', 100_000);
    }
}
