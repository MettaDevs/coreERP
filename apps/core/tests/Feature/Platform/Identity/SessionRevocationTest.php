<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity;

use App\Platform\Identity\Support\SessionRevocation;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class SessionRevocationTest extends TestCase
{
    public function test_revocation_crosses_hosts_but_not_users_and_allows_a_new_login(): void
    {
        config(['session.driver' => 'redis', 'cache.stores.sessions' => ['driver' => 'array']]);
        $sessions = new SessionRevocation;
        $first = $this->requestAt('first.example.test');
        $otherHost = $this->requestAt('second.example.test');
        $bystander = $this->requestAt('first.example.test');
        $sessions->stamp($first, 10);
        $sessions->stamp($otherHost, 10);
        $sessions->stamp($bystander, 20);
        $sessions->revoke(10);

        $this->assertTrue($sessions->revoked($first, 10));
        $this->assertTrue($sessions->revoked($otherHost, 10));
        $this->assertFalse($sessions->revoked($bystander, 20));
        $fresh = $this->requestAt('second.example.test');
        $sessions->stamp($fresh, 10);
        $this->assertFalse($sessions->revoked($fresh, 10));
    }

    public function test_clearing_disposable_cache_does_not_revive_revoked_sessions(): void
    {
        config(['session.driver' => 'redis', 'cache.stores.sessions' => ['driver' => 'array']]);
        $request = $this->requestAt('tenant.example.test');
        $sessions = new SessionRevocation;
        $sessions->revoke(10);
        Cache::store('array')->flush();

        $this->assertTrue($sessions->revoked($request, 10));
    }

    public function test_database_sessions_do_not_open_a_valkey_connection(): void
    {
        config(['session.driver' => 'database']);
        $sessions = new SessionRevocation;
        $request = $this->requestAt('tenant.example.test');
        $sessions->stamp($request, 10);
        $this->assertFalse($sessions->enabled());
        $this->assertFalse($sessions->revoked($request, 10));
    }

    public function test_a_failed_revocation_write_is_not_reported_as_success(): void
    {
        Cache::shouldReceive('store')->with('sessions')->once()->andReturnSelf();
        Cache::shouldReceive('forever')->once()->andReturn(false);
        $this->expectException(RuntimeException::class);
        (new SessionRevocation)->revoke(10);
    }

    private function requestAt(string $host): Request
    {
        $request = Request::create('https://'.$host.'/dashboard');
        $store = new Store('test', new ArraySessionHandler(120));
        $store->start();
        $request->setLaravelSession($store);

        return $request;
    }
}
