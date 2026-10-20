<?php

namespace App\Http\Controllers\Api\Account;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

trait ChecksCurrentPassword
{
    /**
     * Wrong guesses are limited per account (5 every 15 minutes), so a token left open on a shared
     * computer cannot be used to work out the password. Typos in other fields are not counted.
     */
    private function checkCurrentPassword(User $user, ?string $password): void
    {
        if (! filled($password)) {
            throw ValidationException::withMessages(['current_password' => 'Enter your current password to make this change.']);
        }
        // Count the attempt before checking it, so a burst of parallel guesses cannot slip past the limit.
        $key = 'current-password|'.$user->id;
        if (RateLimiter::hit($key, 15 * 60) > 5) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['current_password' => "Too many wrong passwords. Try again in {$minutes} min."])->status(429);
        }
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is not correct.']);
        }
        RateLimiter::clear($key);
    }
}
