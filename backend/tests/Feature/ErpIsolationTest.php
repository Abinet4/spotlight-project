<?php

namespace Tests\Feature;

use App\Enums\SellerStatus;
use App\Models\Order;
use App\Support\Erp\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpWorld;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\ReadsWorkbooks;
use Tests\TestCase;

/**
 * Every ERP address, tried with one store's real record ids by another store, a customer, an admin, a
 * signed-out visitor and the store itself once suspended. Each attempt must be refused and leave every
 * table and stored file exactly as it was. The owner first sends the same request (then undone) to show
 * it was a valid one, so the refusal came from the ownership check and not from bad input.
 *
 * An ERP address with a record id that is not listed in routes() fails the first test, so a new one
 * cannot skip these checks.
 */
class ErpIsolationTest extends TestCase
{
    use BuildsErpWorld, PlacesOrders, ReadsWorkbooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpErpWorld();
    }

    public function test_every_erp_address_with_a_record_id_is_covered_here(): void
    {
        $listed = array_keys($this->routes());
        $withId = collect($this->erpRoutes())->filter(fn ($r) => str_contains($r[1], '{id}'))->map(fn ($r) => "{$r[0]} {$r[1]}")->values()->all();
        sort($listed);
        sort($withId);
        $this->assertSame($withId, $listed, 'An ERP address with a record id is missing from routes() (or listed but gone).');
        $this->assertCount(75, $this->erpRoutes(), 'The number of ERP addresses changed; check the new ones are scoped to the store and listed here.');
    }

    public function test_another_store_gets_not_found_for_every_record_and_changes_nothing(): void
    {
        foreach ($this->routes() as $key => [$kind]) {
            $body = $this->routes()[$key][1] ?? null;
            [$method, $uri] = explode(' ', $key, 2);

            // The owner can do it (proves the request itself is fine), then nothing of it is kept.
            $this->as($this->seller->user);
            $own = $this->sendAndUndo($method, $this->path($uri, $this->a, $kind), $body ? $body($this->a) : []);
            $this->assertTrue($own->isSuccessful(), "{$key}: the owner should be able to do this, got {$own->getStatusCode()}: ".$own->getContent());

            $before = $this->fingerprint();
            $this->as($this->other->user);
            $res = $this->send($method, $this->path($uri, $this->a, $kind), $body ? $body($this->a) : []);
            $this->assertSame(404, $res->getStatusCode(), "{$key} with another store's record: ".$res->getContent());
            $this->assertStringNotContainsString('Alpha', $res->getContent(), "{$key}: the answer names the other store's record");
            $this->assertSame($before, $this->fingerprint(), "{$key}: a refused request changed something");
        }
    }

    public function test_signed_out_visitors_customers_and_admins_are_refused_everywhere(): void
    {
        $who = ['signed out' => null, 'customer' => $this->buyer, 'admin' => $this->admin()];
        foreach ($this->erpRoutes() as [$method, $uri]) {
            $kind = $this->routes()["{$method} {$uri}"][0] ?? '';
            $body = ($this->routes()["{$method} {$uri}"][1] ?? fn () => [])($this->a);
            foreach ($who as $label => $user) {
                $this->app['auth']->forgetGuards();
                if ($user) {
                    $this->as($user);
                }
                $before = $this->fingerprint();
                $res = $this->send($method, $this->path($uri, $this->a, $kind), $body);
                $this->assertSame($user ? 403 : 401, $res->getStatusCode(), "{$label}: {$method} {$uri}");
                $this->assertStringNotContainsString('Alpha', $res->getContent());
                $this->assertSame($before, $this->fingerprint(), "{$label}: {$method} {$uri} changed something");
            }
        }
    }

    public function test_a_suspended_store_can_read_its_records_but_change_nothing(): void
    {
        $this->seller->forceFill(['status' => SellerStatus::Suspended])->save();
        $writes = 0;
        foreach ($this->erpRoutes() as [$method, $uri]) {
            $kind = $this->routes()["{$method} {$uri}"][0] ?? '';
            $body = ($this->routes()["{$method} {$uri}"][1] ?? $this->createBody($uri))($this->a);
            $this->as($this->seller->user);
            $before = $this->fingerprint();
            $res = $this->send($method, $this->path($uri, $this->a, $kind), $body);
            if ($method === 'GET') {
                $res->assertOk();

                continue;
            }
            $writes++;
            $this->assertSame(403, $res->getStatusCode(), "{$method} {$uri}: ".$res->getContent());
            $this->assertSame($before, $this->fingerprint(), "{$method} {$uri} changed something for a suspended store");
        }
        $this->assertSame(38, $writes);
    }

    public function test_the_create_bodies_used_above_are_accepted_from_an_active_store(): void
    {
        $this->as($this->seller->user);
        foreach (['settings' => 'PUT', 'warehouses' => 'POST', 'transfers' => 'POST', 'suppliers' => 'POST', 'purchases' => 'POST', 'sales' => 'POST', 'expenses' => 'POST', 'expense-categories' => 'POST'] as $uri => $method) {
            $res = $this->sendAndUndo($method, $uri, $this->createBody($uri)($this->a));
            $this->assertTrue($res->isSuccessful(), "{$method} {$uri}: {$res->getStatusCode()} ".$res->getContent());
        }
    }

    public function test_ids_of_another_store_inside_a_request_body_are_refused(): void
    {
        $a = $this->a;
        $b = $this->b;
        $line = fn (array $extra = []) => [['product_id' => $b['plain'], 'quantity' => 1, 'unit_cost' => '10', ...$extra]];
        $cases = [
            ['POST', 'purchases', ['supplier_id' => $a['supplier'], 'items' => $line()]],
            ['POST', 'purchases', ['supplier_id' => $b['supplier'], 'items' => [['product_id' => $a['plain'], 'quantity' => 1, 'unit_cost' => '10']]]],
            ['POST', 'purchases', ['supplier_id' => $b['supplier'], 'warehouse_id' => $a['room'], 'items' => $line()]],
            ['POST', 'purchases', ['supplier_id' => $b['supplier'], 'items' => [['product_id' => $b['options'], 'variant_id' => $a['variant'], 'quantity' => 1, 'unit_cost' => '10']]]],
            ['PUT', "purchases/{$b['draft_po']}", ['supplier_id' => $a['supplier'], 'items' => $line()]],
            ['PUT', "purchases/{$b['draft_po']}", ['supplier_id' => $b['supplier'], 'items' => [['product_id' => $a['plain'], 'quantity' => 1, 'unit_cost' => '10']]]],
            ['POST', "purchases/{$b['po']}/receive", ['items' => [['item_id' => $a['po_item'], 'quantity' => 1]]]],
            ['POST', "purchases/{$b['po']}/receive", ['warehouse_id' => $a['room'], 'items' => [['item_id' => $b['po_item'], 'quantity' => 1]]]],
            ['POST', 'transfers', ['from_warehouse_id' => $a['default'], 'to_warehouse_id' => $b['spare'], 'items' => [['product_id' => $b['plain'], 'quantity' => 1]]]],
            ['POST', 'transfers', ['from_warehouse_id' => $b['default'], 'to_warehouse_id' => $a['spare'], 'items' => [['product_id' => $b['plain'], 'quantity' => 1]]]],
            ['POST', 'transfers', ['from_warehouse_id' => $b['default'], 'to_warehouse_id' => $b['spare'], 'items' => [['product_id' => $a['plain'], 'quantity' => 1]]]],
            ['POST', 'transfers', ['from_warehouse_id' => $b['default'], 'to_warehouse_id' => $b['spare'], 'items' => [['product_id' => $b['options'], 'variant_id' => $a['variant'], 'quantity' => 1]]]],
            ['POST', "transfers/{$b['transfer']}/receive", ['items' => [['id' => $a['transfer_item'], 'received_quantity' => 1]]]],
            ['POST', 'sales', ['items' => [['product_id' => $a['plain'], 'quantity' => 1, 'unit_price' => '10']]]],
            ['POST', 'sales', ['warehouse_id' => $a['room'], 'items' => [['product_id' => $b['plain'], 'quantity' => 1, 'unit_price' => '10']]]],
            ['POST', 'sales', ['items' => [['product_id' => $b['options'], 'variant_id' => $a['variant'], 'quantity' => 1, 'unit_price' => '10']]]],
            ['POST', "sales/offline/{$b['sale']}/returns", ['reason' => 'Broken', 'items' => [['item_id' => $a['sale_item'], 'quantity' => 1]]]],
            ['POST', "sales/offline/{$b['sale']}/returns", ['reason' => 'Broken', 'warehouse_id' => $a['room'], 'items' => [['item_id' => $b['sale_item'], 'quantity' => 1, 'restock' => true]]]],
            ['POST', "sales/orders/{$b['order']}/returns", ['reason' => 'Broken', 'items' => [['item_id' => $a['order_item'], 'quantity' => 1]]]],
            ['POST', "sales/orders/{$b['order']}/returns", ['reason' => 'Broken', 'warehouse_id' => $a['room'], 'items' => [['item_id' => $b['order_item'], 'quantity' => 1, 'restock' => true]]]],
            ['POST', 'expenses', ['expense_category_id' => $a['category'], 'amount' => '10', 'description' => 'Tea', 'payment_method' => 'cash']],
            ['PUT', "expenses/{$b['expense']}", ['expense_category_id' => $a['category']]],
            ['POST', "suppliers/{$b['supplier']}/invoices", ['invoice_number' => 'B-9', 'amount' => '10', 'purchase_order_id' => $a['po']]],
            ['POST', "suppliers/{$b['supplier']}/payments", ['amount' => '10', 'method' => 'cash', 'purchase_order_id' => $a['po']]],
            ['PUT', "inventory/{$b['plain']}", ['supplier_id' => $a['supplier']]],
            ['PUT', "inventory/{$b['options']}", ['variant_id' => $a['variant'], 'cost_price' => '5']],
            ['POST', "inventory/{$b['plain']}/adjust", ['warehouse_id' => $a['room'], 'type' => 'found', 'quantity' => 1]],
            ['POST', "inventory/{$b['options']}/adjust", ['variant_id' => $a['variant'], 'type' => 'found', 'quantity' => 1]],
            // The store's own ids, but not belonging together.
            ['POST', 'purchases', ['supplier_id' => $b['supplier'], 'items' => [['product_id' => $b['plain'], 'variant_id' => $b['variant'], 'quantity' => 1, 'unit_cost' => '10']]]],
            ['POST', "purchases/{$b['po']}/receive", ['items' => [['item_id' => $b['plain_po_item'], 'quantity' => 1]]]],
            ['POST', "sales/offline/{$b['sale']}/returns", ['reason' => 'Broken', 'items' => [['item_id' => $b['sale2_item'], 'quantity' => 1]]]],
            ['POST', 'sales', ['items' => [['product_id' => $b['plain'], 'variant_id' => $b['variant'], 'quantity' => 1, 'unit_price' => '10']]]],
            ['POST', 'transfers', ['from_warehouse_id' => $b['default'], 'to_warehouse_id' => $b['closed'], 'items' => [['product_id' => $b['plain'], 'quantity' => 1]]]],
            ['POST', 'transfers', ['from_warehouse_id' => $b['default'], 'to_warehouse_id' => $b['default'], 'items' => [['product_id' => $b['plain'], 'quantity' => 1]]]],
        ];

        $this->as($this->other->user);
        foreach ($cases as $i => [$method, $path, $body]) {
            $before = $this->fingerprint();
            $res = $this->send($method, $path, $body);
            $this->assertContains($res->getStatusCode(), [404, 422], "case {$i} {$method} {$path}: {$res->getStatusCode()} ".$res->getContent());
            $this->assertStringNotContainsString('Alpha', $res->getContent(), "case {$i}: the answer names the other store's record");
            $this->assertSame($before, $this->fingerprint(), "case {$i} {$method} {$path} changed something");
        }
    }

    public function test_filters_with_another_stores_ids_show_nothing_of_it(): void
    {
        $a = $this->a;
        $this->as($this->other->user);
        $empty = [
            "movements?product_id={$a['plain']}", "movements?warehouse_id={$a['room']}", "movements?variant_id={$a['variant']}",
            "purchases?supplier_id={$a['supplier']}", "transfers?warehouse_id={$a['room']}", "inventory?supplier_id={$a['supplier']}",
            "expenses?category_id={$a['category']}", 'sales?q=Alpha', 'suppliers?q=Alpha', 'inventory?q=Alpha', 'purchases?q=Alpha', 'expenses?q=Alpha',
            "items?supplier_id={$a['supplier']}&q=Alpha",
        ];
        foreach ($empty as $path) {
            $res = $this->getJson('/api/seller/erp/'.$path);
            $this->assertContains($res->getStatusCode(), [200, 422], $path);
            if ($res->getStatusCode() === 200) {
                $this->assertSame([], $res->json('data'), $path);
            }
            $this->assertStringNotContainsString('Alpha', $res->getContent(), $path);
        }

        $pages = ['overview', 'inventory', 'movements', 'items', 'settings', 'warehouses', 'transfers', 'suppliers', 'purchases', 'sales', 'sales/report',
            'expenses', 'expense-categories', 'finance', 'reports',
            "items?warehouse_id={$a['room']}", "movements?product_id={$a['plain']}&type=sale"];
        foreach (array_keys(Reports::definitions()) as $report) {
            $pages[] = "reports/{$report}?period=year";
        }
        foreach ($pages as $path) {
            $res = $this->getJson('/api/seller/erp/'.$path);
            $this->assertContains($res->getStatusCode(), [200, 404, 422], $path);
            $this->assertStringNotContainsString('Alpha', $res->getContent(), $path);
        }
        $downloads = ['inventory', 'movements', 'sales', 'expenses', 'finance'];
        foreach (array_keys(Reports::definitions()) as $report) {
            $downloads[] = "reports/{$report}";
        }
        foreach ($downloads as $path) {
            $csv = $this->get('/api/seller/erp/'.$path.'.csv?period=year')->assertOk()->streamedContent();
            $this->assertStringNotContainsString('Alpha', $csv, $path);
            $xlsx = $this->get('/api/seller/erp/'.$path.'.xlsx?period=year')->assertOk()->streamedContent();
            $this->assertStringNotContainsString('Alpha', json_encode($this->workbookRows($xlsx), JSON_UNESCAPED_UNICODE), $path.'.xlsx');
        }
    }

    public function test_an_online_order_not_paid_yet_is_not_yet_a_sale_of_the_store(): void
    {
        $this->as($this->seller->user);
        $this->getJson("/api/seller/erp/sales/orders/{$this->a['unpaid_order']}")->assertNotFound();
        $this->postJson("/api/seller/erp/sales/orders/{$this->a['unpaid_order']}/returns", ['reason' => 'Broken',
            'items' => [['item_id' => Order::find($this->a['unpaid_order'])->items()->value('id'), 'quantity' => 1]]])->assertNotFound();
        $this->getJson('/api/seller/erp/sales?source=marketplace')->assertOk()
            ->assertJsonMissing(['id' => $this->a['unpaid_order'], 'source' => 'marketplace']);
    }
}
