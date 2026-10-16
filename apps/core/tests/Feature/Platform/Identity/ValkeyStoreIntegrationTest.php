<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RedisException;
use Tests\TestCase;

class ValkeyStoreIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('VALKEY_TEST_PORT')) {
            $this->markTestSkipped('Valkey integration dijalankan oleh CI dengan server sungguhan.');
        }
        $this->assertTrue(extension_loaded('redis'), 'CI harus memasang client phpredis.');
        foreach (['cache' => 'VALKEY_CACHE_PASSWORD', 'sessions' => 'VALKEY_SESSION_PASSWORD'] as $connection => $password) {
            config([
                "database.redis.{$connection}.host" => '127.0.0.1',
                "database.redis.{$connection}.port" => (int) getenv('VALKEY_TEST_PORT'),
                "database.redis.{$connection}.username" => $connection,
                "database.redis.{$connection}.password" => getenv($password),
            ]);
        }
        config(['cache.default' => 'redis', 'database.redis.options.prefix' => 'integration:'.Str::uuid().':']);
    }

    public function test_session_store_and_locks_work_with_separate_credentials(): void
    {
        $key = 'integration:'.Str::uuid();
        try {
            Cache::store('sessions')->put($key, 'session-value', 60);
            $this->assertNull(Cache::store('redis')->get($key));
            Cache::store('redis')->put($key, 'cache-value', 60);
            $this->assertSame('session-value', Cache::store('sessions')->get($key));
            $this->assertSame('cache-value', Cache::store('redis')->get($key));
            $lock = Cache::lock($key.':lock', 10);
            $this->assertTrue($lock->get());
            $this->assertFalse(Cache::lock($key.':lock', 10)->get());
            $this->assertTrue($lock->release());
            $this->assertTrue(Cache::add($key.':counter', 1, 60));
            $this->assertSame(2, Cache::increment($key.':counter'));
        } finally {
            Cache::store('sessions')->forget($key);
            Cache::store('redis')->forget($key);
            Cache::store('redis')->forget($key.':counter');
        }
    }

    public function test_cache_credentials_cannot_select_the_session_database(): void
    {
        $this->expectException(RedisException::class);
        Redis::connection('cache')->select(2);
    }

    public function test_laravel_session_round_trip_does_not_redirect_the_default_cache_store(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'sessions', 'session.store' => 'sessions', 'session.encrypt' => true]);
        $manager = app('session');
        $first = $manager->driver('redis');
        $first->start();
        $first->put('integration_value', 'opaque-session-data');
        $id = $first->getId();
        $first->save();
        $manager->forgetDrivers();
        $second = $manager->driver('redis');
        $second->setId($id);
        $second->start();
        $this->assertSame('opaque-session-data', $second->get('integration_value'));
        $key = 'integration:'.Str::uuid();
        try {
            Cache::put($key, 'cache-only', 60);
            $this->assertNull(Cache::store('sessions')->get($key));
        } finally {
            Cache::forget($key);
            $second->getHandler()->destroy($id);
        }
    }

    public function test_cache_flush_is_denied_instead_of_clearing_security_state(): void
    {
        $this->expectException(RedisException::class);
        Cache::store('redis')->flush();
    }
}
