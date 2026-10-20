<?php

namespace Tests\Fakes;

use App\Sms\SmsFailed;
use App\Sms\SmsGateway;

/** Keeps the last text so a test can type the code. */
class CodeCatcher implements SmsGateway
{
    public int $calls = 0;

    public bool $delivers = false;

    public ?SmsFailed $fail = null;

    public ?string $lastTo = null;

    public ?string $lastBody = null;

    public function name(): string
    {
        return 'catcher';
    }

    public function delivers(): bool
    {
        return $this->delivers;
    }

    public function send(string $to, string $body): ?string
    {
        if ($this->fail) {
            throw $this->fail;
        }
        $this->calls++;
        [$this->lastTo, $this->lastBody] = [$to, $body];

        return null;
    }

    public function code(): string
    {
        preg_match('/code is (\d{6})/', (string) $this->lastBody, $m);

        return $m[1] ?? throw new \RuntimeException('No code was texted.');
    }
}
