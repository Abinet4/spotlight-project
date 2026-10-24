<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\ReportController;
use App\Support\Spreadsheet;
use Tests\Concerns\ReadsWorkbooks;
use Tests\TestCase;

class SpreadsheetTest extends TestCase
{
    use ReadsWorkbooks;

    private function sheet(array $header, array $rows, string $format = 'xlsx'): string
    {
        $response = Spreadsheet::download($format, 'test', function ($row) use ($header, $rows) {
            $row($header);
            foreach ($rows as $r) {
                $row($r);
            }
        });
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    public function test_workbook_keeps_values_and_types(): void
    {
        $bytes = $this->sheet(['a', 'b', 'c'], [
            ['0012345678', '+251911000111', '10300.00'],
            ['ሶፋ ሰሜን', ReportController::cell('=cmd|calc'), 42],
            ['<b>&"x"</b>', "line\none\x01", null],
            ['251911000111', '-5.50', '007'],
        ]);
        $files = $this->unzipWorkbook($bytes);
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $this->assertArrayHasKey($part, $files);
            $this->assertNotFalse(simplexml_load_string($files[$part]), $part.' is valid XML');
        }
        $sheet = $files['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('<t xml:space="preserve">0012345678</t>', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">+251911000111</t>', $sheet);
        $this->assertStringContainsString('<c r="C2" s="1"><v>10300.00</v></c>', $sheet);
        $this->assertStringContainsString('ሶፋ ሰሜን', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">=cmd|calc</t>', $sheet, 'the CSV formula guard comes off; the cell is still text');
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('<c r="B3"', $sheet);
        $this->assertStringContainsString('&lt;b&gt;&amp;&quot;x&quot;&lt;/b&gt;', $sheet);
        $this->assertStringContainsString("line\none<", $sheet, 'newlines stay, control characters go');
        $this->assertStringContainsString('<t xml:space="preserve">251911000111</t>', $sheet, '12 digits stay text so Excel does not show 2.5E+11');
        $this->assertStringContainsString('<c r="B5" s="1"><v>-5.50</v></c>', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">007</t>', $sheet);
    }

    public function test_quote_stays_unless_it_guards_a_formula(): void
    {
        $sheet = $this->unzipWorkbook($this->sheet(['a'], [["'quoted"], ["'=x"]]))['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('&apos;quoted', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">=x</t>', $sheet);
    }

    public function test_many_columns_get_double_letters(): void
    {
        $header = array_map(fn ($i) => 'c'.$i, range(0, 29));
        $sheet = $this->unzipWorkbook($this->sheet($header, [range(0, 29)]))['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('<c r="Z2"><v>25</v></c>', $sheet);
        $this->assertStringContainsString('<c r="AD2"><v>29</v></c>', $sheet);
    }

    public function test_text_longer_than_an_excel_cell_is_cut_in_the_workbook_only(): void
    {
        $long = str_repeat('ሀ', 40000);
        $sheet = $this->unzipWorkbook($this->sheet(['a'], [[$long]]))['xl/worksheets/sheet1.xml'];
        preg_match('#<c r="A2" t="inlineStr"><is><t xml:space="preserve">(.*?)</t>#u', $sheet, $m);
        $this->assertSame(32767, mb_strlen($m[1]));
        $this->assertStringEndsWith('the CSV has it all]', $m[1]);
        $this->assertStringContainsString($long, $this->sheet(['a'], [[$long]], 'csv'));
    }

    public function test_csv_mode_is_unchanged(): void
    {
        $csv = $this->sheet(['a', 'b'], [['x,y', null], ['"q"', 3]], 'csv');
        $this->assertSame("a,b\n\"x,y\",\n\"\"\"q\"\"\",3\n", $csv);
    }
}
