<?php

namespace App\Support;

use App\Http\Controllers\Api\Admin\ReportController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A download of rows as an Excel workbook (.xlsx) or CSV, written by the same code.
 *
 * Excel opens a CSV by guessing: it drops leading zeros (TIN 0012...), turns +2519... phone numbers
 * into 2.5E+11 and garbles Amharic without a byte-order mark. The workbook stores every cell with its
 * type, so text stays exactly as saved and money stays a number Excel can add up.
 */
final class Spreadsheet
{
    /** Plain decimal numbers without leading zeros, up to 11 digits (Excel shows longer ones as 2.5E+11); anything else stays text. */
    private const NUMBER = '/^-?(0|[1-9]\d{0,10})(\.\d{1,6})?$/';

    private const CELL_LIMIT = 32767;

    /** @var resource */
    private $out;

    private int $rowNumber = 0;

    private function __construct(private readonly string $format)
    {
        if ($format === 'csv') {
            $this->out = fopen('php://output', 'w');

            return;
        }
        // Rows are written to a temporary file (memory, then disk once it grows) and compressed at the end.
        $this->out = fopen('php://temp', 'w+');
        fwrite($this->out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // The header row stays in view while scrolling.
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetData>');
    }

    /**
     * @param  'csv'|'xlsx'  $format
     * @param  callable(callable(array<int, mixed>): void): void  $fill  called with a function that adds one row;
     *                                                                   the first row is the header
     */
    public static function download(string $format, string $basename, callable $fill): StreamedResponse
    {
        $format = $format === 'xlsx' ? 'xlsx' : 'csv';

        return response()->streamDownload(function () use ($format, $fill) {
            $sheet = new self($format);
            $fill(fn (array $cells) => $sheet->row($cells));
            $sheet->finish();
        }, $basename.'.'.$format, ['Content-Type' => $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8']);
    }

    /** The format a download route asked for: its URL ends in .xlsx or .csv. */
    public static function formatOf(Request $request): string
    {
        return str_ends_with($request->path(), '.xlsx') ? 'xlsx' : 'csv';
    }

    private function row(array $cells): void
    {
        $r = ++$this->rowNumber;
        if ($this->format === 'csv') {
            fputcsv($this->out, array_map(fn ($v) => $v ?? '', array_values($cells)), escape: '');

            return;
        }

        $header = $r === 1;
        $xml = '<row r="'.$r.'">';
        foreach (array_values($cells) as $i => $value) {
            $ref = self::column($i).$r;
            if ($value === null || $value === '') {
                continue;
            }
            if (! $header && (is_int($value) || is_float($value) || (is_string($value) && preg_match(self::NUMBER, $value)))) {
                $style = is_string($value) && str_contains($value, '.') ? ' s="1"' : '';
                $xml .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';

                continue;
            }
            $text = (string) $value;
            // Callers mark text that would run as a CSV formula with a leading quote; a workbook cell is never
            // a formula, so the mark comes off again.
            if (str_starts_with($text, "'") && ReportController::cell(substr($text, 1)) === $text) {
                $text = substr($text, 1);
            }
            // Excel refuses to open a file with a cell over 32,767 characters; the CSV download keeps such text whole.
            if (mb_strlen($text) > self::CELL_LIMIT) {
                $note = ' [cut here: Excel cells hold 32,767 characters; the CSV has it all]';
                $text = mb_substr($text, 0, self::CELL_LIMIT - mb_strlen($note)).$note;
            }
            $xml .= '<c r="'.$ref.'" t="inlineStr"'.($header ? ' s="2"' : '').'><is><t xml:space="preserve">'.self::xml($text).'</t></is></c>';
        }
        fwrite($this->out, $xml.'</row>');
    }

    private function finish(): void
    {
        if ($this->format === 'csv') {
            fclose($this->out);

            return;
        }

        fwrite($this->out, '</sheetData></worksheet>');

        $zip = new ZipWriter(fopen('php://output', 'w'));
        $zip->add('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->add('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->add('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        // Style 1: numbers with two decimals and thousands commas (money). Style 2: bold header.
        $zip->add('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>');
        $zip->addStream('xl/worksheets/sheet1.xml', $this->out);
        $zip->finish();
    }

    /** Column letters: 0 is A, 25 is Z, 26 is AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** Text safe inside XML: escaped, and without the control characters XML does not allow. */
    private static function xml(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
