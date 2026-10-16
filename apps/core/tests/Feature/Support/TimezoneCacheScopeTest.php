<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Platform\Geography\Support\TimezoneResolverService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TimezoneCacheScopeTest extends TestCase
{
    public function test_cloned_geography_ids_do_not_share_cache_across_environment_databases(): void
    {
        $source = config('database.connections.pgsql_test');
        config([
            'database.connections.scope_a' => [...$source, 'database' => 'environment_a'],
            'database.connections.scope_b' => [...$source, 'database' => 'environment_b'],
            'database.default' => 'scope_a',
        ]);
        $resolver = new TimezoneResolverService;
        $first = $resolver->cacheKey('province', 'same-cloned-id');
        Cache::store('array')->put($first, 'first-value', 60);
        config(['database.default' => 'scope_b']);
        $second = $resolver->cacheKey('province', 'same-cloned-id');

        $this->assertNotSame($first, $second);
        $this->assertNull(Cache::store('array')->get($second));
        config(['database.default' => 'scope_a']);
        $this->assertSame($first, $resolver->cacheKey('province', 'same-cloned-id'));
    }
}
