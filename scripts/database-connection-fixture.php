<?php

declare(strict_types=1);

use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Support\ActiveEnvironment;
use App\Platform\Environment\Support\EnvironmentConnection;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Support\ModuleManifest;
use App\Platform\Modules\Support\ModuleMigrator;
use App\Platform\Modules\Support\ModuleSeeder;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

function refuseFixture(): never
{
    fwrite(STDERR, "CPDB02 fixture preflight refused.\n");
    exit(2);
}

function fixtureAssert(bool $condition, string $name): void
{
    $GLOBALS['lane']['checks'][$name] = $condition;
    if (! $condition) {
        throw new LogicException('Fixture assertion failed.');
    }
}

function fixtureLane(Closure $callback): array
{
    $GLOBALS['lane'] = ['status' => 'passed', 'checks' => [], 'identities' => [], 'error' => null];
    try {
        $callback();
    } catch (UnderflowException) {
        $GLOBALS['lane']['status'] = 'unknown';
        $GLOBALS['lane']['error'] = 'schema_not_ready';
    } catch (Throwable $failure) {
        $GLOBALS['lane']['status'] = 'failed';
        $GLOBALS['lane']['error'] = $failure instanceof LogicException ? 'assertion_failed' : 'probe_exception';
    }

    return $GLOBALS['lane'];
}

function fixtureIdentity(Connection|PDO $connection, string $database, string $role): array
{
    $pdo = $connection instanceof PDO ? $connection : $connection->getPdo();
    $row = $pdo->query('SELECT current_database() AS database, current_user AS role')->fetch(PDO::FETCH_ASSOC);
    fixtureAssert($row === ['database' => $database, 'role' => $role], 'database_role_match');
    $GLOBALS['lane']['identities'][] = $row;

    return $row;
}

function fixturePdo(string $database, string $role): PDO
{
    if (! in_array($database, ['core_control_plane', 'core_erp', 'core_erp_cpdb02_test_environment_a', 'core_erp_cpdb02_test_environment_b', 'postgres'], true)
        || ! array_key_exists($role, $GLOBALS['profile']['roles'])) {
        refuseFixture();
    }

    return new PDO('pgsql:host=database;port=5432;dbname='.$database.';sslmode=disable', $role, $GLOBALS['profile']['roles'][$role], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function fixtureEnvironment(string $label): Environment
{
    if (! in_array($label, ['a', 'b'], true)) {
        refuseFixture();
    }

    return (new Environment)->forceFill([
        'id' => 'cpdb02_environment_'.$label,
        'database_name' => 'core_erp_cpdb02_test_environment_'.$label,
    ]);
}

function fixtureDenied(Closure $callback): bool
{
    try {
        $callback();
    } catch (PDOException $failure) {
        return $failure->getCode() === '42501';
    }

    return false;
}

function fixtureConnectDenied(string $database, string $role): bool
{
    $inspector = fixturePdo('postgres', 'cpdb02_provisioner');
    $statement = $inspector->prepare("SELECT has_database_privilege(?, ?, 'CONNECT')");
    $statement->execute([$role, $database]);
    if ($statement->fetchColumn() !== false) {
        return false;
    }
    try {
        fixturePdo($database, $role);
    } catch (PDOException $failure) {
        return str_contains($failure->getMessage(), 'permission denied for database');
    }

    return false;
}

function fixtureRequireTables(string $connection, array $tables): void
{
    foreach ($tables as $table) {
        if (! DB::connection($connection)->getSchemaBuilder()->hasTable($table)) {
            throw new UnderflowException;
        }
    }
}

function fixturePreflight(): array
{
    $connections = app(EnvironmentConnection::class);
    $lanes = [];
    foreach ([
        'control' => [$connections->controlPlane(), 'core_control_plane', 'cpdb02_control_runtime'],
        'business' => [$connections->business(), 'core_erp', 'cpdb02_business_runtime'],
        'control_migration' => [$connections->controlMigration(), 'core_control_plane', 'cpdb02_control_migrator'],
        'business_migration' => [$connections->migrationFor(), 'core_erp', 'cpdb02_business_migrator'],
        'maintenance' => [$connections->maintenance(), 'postgres', 'cpdb02_provisioner'],
    ] as $label => [$alias, $database, $role]) {
        $lanes[$label] = fixtureLane(fn () => fixtureIdentity(DB::connection($alias), $database, $role));
    }
    $lanes['framework_pins'] = fixtureLane(function (): void {
        foreach (['session.connection', 'auth.passwords.users.connection', 'cache.stores.database.connection', 'cache.stores.database.lock_connection', 'queue.connections.database.connection', 'queue.batching.database', 'queue.failed.database'] as $index => $key) {
            fixtureAssert(config($key) === 'control', 'framework_connection_'.$index);
        }
        fixtureAssert(config('cache.scheduler_store') === 'database', 'scheduler_database_store');
    });

    return $lanes;
}

function fixtureRouting(): array
{
    $connections = app(EnvironmentConnection::class);
    $lanes = [];
    foreach (['control' => ['core_control_plane', 'cpdb02_control_runtime'], 'pooled' => ['core_erp', 'cpdb02_business_runtime'], 'environment_a' => ['core_erp_cpdb02_test_environment_a', 'cpdb02_business_runtime'], 'environment_b' => ['core_erp_cpdb02_test_environment_b', 'cpdb02_business_runtime']] as $lane => [$database, $role]) {
        $lanes[$lane] = fixtureLane(function () use ($connections, $lane, $database, $role): void {
            if ($lane === 'control') {
                fixtureIdentity(DB::connection($connections->controlPlane()), $database, $role);
            } else {
                $environment = $lane === 'pooled' ? null : fixtureEnvironment(substr($lane, -1));
                $connections->runWithin($environment, function () use ($database, $role): void {
                    fixtureIdentity(DB::connection(), $database, $role);
                });
                fixtureAssert(config('database.default') === 'business', 'default_restored');
            }
        });
    }
    $lanes['nested_transaction'] = fixtureLane(function () use ($connections): void {
        $connections->runWithin(fixtureEnvironment('a'), function () use ($connections): void {
            $ancestor = DB::connection();
            $ancestor->beginTransaction();
            try {
                $pdo = $ancestor->getPdo();
                $connections->runWithin(fixtureEnvironment('b'), function () use ($connections, $pdo): void {
                    fixtureIdentity(DB::connection(), 'core_erp_cpdb02_test_environment_b', 'cpdb02_business_runtime');
                    $connections->runWithin(fixtureEnvironment('a'), function () use ($pdo): void {
                        fixtureIdentity(DB::connection(), 'core_erp_cpdb02_test_environment_a', 'cpdb02_business_runtime');
                        fixtureAssert(DB::connection()->getPdo() === $pdo && DB::connection()->transactionLevel() === 1 && $pdo->inTransaction(), 'ancestor_pdo_transaction_preserved');
                    });
                    fixtureAssert(config('database.default') === 'environment_cpdb02_environment_b', 'middle_context_restored');
                });
                fixtureAssert(DB::connection()->getPdo() === $pdo && $pdo->inTransaction(), 'ancestor_survives_inner_cleanup');
            } finally {
                $ancestor->rollBack();
            }
        });
        fixtureAssert(config('database.default') === 'business' && ! app()->bound(ActiveEnvironment::KEY), 'outer_context_restored');
    });
    $lanes['exception_cleanup'] = fixtureLane(function () use ($connections): void {
        try {
            $connections->runWithin(fixtureEnvironment('a'), function () use ($connections): void {
                $connections->runWithin(fixtureEnvironment('b'), function (): void {
                    DB::connection()->getPdo();
                    throw new DomainException;
                });
            });
        } catch (DomainException) {
            fixtureAssert(true, 'exception_observed');
        }
        fixtureAssert(config('database.default') === 'business' && ! app()->bound(ActiveEnvironment::KEY), 'exception_context_restored');
        fixtureAssert(! isset(DB::getConnections()['environment_cpdb02_environment_b']), 'owned_connection_purged');
        fixtureIdentity(DB::connection(), 'core_erp', 'cpdb02_business_runtime');
    });
    $lanes['migration_guard'] = fixtureLane(function () use ($connections): void {
        config(['database.connections.cpdb02_masked_control' => config('database.connections.control')]);
        $rejected = false;
        try {
            $connections->migrationFor('cpdb02_masked_control');
        } catch (RuntimeException) {
            $rejected = true;
        }
        fixtureAssert($rejected, 'physical_control_alias_rejected');
        fixtureAssert($connections->migrationFor() === 'business_migration', 'business_migrator_selected');
        fixtureIdentity(DB::connection($connections->controlMigration()), 'core_control_plane', 'cpdb02_control_migrator');
        fixtureIdentity(DB::connection($connections->maintenance()), 'postgres', 'cpdb02_provisioner');
    });

    return $lanes;
}

function fixtureMigrateEnvironments(): array
{
    $lanes = [];
    $connections = app(EnvironmentConnection::class);
    foreach (['a', 'b'] as $label) {
        $lanes['environment_'.$label] = fixtureLane(function () use ($connections, $label): void {
            fixtureRequireTables('business', ['migrations']);
            $runtime = $connections->for(fixtureEnvironment($label));
            fixtureAssert(Artisan::call('migrate', ['--database' => $runtime, '--force' => true]) === 0, 'normal_migration_succeeded');
            $database = DB::connection($runtime);
            fixtureIdentity($database, 'core_erp_cpdb02_test_environment_'.$label, 'cpdb02_business_runtime');
            fixtureAssert($database->table('migrations')->count() === DB::connection('business')->table('migrations')->count(), 'business_history_complete');
            fixtureAssert(! $database->getSchemaBuilder()->hasTable('control_plane_migrations'), 'control_history_absent');
            fixtureAssert(DB::getDefaultConnection() === 'business', 'pooled_default_restored');
            fixtureIdentity(DB::connection(), 'core_erp', 'cpdb02_business_runtime');
        });
    }

    return $lanes;
}

function fixtureControlUpgrade(): array
{
    return ['runtime_restoration' => fixtureLane(function (): void {
        fixtureRequireTables('control', ['control_plane_migrations']);
        fixtureRequireTables('business', ['migrations']);
        $controlHistory = DB::connection('control')->table('control_plane_migrations')->count();
        $businessHistory = DB::connection('business')->table('migrations')->count();
        fixtureAssert(Artisan::call('control:migrate', ['--force' => true]) === 0, 'normal_control_upgrade_succeeded');
        fixtureAssert(DB::getDefaultConnection() === 'business', 'runtime_default_restored');
        fixtureIdentity(DB::connection(), 'core_erp', 'cpdb02_business_runtime');
        fixtureAssert(DB::connection('control')->table('control_plane_migrations')->count() === $controlHistory && $controlHistory > 0, 'control_history_unchanged');
        fixtureAssert(DB::connection('business')->table('migrations')->count() === $businessHistory && $businessHistory > 0, 'business_history_unchanged');
        fixtureAssert(! DB::connection('control')->getSchemaBuilder()->hasTable('migrations'), 'control_business_history_absent');
    })];
}

function fixtureHistoryProtection(PDO $runtime, string $history): void
{
    fixtureAssert((int) $runtime->query('SELECT count(*) FROM '.$history)->fetchColumn() > 0, 'history_readable');
    foreach (['INSERT', 'UPDATE', 'DELETE'] as $action) {
        $statement = $runtime->prepare('SELECT has_table_privilege(current_user, ?, ?)');
        $statement->execute([$history, $action]);
        fixtureAssert($statement->fetchColumn() === false, 'history_'.strtolower($action).'_grant_absent');
    }
    fixtureAssert(fixtureDenied(fn () => $runtime->exec('UPDATE '.$history.' SET batch=batch WHERE false')), 'history_update_denied');
    fixtureAssert(fixtureDenied(fn () => $runtime->exec('DELETE FROM '.$history.' WHERE false')), 'history_delete_denied');
    fixtureAssert(fixtureDenied(fn () => $runtime->exec("INSERT INTO $history (migration,batch) VALUES ('cpdb02_forbidden',0)")), 'history_insert_denied');
    $sequence = $runtime->query("SELECT pg_get_serial_sequence('$history','id')")->fetchColumn();
    fixtureAssert(is_string($sequence) && fixtureDenied(fn () => $runtime->query("SELECT nextval('$sequence')")), 'history_nextval_denied');
    fixtureAssert(is_string($sequence) && fixtureDenied(fn () => $runtime->query("SELECT setval('$sequence',1000)")), 'history_setval_denied');
}

function fixtureModule(string $kind): array
{
    $suffix = substr($GLOBALS['runId'], 0, 16);
    $folderName = 'probe'.$suffix.$kind;
    $folder = '/tmp/cpdb02-module-'.$GLOBALS['runId'].'/'.$folderName;
    $table = 'cpdb02_module_'.$suffix.'_'.$kind;
    $migrationName = '2026_11_03_000001_cpdb02_'.$suffix.'_'.$kind;
    if (! mkdir($folder.'/database/migrations', 0700, true) || ! mkdir($folder.'/database/seeders', 0700, true)) {
        refuseFixture();
    }
    $migration = <<<'PHP'
<?php

return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement("CREATE TABLE __TABLE__ (id bigserial PRIMARY KEY,tenant_id char(26) NOT NULL,value text NOT NULL,database_name text DEFAULT current_database(),role_name text DEFAULT current_user)");
        \Illuminate\Support\Facades\DB::statement("INSERT INTO __TABLE__ (tenant_id,value) VALUES ('00000000000000000000000000','migration')");
        __FAILURE__
    }
};
PHP;
    file_put_contents($folder.'/database/migrations/'.$migrationName.'.php', str_replace(['__TABLE__', '__FAILURE__'], [$table, $kind === 'failure' ? "throw new \\RuntimeException('Synthetic CPDB02 module failure.');" : ''], $migration));
    $seeder = <<<'PHP'
<?php

namespace Modules\CpdbFixture\__CLASS__\Database\Seeders;

final class ProbeSeeder extends \Illuminate\Database\Seeder
{
    public function run(): void
    {
        \Illuminate\Support\Facades\DB::table('__TABLE__')->insert([
            'tenant_id' => app(\App\Platform\Modules\Support\TenantScope::KEY),
            'value' => 'seed',
        ]);
    }
}
PHP;
    $seederPath = $folder.'/database/seeders/ProbeSeeder.php';
    file_put_contents($seederPath, str_replace(['__CLASS__', '__TABLE__'], [ucfirst($folderName), $table], $seeder));
    require $seederPath;

    return [new ModuleManifest('cpdb02-'.$suffix.'-'.$kind, 'CPDB02 synthetic fixture', '0.0.1', 'cpdb-fixture', 'internal-fixture', 'cpdb02_', $folder), $table, $migrationName];
}

function fixtureModules(): array
{
    $connections = app(EnvironmentConnection::class);
    $lanes = [];
    foreach (['success', 'failure'] as $kind) {
        $lanes[$kind] = fixtureLane(function () use ($connections, $kind): void {
            [$module, $table, $migrationName] = fixtureModule($kind);
            $connections->runWithin(fixtureEnvironment('a'), function () use ($connections, $kind, $module, $table, $migrationName): void {
                $runtime = DB::getDefaultConnection();
                $database = DB::connection();
                $migrator = app(ModuleMigrator::class);
                $coreHistory = $database->table('migrations')->count();
                $controlHistory = DB::connection('control')->table('control_plane_migrations')->count();
                if ($kind === 'failure') {
                    $observed = false;
                    try {
                        $migrator->migrate($module, $runtime);
                    } catch (RuntimeException $failure) {
                        $observed = $failure->getMessage() === 'Synthetic CPDB02 module failure.';
                    }
                    fixtureAssert($observed, 'expected_failure_observed');
                    fixtureAssert(DB::getDefaultConnection() === $runtime, 'runtime_restored');
                    fixtureIdentity(DB::connection(), 'core_erp_cpdb02_test_environment_a', 'cpdb02_business_runtime');
                    fixtureAssert(! $database->getSchemaBuilder()->hasTable($table), 'ddl_rolled_back');
                    fixtureAssert($database->table('core_module_migrations')->where('module_id', $module->id)->count() === 0, 'failure_not_recorded');
                } else {
                    fixtureAssert($migrator->migrate($module, $runtime) === [$migrationName], 'migration_recorded');
                    fixtureAssert(DB::getDefaultConnection() === $runtime, 'runtime_restored');
                    fixtureIdentity(DB::connection(), 'core_erp_cpdb02_test_environment_a', 'cpdb02_business_runtime');
                    fixtureAssert($database->table($table)->where('value', 'migration')->value('role_name') === 'cpdb02_business_migrator', 'migration_used_owner');
                    $tenant = substr($GLOBALS['runId'], 0, 26);
                    $database->beginTransaction();
                    try {
                        $database->table('core_module_installations')->insert(['tenant_id' => $tenant, 'module_id' => $module->id, 'version' => '0.0.1', 'status' => 'installed', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                        fixtureAssert(app(ModuleSeeder::class)->run($module, $tenant), 'seed_ran');
                        $row = $database->table($table)->where('value', 'seed')->first();
                        fixtureAssert($row?->database_name === 'core_erp_cpdb02_test_environment_a' && $row->role_name === 'cpdb02_business_runtime', 'seed_used_runtime');
                        fixtureAssert(! app(ModuleSeeder::class)->run($module, $tenant), 'seed_idempotent');
                    } finally {
                        $database->rollBack();
                    }
                    fixtureAssert($migrator->migrate($module, $runtime) === [], 'migration_idempotent');
                    fixtureAssert($database->table('migrations')->count() === $coreHistory && ! $database->table('migrations')->where('migration', $migrationName)->exists(), 'core_history_unchanged');
                    fixtureAssert(DB::connection('control')->table('control_plane_migrations')->count() === $controlHistory, 'control_history_unchanged');
                }
                $migration = DB::connection($connections->migrationFor($runtime));
                fixtureAssert($migration->selectOne("SELECT current_setting('coreerp.change_log',true) AS value")->value !== 'off', 'change_log_restored');
            });
        });
    }
    $lanes['history'] = fixtureLane(function (): void {
        $runtime = fixturePdo('core_erp_cpdb02_test_environment_a', 'cpdb02_business_runtime');
        fixtureIdentity($runtime, 'core_erp_cpdb02_test_environment_a', 'cpdb02_business_runtime');
        fixtureHistoryProtection($runtime, 'core_module_migrations');
    });

    return $lanes;
}

function fixtureRoleProbe(string $database, string $ownerRole, string $runtimeRole, string $history): void
{
    $owner = fixturePdo($database, $ownerRole);
    $runtime = fixturePdo($database, $runtimeRole);
    fixtureIdentity($owner, $database, $ownerRole);
    fixtureIdentity($runtime, $database, $runtimeRole);
    $table = 'cpdb02_probe_'.substr($GLOBALS['runId'], 0, 16);
    $schema = 'cpdb02_schema_'.substr($GLOBALS['runId'], 0, 16);
    $sequence = $table.'_id_seq';
    try {
        $owner->exec('CREATE TABLE public.'.$table.' (id bigserial PRIMARY KEY, value text NOT NULL)');
        $owner->exec('CREATE SCHEMA '.$schema);
        $owner->exec('CREATE TABLE '.$schema.'.probe (id bigserial PRIMARY KEY, value text NOT NULL)');
        $owner->exec('CREATE FUNCTION '.$schema.'.probe_function() RETURNS integer LANGUAGE sql AS \'SELECT 1\'');
        fixtureAssert(true, 'migrator_table_schema_ddl');
        $runtime->exec("INSERT INTO public.$table (value) VALUES ('synthetic')");
        $runtime->exec("UPDATE public.$table SET value='updated'");
        fixtureAssert($runtime->query('SELECT value FROM public.'.$table)->fetchColumn() === 'updated', 'runtime_table_dml');
        $runtime->exec("INSERT INTO $schema.probe (value) VALUES ('future')");
        fixtureAssert((int) $runtime->query('SELECT count(*) FROM '.$schema.'.probe')->fetchColumn() === 1, 'future_schema_table_defaults');
        fixtureAssert((int) $runtime->query('SELECT '.$schema.'.probe_function()')->fetchColumn() === 1, 'future_function_defaults');
        fixtureAssert($owner->query("SELECT has_schema_privilege('cpdb02_outsider','$schema','USAGE')")->fetchColumn() === false, 'public_schema_access_absent');
        fixtureAssert($owner->query("SELECT has_function_privilege('cpdb02_outsider','$schema.probe_function()','EXECUTE')")->fetchColumn() === false, 'public_function_access_absent');
        fixtureAssert((int) $runtime->query("SELECT nextval('public.$sequence')")->fetchColumn() > 0, 'runtime_nextval');
        fixtureAssert(fixtureDenied(fn () => $runtime->query("SELECT setval('public.$sequence', 1000)")), 'runtime_setval_denied');
        fixtureAssert(fixtureDenied(fn () => $runtime->exec('CREATE TABLE public.'.$table.'_denied (id integer)')), 'runtime_create_table_denied');
        fixtureAssert(fixtureDenied(fn () => $runtime->exec('CREATE SCHEMA '.$schema.'_denied')), 'runtime_create_schema_denied');
        fixtureAssert(fixtureDenied(fn () => $runtime->exec('ALTER TABLE public.'.$table.' ADD COLUMN denied integer')), 'runtime_alter_table_denied');
        fixtureAssert(fixtureDenied(fn () => $runtime->exec('SET ROLE '.$ownerRole)), 'runtime_set_role_denied');
        fixtureHistoryProtection($runtime, $history);
        $public = $owner->prepare("SELECT count(*) FROM pg_class c CROSS JOIN LATERAL aclexplode(COALESCE(c.relacl, '{}'::aclitem[])) a WHERE c.relnamespace IN ('public'::regnamespace,?::regnamespace) AND c.relname IN (?,?,'probe','probe_id_seq') AND a.grantee=0");
        $public->execute([$schema, $table, $sequence]);
        fixtureAssert((int) $public->fetchColumn() === 0, 'public_table_sequence_access_absent');
        $runtime->exec('DELETE FROM public.'.$table);
        fixtureAssert((int) $runtime->query('SELECT count(*) FROM public.'.$table)->fetchColumn() === 0, 'runtime_delete');
    } finally {
        $owner->exec('DROP TABLE IF EXISTS '.$schema.'.probe');
        $owner->exec('DROP FUNCTION IF EXISTS '.$schema.'.probe_function()');
        $owner->exec('DROP SCHEMA IF EXISTS '.$schema);
        $owner->exec('DROP TABLE IF EXISTS public.'.$table);
    }
}

function fixturePrivileges(): array
{
    $lanes = [];
    foreach ([
        'control' => ['core_control_plane', 'cpdb02_control_migrator', 'cpdb02_control_runtime', 'control_plane_migrations'],
        'business' => ['core_erp', 'cpdb02_business_migrator', 'cpdb02_business_runtime', 'migrations'],
        'environment_a' => ['core_erp_cpdb02_test_environment_a', 'cpdb02_business_migrator', 'cpdb02_business_runtime', 'migrations'],
        'environment_b' => ['core_erp_cpdb02_test_environment_b', 'cpdb02_business_migrator', 'cpdb02_business_runtime', 'migrations'],
    ] as $label => $arguments) {
        $lanes[$label] = fixtureLane(fn () => fixtureRoleProbe(...$arguments));
    }
    $lanes['cross_connect'] = fixtureLane(function (): void {
        foreach (['cpdb02_control_runtime', 'cpdb02_control_migrator'] as $role) {
            fixtureAssert(fixtureConnectDenied('core_erp', $role), $role.'_business_connect_denied');
            foreach (['a', 'b'] as $label) {
                fixtureAssert(fixtureConnectDenied('core_erp_cpdb02_test_environment_'.$label, $role), $role.'_environment_'.$label.'_connect_denied');
            }
        }
        foreach (['cpdb02_business_runtime', 'cpdb02_business_migrator'] as $role) {
            fixtureAssert(fixtureConnectDenied('core_control_plane', $role), $role.'_control_connect_denied');
        }
        foreach (['core_control_plane', 'core_erp', 'core_erp_cpdb02_test_environment_a', 'core_erp_cpdb02_test_environment_b'] as $index => $database) {
            fixtureAssert(fixtureConnectDenied($database, 'cpdb02_outsider'), 'outsider_connect_denied_'.$index);
        }
    });
    $lanes['role_authority'] = fixtureLane(function (): void {
        $pdo = fixturePdo('postgres', 'cpdb02_provisioner');
        fixtureIdentity($pdo, 'postgres', 'cpdb02_provisioner');
        $rows = $pdo->query("SELECT rolname, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls FROM pg_roles WHERE rolname IN ('cpdb02_control_runtime','cpdb02_control_migrator','cpdb02_business_runtime','cpdb02_business_migrator','cpdb02_provisioner','cpdb02_outsider') ORDER BY rolname")->fetchAll(PDO::FETCH_ASSOC);
        fixtureAssert(count($rows) === 6, 'all_synthetic_roles_present');
        fixtureAssert(array_all($rows, fn (array $role): bool => ! $role['rolsuper'] && ! $role['rolcreaterole'] && ! $role['rolreplication'] && ! $role['rolbypassrls'] && $role['rolcreatedb'] === ($role['rolname'] === 'cpdb02_provisioner')), 'only_provisioner_createdb');
        $memberships = $pdo->query("SELECT count(*) FROM pg_auth_members m JOIN pg_roles r ON r.oid=m.member WHERE r.rolname IN ('cpdb02_control_runtime','cpdb02_control_migrator','cpdb02_business_runtime','cpdb02_business_migrator','cpdb02_provisioner','cpdb02_outsider')")->fetchColumn();
        fixtureAssert((int) $memberships === 0, 'no_inherited_role_memberships');
        fixtureAssert(fixtureConnectDenied('core_control_plane', 'cpdb02_provisioner'), 'provisioner_control_connect_denied');
        fixtureAssert(fixtureConnectDenied('core_erp', 'cpdb02_provisioner'), 'provisioner_pooled_connect_denied');
    });
    $lanes['control_routine'] = fixtureLane(function (): void {
        $owner = fixturePdo('core_control_plane', 'cpdb02_control_migrator');
        $runtime = fixturePdo('core_control_plane', 'cpdb02_control_runtime');
        fixtureIdentity($runtime, 'core_control_plane', 'cpdb02_control_runtime');
        $table = 'cpdb02_routine_'.substr($GLOBALS['runId'], 0, 16);
        $owner->exec('CREATE TABLE '.$table.' (id text PRIMARY KEY, tenant_id char(26), value text, created_by_user_id bigint, updated_by_user_id bigint, version integer NOT NULL DEFAULT 1)');
        try {
            $owner->exec('CREATE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()');
            $owner->exec('CREATE TRIGGER bump_row_version BEFORE UPDATE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()');
            $owner->exec('CREATE TRIGGER log_change AFTER INSERT OR UPDATE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()');
            $runtime->beginTransaction();
            try {
                $statement = $runtime->prepare("INSERT INTO change_log_setup_tables (id, table_name, log_insertion, log_modification) VALUES (?, ?, 'all', 'all')");
                $statement->execute([substr($GLOBALS['runId'], 0, 26), $table]);
                $runtime->exec("SELECT set_config('coreerp.user_id','91337',true)");
                $runtime->exec("INSERT INTO $table (id,tenant_id,value) VALUES ('synthetic','00000000000000000000000000','first')");
                $runtime->exec("UPDATE $table SET value='second' WHERE id='synthetic'");
                $row = $runtime->query('SELECT created_by_user_id,updated_by_user_id,version FROM '.$table)->fetch(PDO::FETCH_ASSOC);
                fixtureAssert((int) $row['created_by_user_id'] === 91337 && (int) $row['updated_by_user_id'] === 91337 && (int) $row['version'] === 2, 'runtime_actor_version_trigger');
                $statement = $runtime->prepare('SELECT count(*) FROM change_log_entries WHERE table_name=? AND created_by_user_id=91337');
                $statement->execute([$table]);
                fixtureAssert((int) $statement->fetchColumn() >= 2, 'runtime_change_log_trigger');
                fixtureAssert((int) $runtime->query("SELECT count(*) FROM pg_class WHERE relnamespace='public'::regnamespace AND relname IN ('roles','duties','privileges','role_duties','duty_privileges')")->fetchColumn() === 0, 'erp_security_tables_absent');
            } finally {
                $runtime->rollBack();
            }
        } finally {
            $owner->exec('DROP TABLE '.$table);
        }
    });

    return $lanes;
}

function fixtureManagers(): array
{
    return [
        'session' => app('session')->driver(),
        'password' => app('auth.password')->broker()->getRepository(),
        'cache' => app('cache')->store('database'),
        'queue' => app('queue')->connection('database'),
        'failed' => app('queue.failer'),
        'batch' => app(BatchRepository::class),
        'schedule' => app(Schedule::class),
    ];
}

function fixtureStatePath(string $mode): string
{
    return '/tmp/cpdb02-'.$GLOBALS['runId'].'-'.$mode.'.json';
}

function fixtureSaveState(string $mode, array $state): void
{
    $path = fixtureStatePath($mode);
    if (is_link($path)) {
        refuseFixture();
    }
    $previousMask = umask(0077);
    try {
        $handle = fopen($path, 'x');
    } finally {
        umask($previousMask);
    }
    if ($handle === false) {
        refuseFixture();
    }
    chmod($path, 0600);
    fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
    fclose($handle);
}

function fixtureState(string $mode): array
{
    $path = fixtureStatePath($mode);
    if (! is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) {
        throw new UnderflowException;
    }

    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function fixtureStoredOnlyOnControl(string $table, string $column, string $value, int $expected = 1): void
{
    fixtureAssert(DB::connection('control')->table($table)->where($column, $value)->count() === $expected, $table.'_control_count');
    $connections = app(EnvironmentConnection::class);
    foreach (['business', $connections->for(fixtureEnvironment('a')), $connections->for(fixtureEnvironment('b'))] as $index => $connection) {
        $database = DB::connection($connection);
        fixtureAssert(! $database->getSchemaBuilder()->hasTable($table) || $database->table($table)->where($column, $value)->count() === 0, $table.'_business_absent_'.$index);
    }
}

function fixtureFramework(string $phase, string $mode): array
{
    fixtureRequireTables('control', ['sessions', 'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches']);
    $connections = app(EnvironmentConnection::class);
    $state = $phase === 'produce' ? [
        'process_id' => getmypid(), 'prefix' => 'cpdb02-'.$GLOBALS['runId'].'-'.$mode,
        'session_id' => bin2hex(random_bytes(20)), 'value' => bin2hex(random_bytes(32)),
        'minute' => now()->toIso8601String(),
    ] : fixtureState($mode);
    $managers = $mode === 'warm' ? fixtureManagers() : null;
    $connections->useForRequest(fixtureEnvironment($phase === 'produce' ? 'a' : 'b'));
    $managers ??= fixtureManagers();
    $event = $managers['schedule']->command('list')->name($state['prefix'])->createMutexNameUsing($state['prefix'])->withoutOverlapping()->onOneServer();
    $eventMutex = new ReflectionProperty($managers['schedule'], 'eventMutex');
    $eventMutex = $eventMutex->getValue($managers['schedule']);
    $scheduleMutex = new ReflectionProperty($managers['schedule'], 'schedulingMutex');
    $scheduleMutex = $scheduleMutex->getValue($managers['schedule']);
    $user = (new User)->forceFill(['email' => $state['prefix'].'@example.invalid']);
    $lanes = [];
    foreach (['session', 'password_reset', 'cache', 'lock', 'scheduler', 'queue_success', 'queue_failure', 'batch'] as $lane) {
        $lanes[$lane] = fixtureLane(function () use (&$state, $phase, $lane, $managers, $event, $eventMutex, $scheduleMutex, $user): void {
            fixtureAssert($phase === 'produce' || $state['process_id'] !== getmypid(), 'fresh_process');
            $prefix = $state['prefix'];
            if ($lane === 'session') {
                $session = $managers['session'];
                $session->setId($state['session_id']);
                $session->start();
                if ($phase === 'produce') {
                    $session->put('cpdb02_value', $state['value']);
                    $session->save();
                }
                fixtureAssert($session->get('cpdb02_value') === $state['value'], 'session_value');
                fixtureStoredOnlyOnControl('sessions', 'id', $state['session_id']);
            } elseif ($lane === 'password_reset') {
                if ($phase === 'produce') {
                    $state['reset_token'] = $managers['password']->create($user);
                }
                fixtureAssert($managers['password']->exists($user, $state['reset_token']), 'reset_token_valid');
                fixtureStoredOnlyOnControl('password_reset_tokens', 'email', $user->email);
            } elseif ($lane === 'cache') {
                if ($phase === 'produce') {
                    $managers['cache']->put($prefix.'-value', $state['value'], 600);
                }
                fixtureAssert($managers['cache']->get($prefix.'-value') === $state['value'], 'cache_value');
                fixtureStoredOnlyOnControl('cache', 'key', $managers['cache']->getStore()->getPrefix().$prefix.'-value');
            } elseif ($lane === 'lock') {
                $lock = $managers['cache']->lock($prefix.'-lock', 600);
                if ($phase === 'produce') {
                    fixtureAssert($lock->get(), 'lock_acquired');
                    $state['lock_owner'] = $lock->owner();
                } else {
                    fixtureAssert(! $lock->get(), 'lock_contended_after_restart');
                    fixtureAssert($managers['cache']->restoreLock($prefix.'-lock', $state['lock_owner'])->release(), 'original_lock_owner_released');
                    fixtureAssert($lock->get(), 'lock_reacquired');
                    $lock->release();
                }
                fixtureStoredOnlyOnControl('cache_locks', 'key', $managers['cache']->getStore()->getPrefix().$prefix.'-lock', $phase === 'produce' ? 1 : 0);
            } elseif ($lane === 'scheduler') {
                if ($phase === 'produce') {
                    fixtureAssert($eventMutex->create($event), 'event_mutex_created');
                    fixtureAssert($scheduleMutex->create($event, Carbon::parse($state['minute'])), 'scheduler_mutex_created');
                } else {
                    fixtureAssert($eventMutex->exists($event) && ! $eventMutex->create($event), 'event_mutex_survived_restart');
                    fixtureAssert($scheduleMutex->exists($event, Carbon::parse($state['minute'])) && ! $scheduleMutex->create($event, Carbon::parse($state['minute'])), 'scheduler_mutex_survived_restart');
                }
                fixtureStoredOnlyOnControl('cache_locks', 'key', $managers['cache']->getStore()->getPrefix().$event->mutexName());
            } else {
                $kind = match ($lane) {
                    'queue_success' => 'success', 'queue_failure' => 'failure', default => 'batch',
                };
                $queue = $prefix.'-'.$kind;
                if ($phase === 'produce') {
                    $job = new Cpdb02FixtureJob($prefix, fixtureEnvironment($kind === 'failure' ? 'b' : 'a')->id, $kind);
                    if ($kind === 'batch') {
                        $state['batch_id'] = Bus::batch([$job])->onConnection('database')->onQueue($queue)->name($prefix)->dispatch()->id;
                        fixtureAssert($managers['batch']->find($state['batch_id'])?->pendingJobs === 1, 'batch_pending');
                        fixtureStoredOnlyOnControl('job_batches', 'id', $state['batch_id']);
                    } else {
                        $managers['queue']->push($job, '', $queue);
                    }
                    fixtureStoredOnlyOnControl('jobs', 'queue', $queue);
                } else {
                    fixtureAssert(Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--once' => true, '--tries' => 1, '--timeout' => 0, '--sleep' => 0]) === 0, 'real_worker_completed');
                    $receipt = $managers['cache']->get($prefix.'-'.$kind.'-receipt');
                    fixtureAssert(is_array($receipt) && $receipt['database'] === 'core_erp_cpdb02_test_environment_'.($kind === 'failure' ? 'b' : 'a') && $receipt['role'] === 'cpdb02_business_runtime', 'worker_business_context');
                    $GLOBALS['lane']['identities'][] = $receipt;
                    fixtureAssert(config('database.default') === 'business' && ! app()->bound(ActiveEnvironment::KEY), 'worker_context_cleaned');
                    fixtureStoredOnlyOnControl('jobs', 'queue', $queue, 0);
                    fixtureStoredOnlyOnControl('failed_jobs', 'queue', $queue, $kind === 'failure' ? 1 : 0);
                    if ($kind === 'batch') {
                        $batch = $managers['batch']->find($state['batch_id']);
                        fixtureAssert($batch?->finished() && $batch->pendingJobs === 0 && $batch->failedJobs === 0, 'batch_finished');
                        fixtureStoredOnlyOnControl('job_batches', 'id', $state['batch_id']);
                    }
                }
            }
        });
    }
    $connections->reset();
    if ($phase === 'produce') {
        fixtureSaveState($mode, $state);
    }

    return $lanes;
}

try {
    if (($argv[1] ?? null) !== '/fixture/profiles.json' || ! is_file($argv[1]) || is_link($argv[1])) {
        refuseFixture();
    }
    $input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($input) || array_keys($input) !== ['run_id', 'profile_sha256', 'source_hashes']
        || preg_match('/^[a-f0-9]{32}$/D', $input['run_id'] ?? '') !== 1
        || ! hash_equals(hash_file('sha256', $argv[1]), $input['profile_sha256'] ?? '')
        || ! in_array($argv[2] ?? null, ['preflight', 'routing', 'control_upgrade', 'migrate_environments', 'modules', 'privileges', 'produce_warm', 'consume_warm', 'produce_cold', 'consume_cold'], true)) {
        refuseFixture();
    }
    $runId = $input['run_id'];
    $profile = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR);
    $expected = [
        'DB_CONTROL' => ['core_control_plane', 'cpdb02_control_runtime'],
        'DB_CONTROL_MIGRATION' => ['core_control_plane', 'cpdb02_control_migrator'],
        'DB_BUSINESS' => ['core_erp', 'cpdb02_business_runtime'],
        'DB_BUSINESS_MIGRATION' => ['core_erp', 'cpdb02_business_migrator'],
        'DB_PROVISIONING' => ['postgres', 'cpdb02_provisioner'],
    ];
    foreach ($expected as $prefix => [$database, $role]) {
        foreach (['_HOST' => 'database', '_PORT' => '5432', '_DATABASE' => $database, '_USERNAME' => $role, '_SCHEMA' => 'public', '_SSLMODE' => 'disable', '_PASSWORD' => $profile['roles'][$role] ?? null] as $suffix => $value) {
            if (! is_string($value) || (string) ($profile['env'][$prefix.$suffix] ?? '') !== $value) {
                refuseFixture();
            }
        }
    }
    foreach (['APP_ENV' => 'testing', 'COREERP_DATABASE_MODE' => 'split', 'DB_CONNECTION' => 'business', 'COREERP_CONTROL_CONNECTION' => 'control', 'COREERP_BUSINESS_CONNECTION' => 'business', 'COREERP_CONTROL_MIGRATION_CONNECTION' => 'control_migration', 'COREERP_BUSINESS_MIGRATION_CONNECTION' => 'business_migration', 'COREERP_MAINTENANCE_CONNECTION' => 'provisioning'] as $key => $value) {
        if (($profile['env'][$key] ?? null) !== $value) {
            refuseFixture();
        }
    }
    foreach ($profile['env'] as $key => $value) {
        if (! is_string($key) || preg_match('/^[A-Z][A-Z0-9_]+$/D', $key) !== 1 || ! is_scalar($value)
            || (str_ends_with($key, '_URL') && $key !== 'APP_URL' && $value !== '')
            || (str_ends_with($key, '_HOST') && $value !== 'database')) {
            refuseFixture();
        }
        putenv($key.'='.$value);
        $_ENV[$key] = (string) $value;
        $_SERVER[$key] = (string) $value;
    }
    $core = '/repo/apps/core';
    if (is_file($core.'/bootstrap/cache/config.php') || getenv('LARAVEL_PARALLEL_TESTING') || getenv('TEST_TOKEN')) {
        refuseFixture();
    }
    $sourceHashes = [];
    foreach ([
        'app/Platform/Environment/Support/EnvironmentConnection.php', 'app/Platform/Environment/Support/ConnectionFrame.php',
        'app/Platform/Environment/Listeners/ResetDatabaseConnections.php', 'app/Platform/Database/Support/DatabasePrivileges.php',
        'app/Platform/Modules/Support/CoreMigrateCommand.php', 'app/Platform/Modules/Support/ModuleMigrator.php',
        'app/Platform/Modules/Support/ModuleManifest.php', 'app/Platform/Modules/Support/ModuleMigrationRepository.php', 'app/Platform/Modules/Support/ModuleSeeder.php',
        'app/Platform/ControlPlane/Console/ControlMigrate.php', 'app/Platform/Reporting/Jobs/RunReportExport.php',
        'config/database.php', 'config/coreerp.php', 'config/session.php', 'config/auth.php', 'config/cache.php', 'config/queue.php', 'routes/console.php',
    ] as $source) {
        $sourceHashes[$source] = hash_file('sha256', $core.'/'.$source);
    }
    if ($sourceHashes !== $input['source_hashes']) {
        refuseFixture();
    }
    chdir($core);
    require $core.'/vendor/autoload.php';
    $app = require $core.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    foreach (['control' => 'DB_CONTROL', 'control_migration' => 'DB_CONTROL_MIGRATION', 'business' => 'DB_BUSINESS', 'business_migration' => 'DB_BUSINESS_MIGRATION', 'provisioning' => 'DB_PROVISIONING'] as $alias => $prefix) {
        $configuration = app(EnvironmentConnection::class)->configuration($alias);
        foreach (['host' => 'database', 'port' => '5432', 'database' => $expected[$prefix][0], 'username' => $expected[$prefix][1], 'search_path' => 'public'] as $key => $value) {
            if ((string) ($configuration[$key] ?? '') !== $value || ($configuration['driver'] ?? null) !== 'pgsql' || ($configuration['url'] ?? null)) {
                refuseFixture();
            }
        }
    }

    final class Cpdb02FixtureJob implements ShouldQueue
    {
        use Batchable, Dispatchable, InteractsWithQueue, Queueable;

        public function __construct(public string $prefix, public string $environmentId, public string $kind) {}

        public function handle(EnvironmentConnection $connections): void
        {
            $label = match ($this->environmentId) {
                'cpdb02_environment_a' => 'a', 'cpdb02_environment_b' => 'b', default => throw new RuntimeException('Unknown synthetic environment.'),
            };
            $connections->runWithin(fixtureEnvironment($label), function (): void {
                $receipt = (array) DB::connection()->selectOne('SELECT current_database() AS database, current_user AS role');
                app('cache')->store('database')->put($this->prefix.'-'.$this->kind.'-receipt', $receipt, 600);
                if ($this->kind === 'failure') {
                    throw new RuntimeException('Synthetic CPDB02 queue failure.');
                }
            });
        }
    }

    $metadata = [
        'profile_sha256' => $input['profile_sha256'], 'source_hashes' => $sourceHashes,
        'php_version' => PHP_VERSION, 'postgres_version' => explode(' ', (string) DB::connection('business')->selectOne("SELECT current_setting('server_version') AS version")->version)[0],
        'process_id' => getmypid(),
    ];
    $verb = $argv[2];
    $lanes = match ($verb) {
        'preflight' => fixturePreflight(),
        'routing' => fixtureRouting(),
        'control_upgrade' => fixtureControlUpgrade(),
        'migrate_environments' => fixtureMigrateEnvironments(),
        'modules' => fixtureModules(),
        'privileges' => fixturePrivileges(),
        default => fixtureFramework(...explode('_', $verb, 2)),
    };
    echo json_encode(['metadata' => $metadata, 'lanes' => $lanes], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    refuseFixture();
}
