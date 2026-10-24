<?php

namespace App\Sms;

/**
 * What a failed SMS's provider error means and what an admin can do about it, in plain words. The raw error
 * stays saved and shown under it, since providers word their errors in many ways.
 */
final class SmsTrouble
{
    /** First match wins: [pattern, explanation]. */
    private const KNOWN = [
        ['/NOT_WHITELISTED|whitelist|starter \(default\) campaign|verified in the dashboard/i',
            'Your SMS Ethiopia account is on the free starter package, which only texts phone numbers verified in the SMS Ethiopia dashboard. Buy an SMS package there (or verify this number for testing); no change is needed on Betoch.'],
        ['/_API_KEY is not set|_TOKEN is not set/',
            'No SMS provider key is set on the server. Add the key in the hosting settings, then texts can go out.'],
        ['/HTTP 401|HTTP 403|unauthori[sz]ed|invalid (api )?key|invalid token|forbidden/i',
            'The SMS provider did not accept the key. Check the key saved in the hosting settings matches the one in the provider dashboard.'],
        ['/insufficient|balance|credit|top.?up|quota|limit exceeded/i',
            'The SMS account has run out of credit. Top it up in the provider dashboard.'],
        ['/invalid (phone|number|msisdn|recipient)|not a valid (phone|number)/i',
            'The provider says the phone number is not valid. Check the number on the account.'],
        ['/sender|short ?code|identifier/i',
            'The provider refused the sender name. Check the sender name approved in the provider dashboard.'],
        ['/Could not reach/',
            'The server could not reach the SMS provider (network or provider outage). Each text is tried up to 3 times on its own.'],
        ['/HTTP 5\d\d/',
            'The SMS provider had a problem on its side. Each text is tried up to 3 times on its own.'],
    ];

    public static function explain(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }
        foreach (self::KNOWN as [$pattern, $text]) {
            if (preg_match($pattern, $error)) {
                return $text;
            }
        }

        return 'The SMS provider refused this text. The provider\'s message is below.';
    }
}
