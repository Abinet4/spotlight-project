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
        'online_payments_enabled' => 'marketplace.payments.online_enabled',
        'commission_credit_limit_birr' => 'marketplace.commission.credit_limit_birr',
        'settlement_instructions' => 'marketplace.settlements.instructions',
        'sms_enabled' => 'marketplace.alerts.sms_enabled',
        'delivery_base_fee_birr' => 'marketplace.distance.base_fee_birr',
        'delivery_per_km_birr' => 'marketplace.distance.per_km_birr',
        'delivery_max_km' => 'marketplace.distance.max_km',
        'delivery_per_floor_birr' => 'marketplace.distance.per_floor_birr',
        'stores_set_delivery_fee' => 'marketplace.distance.stores_set_fee',
        'admins_must_use_two_step' => 'marketplace.sign_in.admins_must_use_two_step',
        'everyone_must_use_two_step' => 'marketplace.sign_in.everyone_must_use_two_step',
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

    /**
     * Unpaid commission above which a store cannot confirm new orders, in santim. 0 means no limit.
     */
    public static function creditLimitSantim(): int
    {
        return max(0, (int) static::value('commission_credit_limit_birr')) * 100;
    }

    public static function settlementInstructions(): string
    {
        return (string) static::value('settlement_instructions');
    }

    public static function codEnabled(): bool
    {
        return filter_var(static::value('cod_enabled'), FILTER_VALIDATE_BOOL);
    }

    /** Admin switch for online payment. It also needs a working gateway; see PaymentService::available(). */
    public static function onlinePaymentsEnabled(): bool
    {
        return filter_var(static::value('online_payments_enabled'), FILTER_VALIDATE_BOOL);
    }

    /** Admin switch: may stores charge their own delivery fee on a listing? Free delivery is always allowed. */
    public static function storesSetDeliveryFee(): bool
    {
        return filter_var(static::value('stores_set_delivery_fee'), FILTER_VALIDATE_BOOL);
    }

    /** Admin switch that pauses every SMS alert (in-app and email alerts continue). */
    public static function smsEnabled(): bool
    {
        return filter_var(static::value('sms_enabled'), FILTER_VALIDATE_BOOL);
    }

    /** Admin switch: every administrator must sign in with a texted code after the password. */
    public static function adminsMustUseTwoStep(): bool
    {
        return filter_var(static::value('admins_must_use_two_step'), FILTER_VALIDATE_BOOL);
    }

    /** Admin switch: every account checks a phone at sign-up and types a texted code at every sign-in. */
    public static function everyoneMustUseTwoStep(): bool
    {
        return filter_var(static::value('everyone_must_use_two_step'), FILTER_VALIDATE_BOOL);
    }

    /** Delivery by distance: fixed part of every trip, in santim. */
    public static function deliveryBaseFeeSantim(): int
    {
        return max(0, (int) static::value('delivery_base_fee_birr')) * 100;
    }

    /** Delivery by distance: price per km in santim. 0 turns distance pricing off. */
    public static function deliveryPerKmSantim(): int
    {
        return max(0, (int) static::value('delivery_per_km_birr')) * 100;
    }

    /** Longest trip priced automatically, in km. 0 means no limit. */
    public static function deliveryMaxKm(): int
    {
        return max(0, (int) static::value('delivery_max_km'));
    }

    /** Carrying fee per floor above the ground floor when the building has no lift, in santim. */
    public static function deliveryPerFloorSantim(): int
    {
        return max(0, (int) static::value('delivery_per_floor_birr')) * 100;
    }
}
