<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A code texted to a user, for the second sign-in step or to check a phone. Written only by
 * App\Services\SignInCodes; the code itself is never stored.
 */
class SignInCode extends Model
{
    public const SIGN_IN = 'sign_in';

    public const PHONE = 'phone';

    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'challenge_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sends' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
