<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\ControlPlane;

use App\Platform\ControlPlane\Console\CopyEnvironment;
use App\Platform\ControlPlane\Console\PurgeEnvironment;
use App\Platform\Environment\Support\EnvironmentConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class EnvironmentOperationConnectionsTest extends TestCase
{
    private EnvironmentConnection $connections;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'control_test',
            'coreerp.database_mode' => 'split',
            'coreerp.database' => 'legacy_combined',
            'coreerp.control_connection' => 'control_test',
            'coreerp.business_connection' => 'business_test',
            'coreerp.business_migration_connection' => 'business_owner_test',
            'coreerp.maintenance_connection' => 'provisioner_test',
            'database.connections.control_test' => $this->configuration('control_store', 'control_user'),
            'database.connections.business_test' => [
                ...$this->configuration('ignored_database', 'ignored_user'),
                'url' => 'postgresql://business_user:business_secret@business.test:5544/business_pool?sslmode=require',
            ],
            'database.connections.business_owner_test' => [
                ...$this->configuration('ignored_database', 'ignored_user'),
                'url' => 'postgresql://migration_user:migration_secret@business.test:5544/business_pool?sslmode=require',
            ],
            'database.connections.provisioner_test' => $this->configuration('postgres', 'provisioner_user'),
        ]);

        $this->connections = new EnvironmentConnection;
    }

    public function test_copy_clients_use_business_credentials_for_dump_and_migration_credentials_for_restore(): void
    {
        $source = $this->connections->register('environment_copy_source', 'copy_source');
        $target = $this->connections->register('environment_copy', 'copy_target');
        $migration = $this->connections->migrationFor($target);
        $command = new CopyEnvironment($this->connections);
        $file = '/tmp/copy-test.dump';
        Process::fake();

        $dump = (new ReflectionMethod($command, 'dumpCommand'))->invoke($command, $source, $file);
        $restore = (new ReflectionMethod($command, 'restoreCommand'))->invoke($command, $migration, $file);
        $run = new ReflectionMethod($command, 'runClient');
        $run->invoke($command, $dump, 'pg_dump', $source);
        $run->invoke($command, $restore, 'pg_restore', $migration);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'pg_dump', '--host=business.test', '--port=5544', '--username=business_user',
            '--no-password', '--format=custom', '--no-owner', '--no-privileges',
            '--file='.$file, '--dbname=copy_source',
        ] && $process->environment['PGPASSWORD'] === 'business_secret'
            && $process->environment['PGSSLMODE'] === 'require');
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'pg_restore', '--host=business.test', '--port=5544', '--username=migration_user',
            '--no-password', '--no-owner', '--no-privileges', '--single-transaction',
            '--dbname=copy_target', $file,
        ] && $process->environment['PGPASSWORD'] === 'migration_secret'
            && $process->environment['PGSSLMODE'] === 'require');
        $this->assertSame('control_test', config('database.default'));
    }

    public function test_a_passwordless_client_overrides_an_inherited_pgpassword(): void
    {
        config(['database.connections.business_test' => $this->configuration('business_pool', 'business_user')]);
        $source = $this->connections->register('environment_copy_source', 'copy_source');
        $command = new CopyEnvironment($this->connections);
        $environment = (new ReflectionMethod($command, 'clientEnvironment'))->invoke($command, $source);

        $this->assertArrayHasKey('PGPASSWORD', $environment);
        $this->assertSame('', $environment['PGPASSWORD']);

        $previous = getenv('PGPASSWORD');
        putenv('PGPASSWORD=inherited_secret');

        try {
            $result = Process::env($environment)->run([PHP_BINARY, '-r', 'echo getenv("PGPASSWORD");']);
            $this->assertTrue($result->successful());
            $this->assertSame('', $result->output());
        } finally {
            putenv($previous === false ? 'PGPASSWORD' : 'PGPASSWORD='.$previous);
        }
    }

    public function test_copy_creates_its_database_with_the_provisioning_connection(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')->once()->with('CREATE DATABASE "copy_target"')->andReturn(0);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('selectOne')->once()
            ->with('select 1 from pg_database where datname = ?', ['copy_target'])->andReturnNull();
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        DB::shouldReceive('connection')->once()->with('provisioner_test')->andReturn($connection);
        DB::shouldReceive('purge')->once()->with('provisioner_test');
        $command = new CopyEnvironment($this->connections);

        $this->assertTrue((new ReflectionMethod($command, 'createDatabase'))->invoke($command, 'copy_target', 'copy_source'));
    }

    public function test_purge_drops_its_database_with_the_provisioning_connection(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')->once()->with('DROP DATABASE IF EXISTS "copy_target" WITH (FORCE)')->andReturn(0);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        DB::shouldReceive('connection')->once()->with('provisioner_test')->andReturn($connection);
        DB::shouldReceive('purge')->once()->with('provisioner_test');
        $command = new PurgeEnvironment($this->connections);

        $this->assertTrue((new ReflectionMethod($command, 'dropDatabase'))->invoke($command, 'copy_target'));
    }

    public function test_purge_releases_the_provisioning_connection_after_a_drop_failure(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')->once()->andThrow(new RuntimeException('drop failed'));
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        DB::shouldReceive('connection')->once()->with('provisioner_test')->andReturn($connection);
        DB::shouldReceive('purge')->once()->with('provisioner_test');
        $command = new PurgeEnvironment($this->connections);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('drop failed');

        (new ReflectionMethod($command, 'dropDatabase'))->invoke($command, 'copy_target');
    }

    public function test_copy_disarms_registry_constraints_as_migrator_and_rows_as_runtime(): void
    {
        $target = $this->connections->register('environment_copy', 'copy_target');
        $migration = $this->connections->migrationFor($target);
        $schema = Mockery::mock(Connection::class);
        $schema->shouldReceive('statement')->once()->with('DROP INDEX IF EXISTS environments_satu_per_jenis')->andReturnTrue();
        $schema->shouldReceive('statement')->once()
            ->with('ALTER TABLE environments DROP CONSTRAINT IF EXISTS environments_server_klien_hanya_produksi')->andReturnTrue();
        DB::shouldReceive('connection')->once()->with($migration)->andReturn($schema);
        $rows = Mockery::mock(Builder::class);
        $rows->shouldReceive('where')->once()->with(Mockery::type('Closure'))->andReturnSelf();
        $rows->shouldReceive('update')->once()->with(Mockery::on(fn (array $values): bool => $values['kind'] === 'sandbox'
            && $values['outbound_allowed'] === false))->andReturn(3);
        $runtime = Mockery::mock(Connection::class);
        $runtime->shouldReceive('statement')->never();
        $runtime->shouldReceive('table')->once()->with('environments')->andReturn($rows);
        $command = new CopyEnvironment($this->connections);

        $this->assertSame(3, (new ReflectionMethod($command, 'disarmCopiedRegistry'))->invoke($command, $runtime));
    }

    #[DataProvider('reservedDatabases')]
    public function test_purge_refuses_authority_and_pooled_databases_before_opening_a_connection(string $database): void
    {
        DB::shouldReceive('connection')->never();
        $command = new PurgeEnvironment($this->connections);
        $this->expectException(RuntimeException::class);

        (new ReflectionMethod($command, 'dropDatabase'))->invoke($command, $database);
    }

    #[DataProvider('reservedDatabases')]
    public function test_copy_refuses_authority_and_pooled_databases_before_opening_a_connection(string $database): void
    {
        DB::shouldReceive('connection')->never();
        $command = new CopyEnvironment($this->connections);
        $this->expectException(RuntimeException::class);

        (new ReflectionMethod($command, 'createDatabase'))->invoke($command, $database, 'copy_source');
    }

    public function test_copy_still_refuses_to_restore_onto_its_source(): void
    {
        DB::shouldReceive('connection')->never();
        $command = new CopyEnvironment($this->connections);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sama dengan databasenya sumber');

        (new ReflectionMethod($command, 'createDatabase'))->invoke($command, 'copy_source', 'copy_source');
    }

    public function test_purge_skips_environments_without_a_dedicated_database(): void
    {
        DB::shouldReceive('connection')->never();
        $command = new PurgeEnvironment($this->connections);
        $drop = new ReflectionMethod($command, 'dropDatabase');

        $this->assertFalse($drop->invoke($command, null));
        $this->assertFalse($drop->invoke($command, ''));
    }

    /** @return array<string, array{string}> */
    public static function reservedDatabases(): array
    {
        return [
            'control' => ['control_store'],
            'pooled business from url' => ['business_pool'],
            'maintenance' => ['postgres'],
            'legacy configured database' => ['legacy_combined'],
        ];
    }

    /** @return array<string, mixed> */
    private function configuration(string $database, string $username): array
    {
        return [
            'driver' => 'pgsql', 'host' => 'business.test', 'port' => '5544',
            'database' => $database, 'username' => $username, 'password' => '',
            'search_path' => 'public', 'sslmode' => 'prefer',
        ];
    }
}
