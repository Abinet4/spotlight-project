<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a server start does on a host such as Railway (docker/start.sh), and the settings for pages
 * and API living at different addresses.
 */
class DeployTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            $this->putEnv($key, $value === false ? null : $value);
        }
        // Trusted proxies are process-wide in Symfony; do not leak them into other tests.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    private function env(string $key, ?string $value): void
    {
        $this->savedEnv[$key] ??= getenv($key);
        $this->putEnv($key, $value);
    }

    private function putEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    public function test_the_first_admin_comes_from_the_deploy_variables_and_is_left_alone_afterwards(): void
    {
        $this->env('ADMIN_PASSWORD', 'Strong-test-pass-1!');
        $this->artisan('marketplace:create-admin', ['email' => '  Owner@Example.com ', '--password-from-env' => true, '--if-missing' => true, '--no-interaction' => true])
            ->expectsOutput('Administrator owner@example.com is ready.')
            ->assertExitCode(0);
        $admin = User::where('email', 'owner@example.com')->sole();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue(Hash::check('Strong-test-pass-1!', $admin->password));
        $this->assertTrue(AuditLog::where('action', 'admin.created_via_cli')->exists());

        // Every later start: the password changed on the site stays, even if the variable differs.
        $admin->forceFill(['password' => 'Changed-on-site-2!'])->save();
        $this->env('ADMIN_PASSWORD', 'Another-pass-3!');
        $this->artisan('marketplace:create-admin', ['email' => 'owner@example.com', '--password-from-env' => true, '--if-missing' => true, '--no-interaction' => true])
            ->expectsOutput('owner@example.com already has an account (admin); left unchanged.')
            ->assertExitCode(0);
        $this->assertTrue(Hash::check('Changed-on-site-2!', $admin->fresh()->password));

        // A customer who already uses that email is not turned into an admin by a deploy variable.
        $customer = $this->customer();
        $this->artisan('marketplace:create-admin', ['email' => $customer->email, '--password-from-env' => true, '--if-missing' => true, '--no-interaction' => true])
            ->assertExitCode(0);
        $this->assertSame(Role::Customer, $customer->fresh()->role);
    }

    public function test_a_weak_or_missing_deploy_password_creates_nobody(): void
    {
        foreach (['short', ''] as $password) {
            $this->env('ADMIN_PASSWORD', $password);
            $this->artisan('marketplace:create-admin', ['email' => 'owner@example.com', '--password-from-env' => true, '--if-missing' => true, '--no-interaction' => true])
                ->assertExitCode(1);
        }
        $this->assertFalse(User::where('email', 'owner@example.com')->exists());
    }

    public function test_the_typed_password_still_works_without_the_deploy_options(): void
    {
        $this->artisan('marketplace:create-admin', ['email' => 'me@example.com'])
            ->expectsQuestion('Password (min 12 characters, letters, numbers and symbols)', 'Typed-pass-123!')
            ->assertExitCode(0);
        $this->assertSame(Role::Admin, User::where('email', 'me@example.com')->sole()->role);
    }

    public function test_reference_data_is_added_to_a_new_database_only(): void
    {
        $this->artisan('marketplace:prepare')->assertExitCode(0);
        $this->assertTrue(City::exists());
        $count = Category::count();
        $this->assertGreaterThan(5, $count);

        // The admin removes a category; the next deploy must not bring it back.
        Category::where('slug', 'office')->delete();
        $this->artisan('marketplace:prepare')->assertExitCode(0);
        $this->assertSame($count - 1, Category::count());
        $this->assertFalse(Category::where('slug', 'office')->exists());
    }

    public function test_demo_data_is_added_once_and_never_in_production(): void
    {
        Storage::fake('public');

        $this->app['env'] = 'production';
        $this->artisan('marketplace:prepare', ['--demo' => true])
            ->expectsOutput('Demo data is for test sites only, so it was not added (APP_ENV=production).')
            ->assertExitCode(0);
        $this->assertFalse(User::where('email', 'customer@demo.local')->exists());
        $this->app['env'] = 'testing';

        $this->env('DEMO_USER_PASSWORD', null);
        $this->artisan('marketplace:prepare', ['--demo' => true])->assertExitCode(1);
        $this->assertFalse(User::where('email', 'customer@demo.local')->exists());

        $this->env('DEMO_USER_PASSWORD', 'Demo-test-pass-9');
        $this->artisan('marketplace:prepare', ['--demo' => true])->assertExitCode(0);
        $customer = User::where('email', 'customer@demo.local')->sole();
        $this->assertTrue(Hash::check('Demo-test-pass-9', $customer->password));
        $listings = Product::count();
        $this->assertGreaterThan(10, $listings);
        $this->assertSame(1, AuditLog::where('action', 'demo.seeded')->count());

        // A demo listing the admin deleted stays deleted on the next start.
        Product::query()->first()->delete();
        $this->artisan('marketplace:prepare', ['--demo' => true])
            ->expectsOutput('Demo data was added on an earlier start; skipped.')
            ->assertExitCode(0);
        $this->assertSame($listings - 1, Product::count());
    }

    public function test_photo_links_use_the_api_address_when_it_is_set(): void
    {
        $this->env('APP_URL', 'https://shop.vercel.app');
        $this->env('API_URL', 'https://api.up.railway.app/');
        $this->assertSame('https://api.up.railway.app/storage', (require config_path('filesystems.php'))['disks']['public']['url']);

        // Same address for pages and API: nothing to set.
        $this->env('API_URL', '');
        $this->assertSame('https://shop.vercel.app/storage', (require config_path('filesystems.php'))['disks']['public']['url']);
    }

    public function test_allowed_shop_origins_ignore_spaces_and_trailing_slashes(): void
    {
        $this->env('CORS_ALLOWED_ORIGINS', ' https://shop.vercel.app/ ,https://www.example.et,, ');
        $this->assertSame(['https://shop.vercel.app', 'https://www.example.et'], (require config_path('cors.php'))['allowed_origins']);
    }

    public function test_visitor_addresses_behind_a_hosting_proxy(): void
    {
        Route::get('/_test/ip', fn (Request $request) => $request->ip());
        // The visitor sent a made-up first entry; the hosting proxy added the address it really saw.
        $ip = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeader('X-Forwarded-For', '6.6.6.6, 198.51.100.7')->get('/_test/ip')->getContent();

        config(['trustedproxy.proxies' => null]);
        $this->assertSame('10.0.0.5', $ip(), 'no proxy trusted: the header is ignored');

        config(['trustedproxy.proxies' => 'REMOTE_ADDR']);
        $this->assertSame('198.51.100.7', $ip(), 'one proxy trusted: its entry is used, the made-up one is not');

        config(['trustedproxy.proxies' => '*']);
        $this->assertSame('6.6.6.6', $ip(), 'trusting every hop believes the made-up entry, which is why REMOTE_ADDR is recommended');

        // An empty variable means none, not a broken list.
        $this->env('TRUSTED_PROXIES', '');
        $this->assertNull((require config_path('trustedproxy.php'))['proxies']);
    }
}
