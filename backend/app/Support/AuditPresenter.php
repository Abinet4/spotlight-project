<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Plain words for audit log rows, shared by the admin page and its download: what happened, to what, and the
 * details that matter. The stored action codes and meta stay untouched.
 */
final class AuditPresenter
{
    /** Actions whose generic wording ("Support: legacy viewed") would not say what happened. */
    private const LABELS = [
        'settings.updated' => 'Changed site settings',
        'support.legacy_viewed' => 'Opened an old store chat',
        'support.created' => 'Support request opened',
        'support.linked' => 'Support request linked to an item',
        'support.note_added' => 'Support note added',
        'support.store_asked' => 'Support asked a store',
        'support.store_replied' => 'Store answered support',
        'support.store_request_closed' => 'Store question closed',
        'report.exported' => 'Downloaded the orders report',
        'audit.exported' => 'Downloaded the audit log',
        'sms.exported' => 'Downloaded the text messages list',
        'user.two_step_reset' => 'Turned off two-step sign-in for a user',
        'user.two_step_reset_via_cli' => 'Turned off two-step sign-in from the server',
        'admin.created_via_cli' => 'Admin account created from the server',
        'account.two_step_on' => 'Turned on two-step sign-in',
        'account.two_step_off' => 'Turned off two-step sign-in',
        'account.signed_out_others' => 'Signed out other devices',
        'checkout.cancelled_unpaid' => 'Checkout cancelled, not paid in time',
        'payment.mismatch' => 'Payment amount did not match',
        'settlement.payout_recorded' => 'Payout to store recorded',
        'settlement.payment_recorded' => 'Commission payment recorded',
        'settlement.adjusted' => 'Commission balance adjusted',
    ];

    private const GROUPS = [
        'account' => 'Account', 'user' => 'User', 'seller' => 'Store', 'product' => 'Listing', 'category' => 'Category',
        'order' => 'Order', 'checkout' => 'Checkout', 'payment' => 'Payment', 'refund' => 'Refund',
        'settlement' => 'Commission', 'support' => 'Support', 'quote' => 'Price offer', 'review' => 'Review',
        'delivery_zone' => 'Delivery zone', 'settings' => 'Settings', 'report' => 'Report', 'inventory' => 'Stock',
        'erp' => 'Store records', 'audit' => 'Audit log', 'sms' => 'Text messages', 'admin' => 'Admin account',
    ];

    private const TYPES = [
        'SellerProfile' => 'Store', 'Product' => 'Listing', 'SupportTicket' => 'Support request',
        'Conversation' => 'Old store chat', 'Quote' => 'Price offer', 'ProductVariant' => 'Option',
        'SellerRequest' => 'Store question', 'OfflineSale' => 'Shop sale', 'SaleReturn' => 'Return',
        'PurchaseOrder' => 'Purchase order', 'StockTransfer' => 'Stock transfer',
    ];

    /** Columns that name a record, best first. */
    private const NAME_COLUMNS = ['number', 'store_name', 'name', 'title', 'subject', 'email', 'code', 'reference'];

    /** The action groups, for the page's filter. */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    public static function label(string $action): string
    {
        if (isset(self::LABELS[$action])) {
            return self::LABELS[$action];
        }
        [$group, $verb] = array_pad(explode('.', $action, 2), 2, '');
        $name = self::GROUPS[$group] ?? Str::headline($group);

        return $verb === '' ? $name : $name.': '.str_replace('_', ' ', $verb);
    }

    public static function typeName(?string $class): ?string
    {
        if (! $class) {
            return null;
        }
        $base = class_basename($class);

        return self::TYPES[$base] ?? Str::headline($base);
    }

    /**
     * "Store: Addis Sofas (#4)" for each row's subject, looked up with one query per kind of record. A deleted
     * record keeps its kind and number.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, string> keyed "Class:id"
     */
    public static function subjects(Collection $logs): array
    {
        $labels = [];
        foreach ($logs->whereNotNull('subject_type')->groupBy('subject_type') as $class => $rows) {
            $ids = $rows->pluck('subject_id')->filter()->unique()->values();
            $names = [];
            if (is_a($class, Model::class, true)) {
                $model = new $class;
                $columns = array_values(array_intersect(self::NAME_COLUMNS, $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable())));
                if ($columns !== []) {
                    $names = $class::query()->withoutGlobalScopes()->whereIn($model->getKeyName(), $ids)
                        ->get([$model->getKeyName(), ...$columns])
                        ->mapWithKeys(fn ($m) => [$m->getKey() => collect($columns)->map(fn ($c) => $m->getAttribute($c))->first(fn ($v) => filled($v))])
                        ->all();
                }
            }
            foreach ($ids as $id) {
                $name = isset($names[$id]) ? Str::limit((string) $names[$id], 80) : null;
                $labels[$class.':'.$id] = self::typeName($class).': '.($name ? $name.' (#'.$id.')' : '#'.$id);
            }
        }

        return $labels;
    }

    /**
     * The meta as one readable line. A before/after pair lists only what changed ("vat rate percent: 0 to 15").
     */
    public static function details(?array $meta): string
    {
        if (! $meta) {
            return '';
        }
        if (is_array($meta['before'] ?? null) && is_array($meta['after'] ?? null)) {
            $changes = [];
            foreach (array_unique([...array_keys($meta['before']), ...array_keys($meta['after'])]) as $key) {
                $old = $meta['before'][$key] ?? null;
                $new = $meta['after'][$key] ?? null;
                if ($old !== $new) {
                    $changes[] = self::key($key).': '.self::value($old).' to '.self::value($new);
                }
            }
            $rest = array_diff_key($meta, ['before' => 1, 'after' => 1]);

            return implode('; ', [...($changes ?: ['nothing changed']), ...self::pairs($rest)]);
        }

        return implode('; ', self::pairs($meta));
    }

    /** @return list<string> */
    private static function pairs(array $meta): array
    {
        $out = [];
        foreach ($meta as $key => $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                $out[] = self::key((string) $key).': '.self::value($value);
            }
        }

        return $out;
    }

    private static function key(string $key): string
    {
        return str_replace('_', ' ', $key);
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'empty',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => array_is_list($value) && ! array_filter($value, 'is_array')
                ? implode(', ', array_map(fn ($v) => self::value($v), $value))
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }
}
