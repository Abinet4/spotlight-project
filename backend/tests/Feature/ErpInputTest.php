<?php

namespace Tests\Feature;

use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsErpWorld;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Broken and hostile input on every ERP form: each field of a request the store could send, swapped
 * for wrong types, huge numbers, long text, formula text and the like. The server must answer with a
 * message (never a crash), and a refused request must leave every table and file as it was.
 */
class ErpInputTest extends TestCase
{
    use BuildsErpWorld, PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpErpWorld();
        $this->as($this->seller->user);
    }

    private function today(int $plusDays = 0): string
    {
        return now(config('marketplace.timezone'))->addDays($plusDays)->toDateString();
    }

    /** A file as a browser sends it: its type is read from what is inside, not from its name. */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return list<mixed> */
    private function badValues(): array
    {
        return ['', null, [], ['x' => 1], [[1]], -1, 0, PHP_INT_MAX, '99999999999999999999999999', '1e400', '1e3', str_repeat('9', 400),
            str_repeat('x', 5000), '=cmd|\' /C calc\'!A0', true, 1.5, '١٢٣', ' 10 ', '10.999', '-0', "1\n2", '<script>alert(1)</script>', "a\0b", '0x1A', 'NaN', '😀', '0001-01-01', '9999-12-31', '2026-02-30'];
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> every ERP form with a request it accepts */
    private function forms(): array
    {
        $forms = [];
        foreach ($this->routes() as $key => $route) {
            [$method, $uri] = explode(' ', $key, 2);
            if ($method !== 'GET') {
                $forms[$key] = [$method, $this->path($uri, $this->a, $route[0]), $route[1]($this->a)];
            }
        }
        foreach (['PUT settings', 'POST warehouses', 'POST transfers', 'POST suppliers', 'POST purchases', 'POST sales', 'POST expenses', 'POST expense-categories'] as $key) {
            [$method, $uri] = explode(' ', $key, 2);
            $forms[$key] = [$method, $uri, $this->createBody($uri)($this->a)];
        }
        // Fuller requests, so optional fields get tried too.
        $forms['POST sales (full)'] = ['POST', 'sales', [
            'customer_name' => 'Almaz', 'customer_phone' => '0911 222 333', 'customer_tin' => '0012345678', 'warehouse_id' => $this->a['default'],
            'sale_date' => $this->today(), 'due_date' => $this->today(10), 'discount' => '10', 'delivery' => '20', 'paid_now' => '5', 'payment_method' => 'cash',
            'payment_reference' => 'R1', 'note' => 'n', 'request_id' => 'r-1',
            'items' => [['product_id' => $this->a['plain'], 'quantity' => 1, 'unit_price' => '100', 'discount' => '1'], ['description' => 'Assembly', 'quantity' => 1, 'unit_price' => '50']],
        ]];
        $forms['POST purchases (full)'] = ['POST', 'purchases', [
            'supplier_id' => $this->a['supplier'], 'warehouse_id' => $this->a['room'], 'order_date' => $this->today(), 'expected_date' => $this->today(5),
            'supplier_reference' => 'S-1', 'discount' => '5', 'charges' => '10', 'charges_note' => 'Truck', 'notes' => 'n',
            'items' => [['product_id' => $this->a['options'], 'variant_id' => $this->a['variant'], 'quantity' => 2, 'unit_cost' => '100', 'discount' => '1']],
        ]];
        $forms['POST sales/offline/{id}/returns (full)'] = ['POST', "sales/offline/{$this->a['sale']}/returns", [
            'reason' => 'Broken', 'warehouse_id' => $this->a['default'], 'returned_on' => $this->today(), 'delivery_credit' => '0', 'refund' => '100', 'refund_method' => 'cash', 'request_id' => 'r-2',
            'items' => [['item_id' => $this->a['sale_item'], 'quantity' => 1, 'restock' => true]],
        ]];
        $forms['POST purchases/{id}/receive (full)'] = ['POST', "purchases/{$this->a['po']}/receive", [
            'warehouse_id' => $this->a['room'], 'received_on' => $this->today(), 'note' => 'n', 'request_id' => 'r-3',
            'items' => [['item_id' => $this->a['po_item'], 'quantity' => 1]],
        ]];
        $forms['POST inventory/{id}/adjust (count)'] = ['POST', "inventory/{$this->a['plain']}/adjust", [
            'type' => 'count', 'quantity' => 3, 'warehouse_id' => $this->a['default'], 'note' => 'n', 'expected_available' => $this->sofa->fresh()->stock_quantity,
        ]];
        $forms['POST suppliers/{id}/invoices (full)'] = ['POST', "suppliers/{$this->a['supplier']}/invoices", [
            'invoice_number' => 'F-1', 'invoice_date' => $this->today(), 'due_date' => $this->today(30), 'amount' => '100', 'note' => 'n', 'purchase_order_id' => $this->a['plain_po'],
        ]];

        return $forms;
    }

    public function test_every_form_accepts_its_request_so_the_bad_input_below_is_what_gets_refused(): void
    {
        foreach ($this->forms() as $key => [$method, $path, $body]) {
            $res = $this->sendAndUndo($method, $path, $body);
            $this->assertTrue($res->isSuccessful(), "{$key}: {$res->getStatusCode()} ".$res->getContent());
        }
    }

    public function test_bad_input_in_any_field_gets_a_message_never_a_crash_and_a_refusal_changes_nothing(): void
    {
        $before = $this->fingerprint();
        $tried = 0;
        foreach ($this->forms() as $key => [$method, $path, $body]) {
            $fields = array_keys(Arr::dot($body));
            // Whole lists too, not just the fields inside them.
            foreach (array_keys($body) as $field) {
                if (is_array($body[$field])) {
                    $fields[] = $field;
                    $fields[] = "{$field}.0";
                }
            }
            foreach (array_unique($fields) as $field) {
                foreach ([...$this->badValues(), '__missing__'] as $value) {
                    $bad = $body;
                    if ($value === '__missing__') {
                        Arr::forget($bad, $field);
                    } else {
                        Arr::set($bad, $field, $value);
                    }
                    if ($bad === $body) {
                        continue;
                    }
                    $tried++;
                    DB::beginTransaction();
                    try {
                        $res = $this->send($method, $path, $bad);
                        $status = $res->getStatusCode();
                        $label = "{$key} with {$field} = ".json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
                        $this->assertLessThan(500, $status, "{$label}: ".mb_substr($res->getContent(), 0, 500));
                        if ($status >= 400) {
                            $this->assertSame($before, $this->fingerprint(), "{$label} was refused but changed something");
                        }
                    } finally {
                        DB::rollBack();
                    }
                }
            }
        }
        $this->assertGreaterThan(2000, $tried);
    }

    public function test_more_lines_than_a_form_takes_are_refused_without_a_crash(): void
    {
        $lines = fn (array $line) => array_fill(0, 1001, $line);
        $cases = [
            ['POST', 'sales', ['items' => $lines(['product_id' => $this->a['plain'], 'quantity' => 1, 'unit_price' => '10'])]],
            ['POST', 'purchases', ['supplier_id' => $this->a['supplier'], 'items' => $lines(['product_id' => $this->a['plain'], 'quantity' => 1, 'unit_cost' => '10'])]],
            ['POST', 'transfers', ['from_warehouse_id' => $this->a['default'], 'to_warehouse_id' => $this->a['spare'], 'items' => $lines(['product_id' => $this->a['plain'], 'quantity' => 1])]],
            ['POST', "purchases/{$this->a['po']}/receive", ['items' => $lines(['item_id' => $this->a['po_item'], 'quantity' => 0])]],
            ['POST', "sales/offline/{$this->a['sale']}/returns", ['reason' => 'Broken', 'items' => $lines(['item_id' => $this->a['sale_item'], 'quantity' => 0])]],
            // The same line twice: together more than was bought or sold.
            ['POST', "sales/offline/{$this->a['sale']}/returns", ['reason' => 'Broken', 'items' => array_fill(0, 2, ['item_id' => $this->a['sale_item'], 'quantity' => 1, 'restock' => true])]],
            ['POST', "sales/orders/{$this->a['order']}/returns", ['reason' => 'Broken', 'items' => array_fill(0, 3, ['item_id' => $this->a['order_item'], 'quantity' => 1, 'restock' => true])]],
            ['POST', "purchases/{$this->a['po']}/receive", ['items' => array_fill(0, 2, ['item_id' => $this->a['po_item'], 'quantity' => 3])]],
            ['POST', "transfers/{$this->a['transfer']}/receive", ['items' => array_fill(0, 2, ['id' => $this->a['transfer_item'], 'received_quantity' => 2])]],
        ];
        $before = $this->fingerprint();
        foreach ($cases as $i => [$method, $path, $body]) {
            $res = $this->send($method, $path, $body);
            $this->assertSame(422, $res->getStatusCode(), "case {$i} {$path}: ".mb_substr($res->getContent(), 0, 300));
            $this->assertSame($before, $this->fingerprint(), "case {$i} changed something");
        }
    }

    public function test_receipt_files_must_be_real_pictures_or_pdfs_of_at_most_5_mb(): void
    {
        $id = $this->a['plain_expense'];
        $bad = [
            'script' => $this->upload('receipt.php', '<?php echo "hi";'),
            'script named pdf' => $this->upload('receipt.pdf', '<?php echo "hi";'),
            'web page named pdf' => $this->upload('receipt.pdf', '<html><body><script>alert(1)</script></body></html>'),
            'web page named jpg' => $this->upload('receipt.jpg', '<html><body><script>alert(1)</script></body></html>'),
            'svg' => $this->upload('receipt.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>'),
            'svg named png' => $this->upload('receipt.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>'),
            'too big' => $this->upload('receipt.pdf', "%PDF-1.4\n".str_repeat('0', 5 * 1024 * 1024 + 10)),
            'text' => $this->upload('receipt.txt', 'just text'),
        ];
        $before = $this->fingerprint();
        foreach ($bad as $label => $file) {
            $res = $this->send('POST', "expenses/{$id}/attachment", ['attachment' => $file]);
            $this->assertSame(422, $res->getStatusCode(), "{$label}: ".$res->getContent());
            $this->assertSame($before, $this->fingerprint(), "{$label} changed something");
        }

        // A real one with a path-like name is kept under a name of the server's choosing, privately.
        $this->send('POST', "expenses/{$id}/attachment", ['attachment' => $this->upload('../../../.env.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")])->assertOk();
        $expense = Expense::find($id);
        $this->assertStringNotContainsString('..', $expense->attachment_path);
        $this->assertStringNotContainsString('.env', $expense->attachment_path);
        Storage::disk('local')->assertExists($expense->attachment_path);
        $this->assertSame([], Storage::disk('public')->allFiles('erp'));
        $res = $this->get("/api/seller/erp/expenses/{$id}/attachment")->assertOk();
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringNotContainsString('/', (string) $expense->attachment_name);
        $this->assertStringNotContainsString('..', (string) $res->headers->get('Content-Disposition'));
    }
}
