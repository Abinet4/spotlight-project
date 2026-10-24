<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Checkout;
use App\Models\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\ReadsWorkbooks;
use Tests\TestCase;

/**
 * Each admin download as Excel holds exactly what its CSV holds, cell for cell, and the values a CSV loses in
 * Excel (leading zeros, phone numbers, Amharic) come through as text.
 */
class ExcelExportTest extends TestCase
{
    use PlacesOrders, ReadsWorkbooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    public function test_orders_download_has_every_detail_in_both_formats(): void
    {
        $this->buyer->forceFill(['name' => 'አበበ ከበደ', 'email' => 'abebe@example.com'])->save();
        $this->seller->forceFill(['vat_number' => '0012345678'])->save();
        $order = $this->deliver($this->placeOrder(2));
        $order->forceFill(['customer_note' => '=call before', 'seller_note' => 'Fragile'])->save();
        Checkout::whereKey($order->checkout_id)->update(['landmark' => 'Near Edna Mall', 'floor' => 3]);
        $cancelled = $this->placeOrder(1);
        $this->as($this->buyer)->postJson("/api/orders/{$cancelled->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();

        $this->as($this->admin());
        $rows = $this->assertSameAsCsv('/api/admin/reports/orders');
        $header = $rows[0];
        $this->assertCount(39, $header);
        $this->assertSame(['order', 'placed_at', 'status'], array_slice($header, 0, 3), 'the first columns keep their place');
        $byNumber = collect(array_slice($rows, 1))->keyBy(0)->map(fn ($r) => array_combine($header, array_pad($r, count($header), '')));
        $row = $byNumber[$order->number];
        $this->assertSame('አበበ ከበደ', $row['customer']);
        $this->assertSame('abebe@example.com', $row['customer_email']);
        $this->assertSame('Abebe Kebede', $row['recipient']);
        $this->assertSame('+251911000111', $row['phone']);
        $this->assertSame('House 12', $row['address']);
        $this->assertSame('Near Edna Mall', $row['landmark']);
        $this->assertSame('3', $row['floor']);
        $this->assertSame('2 x '.$this->sofa->name, $row['items']);
        $this->assertSame('2', $row['item_count']);
        $this->assertSame('=call before', $row['customer_note']);
        $this->assertSame('Fragile', $row['store_note']);
        $this->assertNotSame('', $row['confirmed_at']);
        $this->assertSame('Changed my mind', $byNumber[$cancelled->number]['cancel_reason']);
        $this->assertSame('customer', $byNumber[$cancelled->number]['cancelled_by']);
    }

    public function test_audit_log_download_keeps_every_field_and_follows_the_filters(): void
    {
        $admin = $this->admin();
        $this->as($admin)->putJson('/api/admin/settings', ['vat_rate_percent' => 15])->assertOk();
        $this->putJson('/api/admin/settings', ['vat_rate_percent' => 15])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'settings.updated')->count(), 'a save that changes nothing is not logged');
        $this->deliver($this->placeOrder(1));
        $long = AuditLog::create(['user_id' => $admin->id, 'action' => 'seller.approve', 'subject_type' => $this->seller->getMorphClass(),
            'subject_id' => $this->seller->id, 'meta' => ['note' => str_repeat('ቃ', 400), 'nested' => ['a' => ['b' => 1]]], 'ip_address' => '10.0.0.7']);

        $this->as($admin);
        $before = AuditLog::count();
        // The CSV download is itself logged; take that row out so both files see the same log.
        $rows = $this->assertSameAsCsv('/api/admin/audit-logs', fn () => AuditLog::where('action', 'audit.exported')->delete());
        $header = $rows[0];
        $this->assertSame($before, count($rows) - 1, 'every row, and not the download itself');
        $byId = collect(array_slice($rows, 1))->keyBy(0)->map(fn ($r) => array_combine($header, array_pad($r, count($header), '')));
        $row = $byId[$long->id];
        $this->assertSame($admin->email, $row['who_email']);
        $this->assertSame('Store: approve', $row['action']);
        $this->assertSame('seller.approve', $row['action_code']);
        $this->assertSame('Store: '.$this->seller->store_name.' (#'.$this->seller->id.')', $row['subject']);
        $this->assertSame(json_encode($long->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['details_full']);
        $this->assertSame('10.0.0.7', $row['ip_address']);
        $settings = collect($byId)->firstWhere('action_code', 'settings.updated');
        $this->assertSame('Changed site settings', $settings['action']);
        $this->assertSame('vat rate percent: 0 to 15', $settings['details']);

        $filtered = $this->workbookRows($this->get('/api/admin/audit-logs.xlsx?action=settings.')->streamedContent());
        $this->assertCount(2, $filtered);
        $this->assertSame(0, count($this->workbookRows($this->get('/api/admin/audit-logs.xlsx?q=nobody-here')->streamedContent())) - 1);
        $this->get('/api/admin/audit-logs.xlsx?from=2026-13-01')->assertUnprocessable();

        $page = $this->getJson('/api/admin/audit-logs?action=seller.')->assertOk();
        $this->assertSame('Store: approve', $page->json('data.0.action_label'));
        $this->assertSame('Store: '.$this->seller->store_name.' (#'.$this->seller->id.')', $page->json('data.0.subject_label'));
        $this->assertArrayHasKey('support', $page->json('groups'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.exported']);

        $this->as($this->seller->user)->get('/api/admin/audit-logs.xlsx')->assertForbidden();
    }

    public function test_text_messages_download_explains_failures_and_keeps_the_provider_error(): void
    {
        $error = 'SMS Ethiopia refused the SMS (HTTP 400): DEFAULT_CAMPAIGN_RECIPIENT_NOT_WHITELISTED: This is a starter (default) campaign and can only deliver to numbers verified in the dashboard.';
        SmsMessage::create(['user_id' => $this->buyer->id, 'kind' => 'order.confirmed', 'to' => '+251911000111', 'body' => 'ትዕዛዝዎ ተረጋግጧል BF-1',
            'driver' => 'smsethiopia', 'status' => 'failed', 'error' => $error, 'attempts' => 1]);
        SmsMessage::create(['user_id' => $this->buyer->id, 'kind' => 'sign_in.code', 'to' => '+251911000222', 'body' => 'Code 0042',
            'driver' => 'smsethiopia', 'status' => 'sent', 'provider_message_id' => '000123', 'attempts' => 1, 'sent_at' => now()]);

        $this->as($this->admin());
        $rows = $this->assertSameAsCsv('/api/admin/sms');
        $header = $rows[0];
        $all = collect(array_slice($rows, 1))->map(fn ($r) => array_combine($header, array_pad($r, count($header), '')));
        $failed = $all->firstWhere('status', 'failed');
        $this->assertSame($error, $failed['provider_error']);
        $this->assertStringContainsString('starter package', $failed['problem']);
        $this->assertSame('+251911000111', $failed['to']);
        $this->assertSame('ትዕዛዝዎ ተረጋግጧል BF-1', $failed['message']);
        $this->assertSame('000123', $all->firstWhere('status', 'sent')['provider_message_id']);

        $this->assertCount(2, $this->workbookRows($this->get('/api/admin/sms.xlsx?status=failed')->streamedContent()));
        $page = $this->getJson('/api/admin/sms?status=failed')->assertOk();
        $this->assertStringContainsString('starter package', $page->json('data.0.explanation'));
        $this->assertSame($error, $page->json('data.0.error'));
    }
}
