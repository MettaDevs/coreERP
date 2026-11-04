<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Environment;

use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Support\ActiveEnvironment;
use App\Platform\Environment\Support\EnvironmentConnection;
use App\Platform\Identity\Models\User;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class ConnectionResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $base = ['driver' => 'pgsql', 'port' => '5432', 'search_path' => 'public'];
        config([
            'coreerp.database_mode' => 'split',
            'coreerp.control_connection' => 'boundary_control',
            'coreerp.business_connection' => 'boundary_business',
            'coreerp.business_migration_connection' => 'boundary_migration',
            'coreerp.maintenance_connection' => 'boundary_provisioning',
            'database.default' => 'boundary_business',
            'database.connections.boundary_control' => $base + ['host' => 'control.invalid', 'database' => 'control_pool', 'username' => 'control_runtime'],
            'database.connections.boundary_business' => $base + ['host' => 'business.invalid', 'database' => 'business_pool', 'username' => 'business_runtime'],
            'database.connections.boundary_migration' => $base + ['host' => 'business.invalid', 'database' => 'business_pool', 'username' => 'business_owner'],
            'database.connections.boundary_provisioning' => $base + ['host' => 'business.invalid', 'database' => 'postgres', 'username' => 'provisioner'],
        ]);
    }

    public function test_nested_dedicated_and_pooled_contexts_restore_their_parent_after_failure(): void
    {
        $connections = app(EnvironmentConnection::class);
        $outer = $this->environment('outer', 'environment_outer');
        $inner = $this->environment('inner', 'environment_inner');
        $connections->runWithin($outer, function () use ($connections, $inner): void {
            $this->assertSame('environment_outer', config('database.connections.'.config('database.default').'.database'));
            $this->assertSame('boundary_control', (new User)->getConnectionName());
            try {
                $connections->runWithin($inner, function () use ($connections): void {
                    $connections->runWithin(null, function (): void {
                        $this->assertSame('boundary_business', config('database.default'));
                        $this->assertFalse(app()->make(ActiveEnvironment::KEY));
                        throw new RuntimeException('fixture failure');
                    });
                });
                $this->fail('The fixture exception must escape.');
            } catch (RuntimeException $failure) {
                $this->assertSame('fixture failure', $failure->getMessage());
            }
            $this->assertSame('environment_outer', config('database.connections.'.config('database.default').'.database'));
            $this->assertSame('outer', app()->make(ActiveEnvironment::KEY));
        });
        $this->assertSame('boundary_business', config('database.default'));
        $this->assertFalse(app()->bound(ActiveEnvironment::KEY));
    }

    public function test_environment_connections_use_business_credentials_and_resolve_the_url_before_replacing_the_database(): void
    {
        config(['database.connections.boundary_business.url' => 'postgresql://business_runtime:fixture-password@business.invalid:5432/business_pool']);
        $name = app(EnvironmentConnection::class)->for($this->environment('url', 'environment_url'));
        $configuration = config('database.connections.'.$name);
        $this->assertSame('business.invalid', $configuration['host']);
        $this->assertSame('business_runtime', $configuration['username']);
        $this->assertSame('environment_url', $configuration['database']);
        $this->assertNull($configuration['url']);
    }

    public function test_reregistering_an_identical_target_preserves_the_connection_but_changed_credentials_replace_it(): void
    {
        $connections = app(EnvironmentConnection::class);
        $name = $connections->register('environment_repeat', 'environment_repeat');
        $before = DB::connection($name);
        $connections->register($name, 'environment_repeat');
        $this->assertSame($before, DB::connection($name));
        config(['database.connections.boundary_business.username' => 'new_business_runtime']);
        $connections->register($name, 'environment_repeat');
        $this->assertNotSame($before, DB::connection($name));
        $this->assertSame('new_business_runtime', DB::connection($name)->getConfig('username'));
    }

    public function test_control_aliases_are_rejected_for_business_migrations(): void
    {
        config(['database.connections.another_name' => config('database.connections.boundary_control')]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database control');
        app(EnvironmentConnection::class)->migrationFor('another_name');
    }

    public function test_maintenance_does_not_inherit_runtime_credentials(): void
    {
        $connections = app(EnvironmentConnection::class);
        $name = $connections->maintenance();
        $this->assertSame('boundary_provisioning', $name);
        $this->assertSame('provisioner', $connections->configuration($name)['username']);
    }

    public function test_request_cleanup_restores_a_worker_before_the_next_environment(): void
    {
        $connections = app(EnvironmentConnection::class);
        $connections->useForRequest($this->environment('request', 'environment_request'));
        $connections->reset();
        $this->assertSame('boundary_business', config('database.default'));
        $this->assertFalse(app()->bound(ActiveEnvironment::KEY));
        $this->assertSame('boundary_control', $connections->controlPlane());
    }

    public function test_split_identity_does_not_fall_back_when_its_control_alias_is_missing(): void
    {
        config(['coreerp.control_connection' => null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('control');
        (new User)->getConnectionName();
    }

    public function test_split_pooled_business_does_not_fall_back_to_the_active_default(): void
    {
        config(['coreerp.business_connection' => null, 'database.default' => 'boundary_control']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bisnis');
        app(EnvironmentConnection::class)->for(null);
    }

    public function test_context_closes_both_connection_purposes_and_preserves_ancestor_migration_pdo(): void
    {
        DB::extend('boundary_lifetime', fn (array $configuration): PostgresConnection => new PostgresConnection(new ConnectionLifetimePdo, $configuration['database'], '', $configuration));
        foreach (['boundary_control', 'boundary_business', 'boundary_migration', 'boundary_provisioning'] as $name) {
            config(['database.connections.'.$name.'.driver' => 'boundary_lifetime']);
        }
        $connections = app(EnvironmentConnection::class);
        $outer = $this->environment('lifetime_a', 'environment_lifetime_a');
        $inner = $this->environment('lifetime_b', 'environment_lifetime_b');
        $connections->runWithin($outer, function () use ($connections, $outer, $inner): void {
            $runtime = (string) config('database.default');
            DB::connection($runtime)->getPdo();
            $migration = $connections->migrationFor($runtime);
            $pdo = DB::connection($migration)->getPdo();
            $connections->runWithin($inner, function () use ($connections, $outer, $migration, $pdo): void {
                $connections->runWithin($outer, fn () => $this->assertSame($pdo, DB::connection($migration)->getRawPdo()));
                $this->assertSame($pdo, DB::connection($migration)->getRawPdo());
            });
            $this->assertSame($pdo, DB::connection($migration)->getRawPdo());
        });
        foreach (['environment_lifetime_a', 'environment_lifetime_a_migration', 'environment_lifetime_b', 'environment_lifetime_b_migration'] as $name) {
            $this->assertArrayNotHasKey($name, DB::getConnections());
        }
    }

    private function environment(string $id, string $database): Environment
    {
        return (new Environment)->forceFill(['id' => $id, 'database_name' => $database]);
    }
}

final class ConnectionLifetimePdo extends PDO
{
    public function __construct() {}
}
