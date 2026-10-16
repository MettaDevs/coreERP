<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity;

use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class ValkeyHealthTest extends TestCase
{
    public function test_health_checks_each_configured_store_without_opening_a_business_session(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);
        Redis::shouldReceive('connection')->with('cache')->once()->andReturnSelf();
        Redis::shouldReceive('connection')->with('sessions')->once()->andReturnSelf();
        Redis::shouldReceive('ping')->twice()->andReturn(true);
        $this->get('/up')->assertOk();
    }

    public function test_health_is_not_green_when_the_session_store_is_unavailable(): void
    {
        config(['session.driver' => 'redis', 'app.debug' => false]);
        Redis::shouldReceive('connection')->with('sessions')->once()->andReturnSelf();
        Redis::shouldReceive('ping')->once()->andThrow(new RuntimeException('Store unavailable'));
        $this->get('/up')->assertStatus(500);
    }
}
