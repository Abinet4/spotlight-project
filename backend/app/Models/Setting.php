<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable business settings. Values not stored fall back to config defaults.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'updated_by'];

    public const DEFAULTS = [
        'commission_rate_percent' => 'marketplace.commission.default_rate_percent',
        'cod_enabled' => 'marketplace.payments.cod_enabled',
    ];

    public static function value(string $key): mixed
    {
        $stored = static::query()->whereKey($key)->value('value');

        return $stored ?? config(self::DEFAULTS[$key] ?? '');
    }

    /**
     * Commission in basis points (10% = 1000), never a float in money maths.
     */
    public static function commissionRateBp(): int
    {
        return (int) round(((float) static::value('commission_rate_percent')) * 100);
    }

    public static function codEnabled(): bool
    {
        return filter_var(static::value('cod_enabled'), FILTER_VALIDATE_BOOL);
    }
}
