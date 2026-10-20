<?php

namespace App\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SMS Ethiopia (smsethiopia.com). Needs SMSETHIOPIA_API_KEY from the account's API page.
 * Their send API takes the number as 2519XXXXXXXX in `msisdn`, the key in a `KEY` header, and answers
 * {"status": "success", "message": "Accepted Successfully"} when it accepted the message (accepted,
 * not yet delivered to the phone). Their SDK and some callers see {"sent": true, ...} instead.
 *
 * The documentation names no error format, so a 2xx answer counts as sent unless it says otherwise in
 * one of the usual ways; a reply this misreads shows up in Admin > Text messages with the provider's words.
 */
class SmsEthiopiaGateway implements SmsGateway
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'smsethiopia';
    }

    public function delivers(): bool
    {
        return true;
    }

    public function send(string $to, string $body): ?string
    {
        if (empty($this->config['key'])) {
            throw new SmsFailed('SMSETHIOPIA_API_KEY is not set.', retryable: false);
        }

        try {
            $response = Http::withHeaders(['KEY' => $this->config['key']])
                ->acceptJson()
                ->timeout(10)
                ->post(rtrim($this->config['base_url'], '/').'/sms/send', [
                    'msisdn' => ltrim($to, '+'),
                    'text' => $body,
                ]);
        } catch (ConnectionException $e) {
            throw new SmsFailed('Could not reach SMS Ethiopia: '.$e->getMessage());
        }

        $json = $response->json();
        if ($response->successful() && ! self::saysFailed($json)) {
            $id = $json['id'] ?? $json['message_id'] ?? $json['messageId'] ?? null;

            return is_scalar($id) ? (string) $id : null;
        }

        $detail = is_array($json) ? ($json['message'] ?? $json['error'] ?? json_encode($json)) : $response->body();

        // 4xx answers (bad key, bad number, no balance) will not fix themselves on retry.
        throw new SmsFailed('SMS Ethiopia refused the SMS (HTTP '.$response->status().'): '.mb_substr((string) (is_scalar($detail) ? $detail : json_encode($detail)), 0, 300), retryable: $response->serverError());
    }

    private static function saysFailed(mixed $json): bool
    {
        if (! is_array($json)) {
            return true;
        }
        if (($json['sent'] ?? null) === false || ($json['success'] ?? null) === false) {
            return true;
        }
        $status = is_string($json['status'] ?? null) ? strtolower($json['status']) : null;

        return in_array($status, ['error', 'failed', 'fail', 'failure'], true) || ! empty($json['error']) || ! empty($json['errors']);
    }
}
