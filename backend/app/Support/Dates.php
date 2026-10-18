<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Validation for a day typed into a form or filter (YYYY-MM-DD, from 2000 to 2099).
 *
 * "bail" stops at the first rule that fails: Laravel's date comparisons crash (500) when handed a
 * number or a list, so they must only ever see a well-formed day.
 */
final class Dates
{
    public const FIRST = '2000-01-01';

    public const LAST = '2099-12-31';

    /** @return list<string> */
    public static function rules(string ...$more): array
    {
        return ['bail', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.self::FIRST, 'before_or_equal:'.self::LAST, ...$more];
    }

    /**
     * Rules for a from/to pair. The "to on or after from" check is only added when from is text,
     * since it reads from as a date even when from itself failed.
     *
     * @return array<string, list<string>>
     */
    public static function range(Request $request, string $from = 'from', string $to = 'to'): array
    {
        return [$from => self::rules(), $to => self::rules(...(is_string($request->input($from)) ? ["after_or_equal:{$from}"] : []))];
    }
}
