<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:admin') or ->middleware('role:seller,admin').
 * Also rejects suspended accounts, so a suspension takes effect on the next request.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user->isActive()) {
            abort(403, 'Your account is suspended.');
        }

        $allowed = array_map(fn (string $r) => Role::from($r), $roles);

        if (! $user->hasRole(...$allowed)) {
            abort(403, 'You do not have access to this area.');
        }

        return $next($request);
    }
}
