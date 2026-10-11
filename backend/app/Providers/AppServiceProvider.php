<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Surface lazy loading and silently discarded attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        // New inquiries are capped tighter than replies to slow down spam to sellers.
        RateLimiter::for('inquiries', fn (Request $request) => [
            Limit::perHour(10)->by('h|'.$request->user()?->id),
            Limit::perDay(30)->by('d|'.$request->user()?->id),
        ]);

        RateLimiter::for('messages', fn (Request $request) => [
            Limit::perMinute(20)->by('m|'.$request->user()?->id),
            Limit::perDay(500)->by('d|'.$request->user()?->id),
        ]);

        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
