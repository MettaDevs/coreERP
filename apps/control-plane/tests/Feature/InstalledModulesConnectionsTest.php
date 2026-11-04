<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Environments\EnvironmentRejected;
use ControlPlane\Environments\InstalledModules;
use ControlPlane\Models\Environment;
use ControlPlane\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;

class InstalledModulesConnectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'control_test',
            'database.business_read_connection' => 'business_test',
            'database.connections.control_test' => [
                'driver' => 'pgsql', 'host' => 'control.test', 'database' => 'control_store',
                'username' => 'control_user', 'password' => 'control_secret',
            ],
            'database.connections.business_test' => [
                'driver' => 'pgsql', 'host' => 'ignored.test', 'database' => 'ignored_database',
                'username' => 'ignored_user', 'password' => 'ignored_password',
                'url' => 'postgresql://reader_user:reader_secret@business.test:5544/business_pool?sslmode=require',
                'search_path' => 'business_schema',
            ],
        ]);
    }

    public function test_pooled_production_reads_business_installations_and_names_from_the_control_catalog(): void
    {
        $this->expectInstallations('business_test');
        $catalog = Mockery::mock(Builder::class);
        $catalog->shouldReceive('whereIn')->once()->with('id', ['module-test'])->andReturnSelf();
        $catalog->shouldReceive('pluck')->once()->with('name', 'id')->andReturn(collect(['module-test' => 'Nama dari katalog']));
        DB::shouldReceive('table')->once()->with('apps')->andReturn($catalog);

        $result = (new InstalledModules)(new Environment(['kind' => 'production', 'tenant_id' => 'tenant-test']));

        $this->assertSame([[
            'id' => 'module-test', 'name' => 'Nama dari katalog', 'version' => '1.2.3',
            'status' => 'installed', 'seeded' => true,
        ]], $result);
        $this->assertSame('control_test', config('database.default'));
    }

    public function test_dedicated_read_uses_the_business_url_credentials_and_replaces_the_database(): void
    {
        $alias = 'environment_'.md5('dedicated_test');
        $this->expectInstallations($alias, []);
        DB::shouldReceive('purge')->once()->with($alias);

        $result = (new InstalledModules)((new Environment)->forceFill([
            'kind' => 'sandbox', 'tenant_id' => 'tenant-test', 'database_name' => 'dedicated_test',
        ]));

        $this->assertSame([], $result);
        $this->assertSame('business.test', config('database.connections.'.$alias.'.host'));
        $this->assertSame(5544, config('database.connections.'.$alias.'.port'));
        $this->assertSame('reader_user', config('database.connections.'.$alias.'.username'));
        $this->assertSame('reader_secret', config('database.connections.'.$alias.'.password'));
        $this->assertSame('dedicated_test', config('database.connections.'.$alias.'.database'));
        $this->assertSame('business_schema', config('database.connections.'.$alias.'.search_path'));
        $this->assertNull(config('database.connections.'.$alias.'.url'));
        $this->assertSame('control_test', config('database.default'));
    }

    public function test_repeated_dedicated_reads_do_not_purge_the_same_connection(): void
    {
        $alias = 'environment_'.md5('dedicated_test');
        $rows = Mockery::mock(Builder::class);
        $rows->shouldReceive('where')->twice()->with('tenant_id', 'tenant-test')->andReturnSelf();
        $rows->shouldReceive('orderBy')->twice()->with('module_id')->andReturnSelf();
        $rows->shouldReceive('get')->twice()->with(['module_id', 'version', 'status', 'seeded_at'])->andReturn(collect());
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('table')->twice()->with('core_module_installations')->andReturn($rows);
        DB::shouldReceive('connection')->twice()->with($alias)->andReturn($connection);
        DB::shouldReceive('purge')->once()->with($alias);
        $environment = (new Environment)->forceFill(['kind' => 'sandbox', 'tenant_id' => 'tenant-test', 'database_name' => 'dedicated_test']);
        $reader = new InstalledModules;

        $this->assertSame([], $reader($environment));
        $this->assertSame([], $reader($environment));
    }

    public function test_an_unprovisioned_demo_or_sandbox_does_not_read_pooled_installations(): void
    {
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();

        foreach (['demo', 'sandbox'] as $kind) {
            $this->assertSame([], (new InstalledModules)(new Environment(['kind' => $kind, 'tenant_id' => 'tenant-test'])));
        }
    }

    public function test_an_empty_business_alias_cannot_fall_back_to_the_control_database(): void
    {
        config(['database.business_read_connection' => '']);
        DB::shouldReceive('connection')->never();
        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('belum dapat dibaca');

        (new InstalledModules)(new Environment(['kind' => 'production', 'tenant_id' => 'tenant-test']));
    }

    /** @param list<object>|null $installed */
    private function expectInstallations(string $alias, ?array $installed = null): void
    {
        $rows = Mockery::mock(Builder::class);
        $rows->shouldReceive('where')->once()->with('tenant_id', 'tenant-test')->andReturnSelf();
        $rows->shouldReceive('orderBy')->once()->with('module_id')->andReturnSelf();
        $rows->shouldReceive('get')->once()->with(['module_id', 'version', 'status', 'seeded_at'])->andReturn(collect($installed ?? [
            (object) ['module_id' => 'module-test', 'version' => '1.2.3', 'status' => 'installed', 'seeded_at' => '2026-01-01'],
        ]));
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('table')->once()->with('core_module_installations')->andReturn($rows);
        DB::shouldReceive('connection')->once()->with($alias)->andReturn($connection);
    }
}
