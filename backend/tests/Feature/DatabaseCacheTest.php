<?php

namespace Tests\Feature;

use App\Support\RetryingDatabaseStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DatabaseCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_cache_and_the_request_limiter_use_the_retrying_store(): void
    {
        config(['cache.limiter' => 'database']);
        $store = Cache::store('database');
        $this->assertInstanceOf(RetryingDatabaseStore::class, $store->getStore());

        $store->put('a', 'b', 60);
        $this->assertSame('b', $store->get('a'));
        $this->assertTrue($store->add('n', 1, 60));
        $this->assertFalse($store->add('n', 1, 60));
        $this->assertSame(3, $store->increment('n', 2));

        $limiter = app(\Illuminate\Cache\RateLimiter::class);
        $this->assertSame(1, $limiter->hit('visitor'));
        $this->assertSame(2, $limiter->hit('visitor'));
        $this->assertSame(2, RateLimiter::attempts('visitor'));
    }
}
