<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SellerLedgerEntry;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What each seller owes the platform. Every change is a new ledger row; rows are never edited.
 */
class LedgerService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Alerts $alerts,
    ) {}

    public static function balance(int $sellerProfileId): int
    {
        return (int) SellerLedgerEntry::where('seller_profile_id', $sellerProfileId)->sum('amount_santim');
    }

    /**
     * Charge the order's commission once. Called inside the transaction that marks a cash order delivered;
     * the unique (order_id, type) index makes a second charge impossible even if this ran twice.
     */
    public function chargeCommission(Order $order): void
    {
        if ($order->commission_santim <= 0) {
            return;
        }
        if (SellerLedgerEntry::where('order_id', $order->id)->where('type', 'commission')->exists()) {
            return;
        }
        $entry = new SellerLedgerEntry;
        $entry->seller_profile_id = $order->seller_profile_id;
        $entry->type = 'commission';
        $entry->amount_santim = $order->commission_santim;
        $entry->order_id = $order->id;
        $entry->note = "Commission on {$order->number}";
        $entry->save();
    }

    /**
     * Record money received from a seller. The same reference cannot be recorded twice for a seller,
     * and a payment cannot exceed the balance (a typo would otherwise create a large credit).
     */
    public function recordPayment(int $sellerProfileId, int $amountSantim, string $method, ?string $reference, ?string $note, User $admin): SellerLedgerEntry
    {
        try {
            return DB::transaction(function () use ($sellerProfileId, $amountSantim, $method, $reference, $note, $admin) {
                $seller = SellerProfile::lockForUpdate()->findOrFail($sellerProfileId);
                if ($reference !== null && SellerLedgerEntry::where('seller_profile_id', $seller->id)->where('reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['reference' => 'A payment with this reference is already recorded for this store.']);
                }
                $balance = self::balance($seller->id);
                if ($amountSantim > $balance) {
                    throw ValidationException::withMessages(['amount' => 'This is more than the store owes ('.number_format($balance / 100, 2).' ETB). Use an adjustment for credits.']);
                }

                $entry = new SellerLedgerEntry;
                $entry->seller_profile_id = $seller->id;
                $entry->type = 'payment';
                $entry->amount_santim = -$amountSantim;
                $entry->method = $method;
                $entry->reference = $reference;
                $entry->note = $note;
                $entry->created_by = $admin->id;
                $entry->save();

                $this->audit->log('settlement.payment_recorded', $seller, ['amount_santim' => $amountSantim, 'method' => $method, 'reference' => $reference, 'entry_id' => $entry->id]);
                $this->alerts->paymentRecorded($entry, $balance - $amountSantim);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => 'A payment with this reference is already recorded for this store.']);
        }
    }

    /**
     * A correction with a reason: positive adds to what the seller owes, negative forgives part of it.
     */
    public function adjust(int $sellerProfileId, int $amountSantim, string $note, User $admin): SellerLedgerEntry
    {
        return DB::transaction(function () use ($sellerProfileId, $amountSantim, $note, $admin) {
            $seller = SellerProfile::lockForUpdate()->findOrFail($sellerProfileId);

            $entry = new SellerLedgerEntry;
            $entry->seller_profile_id = $seller->id;
            $entry->type = 'adjustment';
            $entry->amount_santim = $amountSantim;
            $entry->note = $note;
            $entry->created_by = $admin->id;
            $entry->save();

            $this->audit->log('settlement.adjusted', $seller, ['amount_santim' => $amountSantim, 'note' => $note, 'entry_id' => $entry->id]);

            return $entry;
        });
    }
}
