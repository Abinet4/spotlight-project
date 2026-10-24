<?php

namespace Tests\Feature;

use App\Support\Erp\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpWorld;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\ReadsWorkbooks;
use Tests\TestCase;

/** Every store download as Excel holds exactly what its CSV holds, with the store's real records in it. */
class ErpExcelExportTest extends TestCase
{
    use BuildsErpWorld, PlacesOrders, ReadsWorkbooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpErpWorld();
    }

    public function test_every_store_download_matches_its_csv(): void
    {
        $this->as($this->seller->user);
        $paths = ['inventory', 'movements', 'sales', 'expenses', 'finance'];
        foreach (array_keys(Reports::definitions()) as $report) {
            $paths[] = "reports/{$report}";
        }
        $withData = 0;
        foreach ($paths as $path) {
            $rows = $this->assertSameAsCsv('/api/seller/erp/'.$path, query: '?period=year');
            $this->assertNotEmpty($rows[0], $path.' has a header');
            $withData += count($rows) > 1 ? 1 : 0;
        }
        $this->assertGreaterThan(count($paths) / 2, $withData, 'most downloads have rows, so the comparison means something');

        $inventory = $this->workbookRows($this->get('/api/seller/erp/inventory.xlsx')->streamedContent());
        $this->assertContains('Alpha sofa', array_column($inventory, 0));
    }
}
