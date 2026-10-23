<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * A store's VAT registration: whether it is registered and its VAT number. A registered store
 * needs both a TIN and a VAT number, since both appear on its receipts.
 */
class VatDetails
{
    /** @return array<string, array<int, string>> */
    public static function rules(bool $partial = true): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return [
            'vat_registered' => [...$sometimes, 'boolean'],
            'vat_number' => [...$sometimes, 'nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z\/ -]+$/'],
        ];
    }

    /** Fails unless a store marked as registered ends up with a TIN and a VAT number. */
    public static function check(bool $registered, ?string $vatNumber, ?string $tin): void
    {
        if (! $registered) {
            return;
        }
        $errors = [];
        if (trim((string) $vatNumber) === '') {
            $errors['vat_number'] = 'Enter the VAT registration number from the certificate.';
        }
        if (trim((string) $tin) === '') {
            $errors['tin_number'] = 'A store registered for VAT needs its TIN too.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
