<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\ReportController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    public function test_summary_uses_addis_ababa_days(): void
    {
        // 22:30 UTC on 1 March is 01:30 on 2 March in Addis Ababa.
        $this->travelTo(Carbon::parse('2026-03-01 22:30:00', 'UTC'));
        $this->deliver($this->placeOrder(2));
        $cancel = $this->placeOrder(1);
        $this->as($this->buyer)->postJson("/api/orders/{$cancel->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->travelTo(Carbon::parse('2026-03-05 09:00:00', 'UTC'));
        $this->placeOrder(1); // still open

        $admin = $this->admin();
        $res = $this->as($admin)->getJson('/api/admin/reports/summary?from=2026-03-02&to=2026-03-05')->assertOk()
            ->assertJsonPath('data.timezone', 'Africa/Addis_Ababa')
            ->assertJsonPath('data.placed.orders', 3)
            ->assertJsonPath('data.delivered.orders', 1)
            ->assertJsonPath('data.delivered.gross_santim', 2_030_000)
            ->assertJsonPath('data.delivered.items_santim', 2_000_000)
            ->assertJsonPath('data.delivered.delivery_fees_santim', 30_000)
            ->assertJsonPath('data.delivered.commission_santim', 200_000)
            ->assertJsonPath('data.cancelled.by_customer', 1)
            ->assertJsonPath('data.top_sellers.0.store_name', $this->seller->store_name)
            ->assertJsonCount(4, 'data.days');
        $this->assertSame(['date' => '2026-03-02', 'orders' => 1, 'gross_santim' => 2_030_000, 'commission_santim' => 200_000], $res->json('data.days.0'));

        // The same moment is 1 March in UTC but must not fall into a range ending 1 March Addis time.
        $this->getJson('/api/admin/reports/summary?from=2026-02-25&to=2026-03-01')->assertOk()
            ->assertJsonPath('data.placed.orders', 0)->assertJsonPath('data.delivered.orders', 0);
    }

    public function test_range_validation(): void
    {
        $this->as($this->admin());
        $this->getJson('/api/admin/reports/summary?from=2026-03-05&to=2026-03-01')->assertUnprocessable();
        $this->getJson('/api/admin/reports/summary?from=2024-01-01&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/admin/reports/summary?from=03/01/2026')->assertUnprocessable();
        $this->getJson('/api/admin/reports/summary?from='.now('Africa/Addis_Ababa')->addDay()->toDateString())->assertUnprocessable();
        $this->getJson('/api/admin/reports/summary')->assertOk()->assertJsonCount(30, 'data.days');
        $this->as($this->seller->user)->getJson('/api/admin/reports/summary')->assertForbidden();
        $this->getJson('/api/admin/reports/orders.csv')->assertForbidden();
    }

    public function test_csv_export_escapes_formulas(): void
    {
        $this->buyer->forceFill(['name' => '=HYPERLINK("http://evil","x")'])->save();
        $order = $this->deliver($this->placeOrder(1));

        $res = $this->as($this->admin())->get('/api/admin/reports/orders.csv?from='.now('Africa/Addis_Ababa')->toDateString());
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $row = str_getcsv($lines[1], escape: '');
        $this->assertSame($order->number, $row[0]);
        $this->assertSame('delivered', $row[2]);
        $this->assertSame("'=HYPERLINK(\"http://evil\",\"x\")", $row[4]);
        $this->assertSame(['10000.00', '300.00', '10300.00', '0.00', '0.00', '', '10.00', '1000.00'], array_slice($row, 7, 8));
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);

        $this->assertSame("'-5", ReportController::cell('-5'));
        $this->assertSame("'@x", ReportController::cell('@x'));
        $this->assertSame('plain', ReportController::cell('plain'));
        $this->assertSame('', ReportController::cell(null));
    }
}
