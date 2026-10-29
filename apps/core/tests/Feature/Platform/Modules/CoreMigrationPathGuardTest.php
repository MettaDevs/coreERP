<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CoreMigrationPathGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, bool, bool}> */
    public static function modulePaths(): iterable
    {
        $folder = 'tests/Fixtures/modules/apperp/contoh-a/database/migrations';
        yield 'relative folder' => [$folder, false, false];
        yield 'absolute folder' => [$folder, true, false];
        yield 'normalized folder' => [$folder.'/../migrations', false, false];
        yield 'mixed core and module paths' => [$folder, false, true];
        yield 'product migration file' => ['../../modules/apperp/management-aset/database/migrations/2026_10_23_100000_add_aggregate_creation_payload_hashes.php', false, false];
        yield 'absolute product migration file' => ['../../modules/apperp/management-aset/database/migrations/2026_10_23_100000_add_aggregate_creation_payload_hashes.php', true, false];
    }

    #[DataProvider('modulePaths')]
    public function test_module_paths_are_rejected_before_any_database_query(string $path, bool $absolute, bool $mixed): void
    {
        $paths = [$absolute ? realpath(base_path($path)) : $path];
        if ($mixed) {
            array_unshift($paths, 'database/migrations');
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $exitCode = Artisan::call('migrate', [
                '--path' => $paths,
                '--realpath' => $absolute,
                '--force' => true,
                '--graceful' => true,
            ]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('module:migrate', Artisan::output());
        $this->assertSame([], $queries);
    }

    /** @return iterable<string, array<array<string, mixed>>> */
    public static function corePaths(): iterable
    {
        yield 'default paths' => [[]];
        yield 'relative core folder' => [['--path' => ['database/migrations']]];
        yield 'relative core file' => [['--path' => ['database/migrations/0001_01_01_000000_create_users_table.php']]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('corePaths')]
    public function test_core_migrations_still_run(array $options): void
    {
        $historyCount = DB::table('migrations')->count();

        $this->assertSame(0, Artisan::call('migrate', [...$options, '--force' => true]));

        $this->assertSame($historyCount, DB::table('migrations')->count());
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_module_command_keeps_its_own_history(): void
    {
        $historyCount = DB::table('migrations')->count();

        $this->assertSame(0, Artisan::call('module:migrate', ['module' => 'contoh-a']));

        $this->assertTrue(Schema::hasTable('contoh_a_m_barang'));
        $this->assertSame($historyCount, DB::table('migrations')->count());
        $this->assertGreaterThan(0, DB::table('core_module_migrations')->where('module_id', 'contoh-a')->count());
    }

    public function test_pending_core_migration_runs_and_records_its_history(): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'core-migration-guard-');
        $this->assertNotFalse($temporaryPath);
        $path = $temporaryPath.'.php';
        rename($temporaryPath, $path);
        file_put_contents($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_migration_guard_probe', fn (Blueprint $table) => $table->id());
    }
};
PHP);

        try {
            $this->assertSame(0, Artisan::call('migrate', [
                '--path' => [$path],
                '--realpath' => true,
                '--database' => 'pgsql_test',
                '--force' => true,
            ]));

            $this->assertTrue(Schema::hasTable('core_migration_guard_probe'));
            $this->assertSame(1, DB::table('migrations')->where('migration', basename($path, '.php'))->count());
            $this->assertSame(0, DB::table('core_module_migrations')->where('migration', basename($path, '.php'))->count());
        } finally {
            unlink($path);
        }
    }
}
