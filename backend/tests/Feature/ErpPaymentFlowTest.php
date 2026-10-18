<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SellerLedgerEntry;
use App\Payments\PaymentGateway;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Online payments through to the store's books, with the test payment page standing in for the
 * provider: paid orders, an order cancelled after payment (refunded by the marketplace), an unpaid
 * order that runs out of time, and a payout. Each amount is counted once, on the right day.
 */
class ErpPaymentFlowTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.payments.gateway' => 'fake', 'marketplace.payments.window_minutes' => 30]);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->setUpOrders();
        Cache::flush();
        $this->sofa->forceFill(['cost_price_santim' => 600_000])->save();
    }

    private function payOnline(int $qty, string $outcome = 'paid'): Order
    {
        CartItem::where('user_id', $this->buyer->id)->delete();
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => $qty])->assertCreated();
        $expected = $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.grand_total_santim');
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'online', 'expected_total_santim' => $expected],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $order = Order::latest('id')->first();
        if ($outcome !== 'none') {
            $payment = Payment::where('checkout_id', $order->checkout_id)->latest('id')->first();
            $this->postJson("/api/payments/test/{$payment->tx_ref}", ['outcome' => $outcome])->assertOk();
        }

        return $order->fresh();
    }

    private function finance(): array
    {
        return $this->as($this->seller->user)->getJson('/api/seller/erp/finance?period=30d')->assertOk()->json('data');
    }

    private function salesList(): array
    {
        return $this->as($this->seller->user)->getJson('/api/seller/erp/sales?source=marketplace')->assertOk()->collect('data')->pluck('id')->all();
    }

    public function test_online_payments_refunds_and_payouts_reach_the_books_once(): void
    {
        // Unpaid: not the store's sale, nothing in the books, stock held for the customer.
        $unpaid = $this->payOnline(1, 'none');
        $this->assertNotContains($unpaid->id, $this->salesList());
        $this->as($this->seller->user)->getJson("/api/seller/erp/sales/orders/{$unpaid->id}")->assertNotFound();
        $this->assertSame(49, $this->sofa->fresh()->stock_quantity);

        // A failed payment does not make it a sale either.
        $payment = Payment::where('checkout_id', $unpaid->checkout_id)->latest('id')->first();
        $this->as($this->buyer)->postJson("/api/payments/test/{$payment->tx_ref}", ['outcome' => 'failed'])->assertOk();
        $this->assertNotContains($unpaid->id, $this->salesList());

        // It runs out of time: cancelled by the system, stock back, never in the books.
        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-payments')->assertSuccessful();
        $this->assertSame('cancelled', $unpaid->fresh()->status->value);
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);

        // Paid: now it is the store's, but counts as a sale only once delivered.
        $paid = $this->payOnline(2);
        $this->assertSame('paid', $paid->payment_status);
        $this->assertContains($paid->id, $this->as($this->seller->user)->getJson('/api/seller/orders')->collect('data')->pluck('id')->all());
        $this->assertNotContains($paid->id, $this->salesList(), 'a sale once delivered');
        $f = $this->finance();
        $this->assertSame(0, $f['total']['gross_sales_santim']);
        $this->assertSame($paid->total_santim, $f['marketplace']['online_waiting_santim']);
        $this->assertSame(1, $f['marketplace']['online_waiting_orders']);
        $this->assertSame(0, $f['total']['money_in_santim'], 'online money is the marketplace\'s until paid out');

        $this->deliver($paid);
        $paid->refresh();
        $this->assertContains($paid->id, $this->salesList());
        $commission = $paid->commission_santim;
        $this->assertGreaterThan(0, $commission);
        $f = $this->finance();
        $this->assertSame(2_000_000, $f['total']['gross_sales_santim']);
        $this->assertSame($paid->delivery_fee_santim, $f['total']['delivery_santim']);
        $this->assertSame($commission, $f['total']['commission_santim']);
        $this->assertSame(2_000_000 - 1_200_000, $f['total']['gross_profit_santim']);
        $this->assertSame(0, $f['marketplace']['online_waiting_santim']);
        $this->assertSame($paid->total_santim, $f['marketplace']['period']['online_sales_credited_santim']);
        $this->assertSame($commission, $f['marketplace']['period']['commission_charged_santim']);
        // The marketplace owes the store what the customer paid, less commission.
        $this->assertSame(-($paid->total_santim - $commission), $f['marketplace']['now']['balance_santim']);
        $this->assertSame(0, $f['total']['money_in_santim'], 'still not the store\'s cash: no payout yet');

        // Paid, then cancelled by the store: the marketplace owes the customer a refund; nothing for the store.
        $cancelled = $this->payOnline(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$cancelled->id}/cancel", ['reason' => 'Out of stock'])->assertOk();
        $refund = Refund::where('order_id', $cancelled->id)->sole();
        $this->assertSame('due', $refund->status);
        $this->assertSame($cancelled->total_santim, $refund->amount_santim);
        $row = collect($this->as($this->seller->user)->getJson('/api/seller/erp/reports/returns?period=30d')->assertOk()->json('data'))->firstWhere('document', $cancelled->number);
        $this->assertSame(['cancelled_order', 'marketplace', 'refund_due', $cancelled->total_santim],
            [$row['kind'], $row['refund_by'], $row['status'], $row['money_back_santim']]);
        $f2 = $this->finance();
        foreach (['gross_sales_santim', 'commission_santim', 'net_profit_santim', 'money_in_santim', 'money_out_santim'] as $field) {
            $this->assertSame($f['total'][$field], $f2['total'][$field], "{$field} must not change when the marketplace refunds an online order");
        }
        $this->assertSame($f['marketplace']['now']['balance_santim'], $f2['marketplace']['now']['balance_santim']);

        // The marketplace pays the refund: the report says so; the store's books still do not move.
        $this->as($this->admin())->postJson("/api/admin/refunds/{$refund->id}/complete", ['method' => 'telebirr', 'reference' => 'TB-555'])->assertOk();
        $row = collect($this->as($this->seller->user)->getJson('/api/seller/erp/reports/returns?period=30d')->json('data'))->firstWhere('document', $cancelled->number);
        $this->assertSame('refund_refunded', $row['status']);
        $this->assertSame($f['total']['money_out_santim'], $this->finance()['total']['money_out_santim']);

        // The payout: money in for the store, the balance settles, and sales stay what they were.
        $owed = -LedgerService::balance($this->seller->id);
        $this->as($this->admin())->postJson("/api/admin/settlements/{$this->seller->id}/payouts", ['amount' => (string) ($owed / 100), 'method' => 'bank_transfer', 'reference' => 'TR-9'])->assertCreated();
        $f3 = $this->finance();
        $this->assertSame($owed, $f3['total']['money_in_santim']);
        $this->assertSame($owed, $f3['marketplace']['period']['payouts_received_santim']);
        $this->assertSame(0, $f3['marketplace']['now']['balance_santim']);
        foreach (['gross_sales_santim', 'commission_santim', 'gross_profit_santim', 'net_profit_santim'] as $field) {
            $this->assertSame($f['total'][$field], $f3['total'][$field], "{$field}: the payout is the same money as the sale, not more income");
        }
        // The overview agrees with the finance page.
        $overview = $this->as($this->seller->user)->getJson('/api/seller/erp/overview?period=30d')->assertOk()->json('data.sales.finance');
        $this->assertSame($f3['total']['net_profit_santim'], $overview['net_profit_santim']);
        $this->assertSame($f3['total']['gross_profit_santim'], $overview['gross_profit_santim']);
    }

    public function test_paying_twice_or_too_late_never_makes_a_second_sale(): void
    {
        $order = $this->payOnline(1);
        $payment = Payment::where('checkout_id', $order->checkout_id)->latest('id')->first();
        // The provider tells us again (a retried webhook): still one paid order, one ledger credit after delivery.
        $this->as($this->buyer)->postJson("/api/payments/test/{$payment->tx_ref}", ['outcome' => 'paid'])->assertOk();
        $this->deliver($order->fresh());
        $f = $this->finance();
        $this->assertSame(1, $f['total']['marketplace_orders']);
        $this->assertSame($order->fresh()->total_santim, $f['marketplace']['period']['online_sales_credited_santim']);
        $this->assertSame(1, SellerLedgerEntry::where('order_id', $order->id)->where('type', 'sale')->count());
    }
}
