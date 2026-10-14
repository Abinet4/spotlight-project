<?php

namespace App\Models;

use App\Enums\ConversationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * All attributes are set explicitly by the controller; nothing comes from mass assignment.
 */
class Conversation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
            'last_message_id' => 'integer',
            'customer_last_read_id' => 'integer',
            'seller_last_read_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Which side of the conversation the user is on: 'customer', 'seller', or null for outsiders.
     */
    public function sideOf(User $user): ?string
    {
        if ($this->customer_id === $user->id) {
            return 'customer';
        }
        if ($user->sellerProfile && $this->seller_profile_id === $user->sellerProfile->id) {
            return 'seller';
        }

        return null;
    }

    public static function readColumn(string $side): string
    {
        return $side === 'seller' ? 'seller_last_read_id' : 'customer_last_read_id';
    }

    public function isUnreadFor(string $side): bool
    {
        $readId = $this->{self::readColumn($side)};

        return $this->last_message_id !== null && ($readId === null || $this->last_message_id > $readId);
    }

    /**
     * Conversations with messages the given side has not read yet.
     */
    public function scopeUnreadFor(Builder $query, string $side): void
    {
        $readColumn = self::readColumn($side);
        $query->whereNotNull('last_message_id')
            ->where(fn (Builder $q) => $q->whereNull($readColumn)->orWhereColumn('last_message_id', '>', $readColumn));
    }

    /**
     * Record that the given side has seen everything up to the latest message.
     * Never moves the pointer backwards.
     */
    public function markReadFor(string $side): void
    {
        $column = self::readColumn($side);
        if ($this->last_message_id !== null && $this->{$column} !== $this->last_message_id) {
            static::whereKey($this->id)
                ->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '<', $this->last_message_id))
                ->update([$column => $this->last_message_id]);
            $this->{$column} = $this->last_message_id;
            $this->syncOriginalAttribute($column);
        }
    }
}
