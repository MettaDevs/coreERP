<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\ControlPlane;

use App\Platform\ControlPlane\Console\ControlMigrate;
use App\Platform\Environment\Support\EnvironmentConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\DropsTestDatabases;
use Tests\TestCase;

#[Group('serial')]
final class ControlSchemaMigrationsTest extends TestCase
{
    use DropsTestDatabases;

    private string $databaseName;

    private bool $created = false;

    private Connection $maintenance;

    private Connection $target;

    private ?string $runtimeRole = null;

    protected function setUp(): void
    {
        parent::setUp();
        $configuration = app(EnvironmentConnection::class)->configuration((string) config('database.default'));
        config(['database.connections.control_schema_maintenance' => $configuration]);
        $this->maintenance = DB::connection('control_schema_maintenance');
        $this->databaseName = 'core_erp_test_control_'.strtolower((string) Str::ulid());
        $this->maintenance->getPdo()->exec('CREATE DATABASE "'.$this->databaseName.'"');
        $this->created = true;
        $configuration['database'] = $this->databaseName;
        $configuration['url'] = null;
        $configuration['search_path'] = 'coreerp_test';
        config(['database.connections.control_schema_target' => $configuration]);
        $this->target = DB::connection('control_schema_target');
        $actual = $this->target->selectOne('select current_database() as database');
        if ($actual->database !== $this->databaseName) {
            throw new RuntimeException('Control schema fixture connected to another database.');
        }
        $this->target->statement('CREATE SCHEMA coreerp_test');
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('control_schema_target');
            DB::purge('control_schema_migration');
            DB::purge('control_schema_runtime');
            if ($this->created) {
                if (! preg_match('/^core_erp_test_control_[a-z0-9]{26}$/', $this->databaseName)) {
                    throw new RuntimeException('Refusing a database outside this test journal.');
                }
                $this->dropTestDatabase($this->maintenance, $this->databaseName);
            }
            if ($this->runtimeRole !== null) {
                if (! preg_match('/^coreerp_test_role_[a-z0-9]{26}$/', $this->runtimeRole)) {
                    throw new RuntimeException('Refusing a role outside this test journal.');
                }
                $this->maintenance->getPdo()->exec('DROP ROLE "'.$this->runtimeRole.'"');
            }
            DB::purge('control_schema_maintenance');
        } finally {
            parent::tearDown();
        }
    }

    public function test_fresh_baseline_and_original_pf01_upgrade_are_isolated_and_idempotent(): void
    {
        $businessConnection = (string) config('database.default');
        $businessHistory = DB::connection()->getSchemaBuilder()->hasTable('migrations')
            ? DB::table('migrations')->count() : null;
        $migrator = $this->migrator();
        $paths = require database_path('control-plane/manifest.php');
        $migrator->usingConnection('control_schema_target', fn () => $migrator->run([$paths[0]]));
        $schema = $this->target->getSchemaBuilder();
        $this->assertFalse($schema->hasColumn('product_customers', 'user_id'));
        $this->assertFalse($schema->hasTable('tenant_creation_requests'));
        foreach ($schema->getTables('coreerp_test') as $table) {
            if ($table['name'] !== ControlMigrate::HISTORY) {
                $this->assertSame(0, $this->target->table($table['name'])->count());
            }
        }
        foreach (['roles', 'role_assignments', 'security_duties', 'security_privileges', 'fiscal_calendars', 'core_module_installations', 'core_module_migrations'] as $table) {
            $this->assertFalse($schema->hasTable($table));
        }
        $this->assertFalse($schema->hasColumn('legal_entities', 'company_code'));
        $this->assertFalse($schema->hasColumn('legal_entities', 'fiscal_calendar_id'));
        $this->assertEqualsCanonicalizing(['id', 'name', 'status', 'description', 'created_at', 'updated_at'], $schema->getColumnListing('apps'));
        $customerId = (string) Str::uuid();
        $this->target->table('product_customers')->insert([
            'id' => $customerId, 'identity_hash' => str_repeat('a', 64),
            'issuer' => 'https://identity.fixture.invalid', 'subject' => 'upgrade-fixture', 'name' => 'Synthetic fixture',
        ]);
        $migrator->usingConnection('control_schema_target', fn () => $migrator->run($paths));
        $this->assertTrue($schema->hasTable('tenant_creation_requests'));
        $this->assertNull($this->target->table('product_customers')->where('id', $customerId)->value('user_id'));
        $this->assertSame('Synthetic fixture', $this->target->table('product_customers')->where('id', $customerId)->value('name'));
        $this->assertSame([], $migrator->usingConnection('control_schema_target', fn () => $migrator->run($paths)));
        $this->assertSame(2, $this->target->table(ControlMigrate::HISTORY)->count());
        $this->assertSame($businessConnection, config('database.default'));
        $this->assertSame($businessHistory, DB::connection()->getSchemaBuilder()->hasTable('migrations')
            ? DB::table('migrations')->count() : null);
        $foreign = $this->target->selectOne(<<<'SQL'
            select count(*) as total from pg_constraint k
            join pg_class source on source.oid = k.conrelid join pg_namespace sn on sn.oid = source.relnamespace
            join pg_class target on target.oid = k.confrelid join pg_namespace tn on tn.oid = target.relnamespace
            where k.contype = 'f' and sn.nspname = 'coreerp_test' and tn.nspname <> sn.nspname
            SQL);
        $this->assertSame(0, (int) $foreign->total);
    }

    public function test_control_command_creates_its_custom_schema_before_applying_privileges(): void
    {
        $capability = $this->maintenance->selectOne('select rolsuper or rolcreaterole as allowed from pg_roles where rolname = current_user');
        if (! $capability->allowed) {
            $this->markTestSkipped('Fixture migration memerlukan role test yang dapat dibuat dan dibuang.');
        }
        $role = 'coreerp_test_role_'.strtolower((string) Str::ulid());
        $this->maintenance->getPdo()->exec('CREATE ROLE "'.$role.'" NOLOGIN');
        $this->runtimeRole = $role;
        $configuration = app(EnvironmentConnection::class)->configuration('control_schema_target');
        $configuration['search_path'] = 'control_custom';
        $business = (string) config('database.default');
        config([
            'coreerp.database_mode' => 'split',
            'coreerp.control_connection' => 'control_schema_runtime',
            'coreerp.business_connection' => $business,
            'coreerp.control_migration_connection' => 'control_schema_migration',
            'database.connections.control_schema_migration' => $configuration,
            'database.connections.control_schema_runtime' => array_replace($configuration, ['username' => $role]),
        ]);
        $this->artisan('control:migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame($business, config('database.default'));
        $connection = DB::connection('control_schema_migration');
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('tenant_creation_requests'));
        $this->assertSame(2, $connection->table(ControlMigrate::HISTORY)->count());
        $this->assertTrue($connection->selectOne("select has_schema_privilege(?, 'control_custom', 'USAGE') as allowed", [$role])->allowed);
    }

    /** @param literal-string $sql */
    #[DataProvider('nonemptySchemas')]
    public function test_baseline_refuses_existing_objects_before_creating_any_control_table(string $sql): void
    {
        $this->target->statement($sql);
        $paths = require database_path('control-plane/manifest.php');
        $migrator = $this->migrator();
        try {
            $migrator->usingConnection('control_schema_target', fn () => $migrator->run([$paths[0]]));
            $this->fail('A populated schema must reject bootstrap.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('schema kosong', $error->getMessage());
        }
        $this->assertFalse($this->target->getSchemaBuilder()->hasTable('apps'));
    }

    /** @return array<string, array{literal-string}> */
    public static function nonemptySchemas(): array
    {
        return [
            'table' => ['CREATE TABLE coreerp_test.fixture_guard (id integer)'],
            'routine' => ['CREATE FUNCTION coreerp_test.fixture_guard() RETURNS integer LANGUAGE SQL AS $$ SELECT 1 $$'],
            'sequence' => ['CREATE SEQUENCE coreerp_test.fixture_guard'],
            'enum' => ["CREATE TYPE coreerp_test.fixture_guard AS ENUM ('fixture')"],
            'composite' => ['CREATE TYPE coreerp_test.fixture_guard AS (value integer)'],
        ];
    }

    private function migrator(): Migrator
    {
        $repository = new DatabaseMigrationRepository(app('db'), ControlMigrate::HISTORY);
        $repository->setSource('control_schema_target');
        $repository->createRepository();
        $migrator = new Migrator($repository, app('db'), new Filesystem);

        return $migrator;
    }
}
