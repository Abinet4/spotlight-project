<?php

namespace App\Providers;

use App\Payments\ChapaGateway;
use App\Payments\FakeGateway;
use App\Payments\NullGateway;
use App\Payments\PaymentGateway;
use App\Sms\AfroMessageGateway;
use App\Sms\LogSmsGateway;
use App\Sms\SmsGateway;
use App\Support\RetryingDatabaseStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsGateway::class, fn () => match (config('marketplace.alerts.sms_driver')) {
            'afromessage' => new AfroMessageGateway(config('services.afromessage')),
            default => new LogSmsGateway,
        });

        $this->app->singleton(PaymentGateway::class, fn () => match (config('marketplace.payments.gateway')) {
            // A Chapa test key marks orders paid without real money, so only a test site may use one.
            'chapa' => ! self::isTestSite() && str_starts_with(trim((string) config('services.chapa.secret_key')), 'CHASECK_TEST')
                ? new NullGateway('chapa test key (only allowed when APP_ENV is local, testing or staging)')
                : new ChapaGateway(config('services.chapa')),
            // The test page can mark orders paid without money: only on named test environments, so a
            // live site called "prod" or "live" cannot have it switched on by mistake.
            'fake' => self::isTestSite() ? new FakeGateway : new NullGateway('fake (only allowed when APP_ENV is local, testing or staging)'),
            default => new NullGateway,
        });
    }

    /** Environments where fake payments and demo data are allowed. Any other name counts as live. */
    public static function isTestSite(): bool
    {
        return app()->environment('local', 'testing', 'staging');
    }

    public function boot(): void
    {
        // Same as Laravel's database cache, with deadlocked writes tried again (see the class).
        Cache::extend('database', function ($app, array $config) {
            $store = new RetryingDatabaseStore(
                $app['db']->connection($config['connection'] ?? null),
                $config['table'],
                $this->getPrefix($config),
                $config['lock_table'] ?? 'cache_locks',
                $config['lock_lottery'] ?? [2, 100],
                $config['lock_timeout'] ?? 86400,
                $this->getSerializableClasses($config),
            );

            return $this->repository(
                $store->setLockConnection($app['db']->connection($config['lock_connection'] ?? $config['connection'] ?? null)),
                $config,
            );
        });

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

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by('c|'.$request->user()?->id));

        // Provider callbacks only trigger a verify call, but an open endpoint still needs a ceiling.
        RateLimiter::for('payment-callbacks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Delivery estimates may ask Google (billed per trip); saved addresses feed the same lookup.
        RateLimiter::for('distance', fn (Request $request) => Limit::perMinute(30)->by('d|'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('addresses', fn (Request $request) => Limit::perHour(30)->by('a|'.$request->user()?->id));

        // Profile edits (including email changes) are rare; this also slows down probing which emails exist.
        // Stock adjustments and other ERP records: generous for a busy shop, but not a script.
        RateLimiter::for('erp-writes', fn (Request $request) => Limit::perMinute(60)->by('e|'.$request->user()?->id));
        // Spreadsheet downloads read whole tables; a few a minute is plenty for a person.
        RateLimiter::for('erp-exports', fn (Request $request) => Limit::perMinute(30)->by('x|'.$request->user()?->id));
        RateLimiter::for('account', fn (Request $request) => Limit::perHour(30)->by('p|'.$request->user()?->id));

        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(config('marketplace.catalog.requests_per_minute'))->by($request->user()?->id ?: $request->ip()));
    }
}
