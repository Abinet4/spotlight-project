<?php

namespace App\Support;

/**
 * The smallest zip writer an .xlsx needs, so downloads do not depend on the zip extension being installed
 * on the server. Large parts are compressed in chunks, so a big export never sits in memory whole.
 */
final class ZipWriter
{
    private const CHUNK = 1 << 20;

    private int $offset = 0;

    /** @var list<string> */
    private array $central = [];

    /** @param resource $out */
    public function __construct(private $out) {}

    public function add(string $name, string $data): void
    {
        $source = fopen('php://temp', 'w+');
        fwrite($source, $data);
        $this->addStream($name, $source);
    }

    /** Adds the stream's contents (read from the start) as one deflated file, then closes the stream. */
    public function addStream(string $name, $source): void
    {
        rewind($source);
        $deflated = fopen('php://temp', 'w+');
        $deflate = deflate_init(ZLIB_ENCODING_RAW);
        $crc = hash_init('crc32b');
        $size = 0;
        while (! feof($source)) {
            $chunk = (string) fread($source, self::CHUNK);
            $size += strlen($chunk);
            hash_update($crc, $chunk);
            fwrite($deflated, deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
        }
        fwrite($deflated, deflate_add($deflate, '', ZLIB_FINISH));
        fclose($source);
        $crc = (int) hexdec(hash_final($crc));
        $compressed = ftell($deflated);

        // The date field is a fixed 1 January 2026 so the same rows always give the same file.
        [$time, $date] = [0, ((2026 - 1980) << 9) | (1 << 5) | 1];
        // Flag 0x0800: names are UTF-8. Method 8: deflate.
        $local = pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 8, $time, $date, $crc, $compressed, $size, strlen($name), 0).$name;
        fwrite($this->out, $local);
        rewind($deflated);
        stream_copy_to_stream($deflated, $this->out);
        fclose($deflated);

        $this->central[] = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 8, $time, $date, $crc,
            $compressed, $size, strlen($name), 0, 0, 0, 0, 0, $this->offset).$name;
        $this->offset += strlen($local) + $compressed;
    }

    public function finish(): void
    {
        $directory = implode('', $this->central);
        fwrite($this->out, $directory);
        fwrite($this->out, pack('VvvvvVVv', 0x06054B50, 0, 0, count($this->central), count($this->central), strlen($directory), $this->offset, 0));
        fclose($this->out);
    }
}
