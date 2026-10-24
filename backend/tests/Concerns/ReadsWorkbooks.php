<?php

namespace Tests\Concerns;

/** Opens an .xlsx download the way any unzip tool does, checking every file's size and CRC. */
trait ReadsWorkbooks
{
    /** @return array<string, string> the files inside the workbook */
    protected function unzipWorkbook(string $bytes): array
    {
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4), 'an .xlsx is a zip file');
        $files = [];
        $offset = 0;
        while (substr($bytes, $offset, 4) === "PK\x03\x04") {
            $h = unpack('Vsig/vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen', substr($bytes, $offset, 30));
            $name = substr($bytes, $offset + 30, $h['nlen']);
            $body = substr($bytes, $offset + 30 + $h['nlen'] + $h['xlen'], $h['csize']);
            $data = $h['method'] === 8 ? gzinflate($body) : $body;
            $this->assertSame($h['size'], strlen($data), $name);
            $this->assertSame($h['crc'], crc32($data), $name);
            $files[$name] = $data;
            $offset += 30 + $h['nlen'] + $h['xlen'] + $h['csize'];
        }
        $this->assertSame("PK\x01\x02", substr($bytes, $offset, 4), 'central directory follows the files');

        return $files;
    }

    /**
     * The workbook's rows as plain values, like reading the sheet in Excel: numbers as strings, empty cells as ''.
     *
     * @return list<list<string>>
     */
    protected function workbookRows(string $bytes): array
    {
        $xml = simplexml_load_string($this->unzipWorkbook($bytes)['xl/worksheets/sheet1.xml']);
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                $col = 0;
                foreach (str_split($m[1]) as $ch) {
                    $col = $col * 26 + ord($ch) - 64;
                }
                $cells[$col - 1] = isset($c->is) ? (string) $c->is->t : (string) $c->v;
            }
            $rows[] = $cells === [] ? [] : array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
        }

        return $rows;
    }

    /** @return list<list<string>> */
    protected function csvRows(string $csv): array
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, $csv);
        rewind($h);
        $rows = [];
        while (($row = fgetcsv($h, escape: '')) !== false) {
            // The workbook needs no formula guard, so compare without it.
            $rows[] = array_map(fn ($v) => preg_match("/^'[=+\\-@\t\r\n＝＋－＠]/u", $v) ? substr($v, 1) : $v, $row);
        }
        fclose($h);

        return $rows;
    }

    /** Downloads $path as .csv and .xlsx and checks both hold the same cells; returns the workbook rows. */
    protected function assertSameAsCsv(string $path, ?callable $between = null, string $query = ''): array
    {
        $csv = $this->get($path.'.csv'.$query)->assertOk()->streamedContent();
        $between && $between();
        $res = $this->get($path.'.xlsx'.$query)->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', $res->headers->get('Content-Disposition'));
        $rows = $this->workbookRows($res->streamedContent());
        $expected = $this->csvRows($csv);
        // Trailing empty cells are not stored in a workbook row.
        $trim = fn ($rows) => array_map(fn ($r) => array_values(array_slice($r, 0, (int) max([0, ...array_keys(array_filter($r, fn ($v) => $v !== ''))]) + 1)), $rows);
        $this->assertSame($trim($expected), $trim($rows), $path);

        return $rows;
    }
}
