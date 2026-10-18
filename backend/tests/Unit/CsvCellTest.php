<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\ReportController;
use PHPUnit\Framework\TestCase;

class CsvCellTest extends TestCase
{
    public function test_text_that_a_spreadsheet_would_run_as_a_formula_is_kept_as_text(): void
    {
        foreach (['=1+1', '+251911', '-2', '@SUM(A1)', "\t=1", "\r=1", "\n=1", ' =1', "  \t+1", "\u{00A0}=1", "\u{3000}=1", '＝1+1', '＋1', '－1', '＠x'] as $value) {
            $this->assertSame("'".$value, ReportController::cell($value), json_encode($value));
        }
    }

    public function test_ordinary_text_and_empty_cells_are_left_alone(): void
    {
        foreach (['Sofa', 'Almaz Kebede', '12 chairs', 'a=b', 'x-1', '', 'café', "\xff\xfe broken"] as $value) {
            $this->assertSame($value, ReportController::cell($value), json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $this->assertSame('', ReportController::cell(null));
        $this->assertSame('12', ReportController::cell(12));
    }
}
