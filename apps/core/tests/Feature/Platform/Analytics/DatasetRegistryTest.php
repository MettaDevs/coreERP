<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Datasets\DatasetValidator;
use App\Platform\Analytics\Datasets\SharedDimensionRegistry;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\Datasets;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\Analytics\SharedDimensions;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Models\ModuleInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Modules\Apperp\ContohA\Analytics\BarangDataset;
use Modules\Apperp\ContohA\Models\Barang;
use Tests\Concerns\WritesSiteLicenses;
use Tests\TestCase;

/**
 * Registry dataset (area 0 dan 1): satu benda untuk module dan Core; dataset yang definisinya tidak dapat
 * dipakai dilewati dengan peringatan tanpa menjatuhkan aplikasi; dataset yang tabelnya belum ada tidak
 * tersedia tanpa peringatan dan langsung terbaca begitu module-nya dipasang di proses yang sama; dan
 * `forTenant()` hanya menawarkan dataset module yang terpasang untuk tenant itu dan berlisensi.
 * Aturan per definisi diuji `DatasetValidatorTest`.
 */
class DatasetRegistryTest extends TestCase
{
    use RefreshDatabase;
    use WritesSiteLicenses;

    protected function tearDown(): void
    {
        try {
            if (isset($this->licenseDirectory)) {
                $this->removeSiteLicenseDirectory();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_registries_are_one_shared_instance_for_modules_foundation_and_core(): void
    {
        $this->assertSame(app(DatasetRegistry::class), app(Datasets::class));
        $this->assertNotNull(app(DatasetRegistry::class)->find('management-aset.asset-register'), 'Dataset register aset tidak terdaftar dari penyedia layanan module.');

        // Dimensi bersama: milik Platform dipasang registry-nya, milik Foundation didaftarkan fiturnya sendiri.
        $shared = app(SharedDimensionRegistry::class);
        $this->assertSame($shared, app(SharedDimensions::class));
        foreach (SharedDimension::cases() as $dimension) {
            $this->assertSame($dimension, $shared->for($dimension)?->dimension(), "Dimensi bersama {$dimension->value} tidak punya penerjemah label.");
        }
    }

    public function test_unusable_definitions_are_skipped_with_a_warning(): void
    {
        $this->migrateFixture();
        Log::spy();
        $registry = $this->registry();

        // Kode yang tidak berawalan id module pemiliknya.
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-b.barang', 'Barang')
            ->model(Barang::class)->permission('contoh-a.barang.read')->measure('count', 'Jumlah', Aggregate::Count)));
        // Model tanpa BelongsToTenant: penyaringan tenant tidak ikut dari model.
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-a.pemasangan', 'Pemasangan')
            ->model(ModuleInstallation::class)->permission('contoh-a.barang.read')->measure('count', 'Jumlah', Aggregate::Count)));
        // Uang tanpa kolom mata uang tidak dapat dicegah dijumlah lintas mata uang (KA-22).
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-a.nilai', 'Nilai')
            ->model(Barang::class)->permission('contoh-a.barang.read')->measure('total', 'Total', Aggregate::Sum, field: 'nilai', format: MeasureFormat::Money)));
        // Lolos tahap tanpa database, tetapi kolomnya tidak ada di tabel: dilewati juga, dengan sebabnya.
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-a.warna', 'Warna')
            ->model(Barang::class)->permission('contoh-a.barang.read')->measure('count', 'Jumlah', Aggregate::Count)->field('warna', 'Warna', FieldType::Text)));
        // Definisi module yang melempar apa pun adalah dataset rusak, bukan aplikasi yang rusak.
        $registry->register(new class implements Dataset
        {
            public function moduleId(): string
            {
                return 'contoh-a';
            }

            public function definition(): DatasetDefinition
            {
                throw new LogicException('definisi rusak di kode module');
            }
        });
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-a.barang', 'Barang')
            ->model(Barang::class)->permission('contoh-a.barang.read')->measure('count', 'Jumlah', Aggregate::Count)));

        $this->assertNull($registry->find('contoh-b.barang'));
        $this->assertNull($registry->find('contoh-a.pemasangan'));
        $this->assertNull($registry->find('contoh-a.nilai'));
        $this->assertNull($registry->find('contoh-a.warna'));
        $this->assertSame(['contoh-a.barang'], array_map(static fn (CompiledDataset $dataset): string => $dataset->code, $registry->all()));
        $this->assertSame('contoh_a_m_barang.kode', $registry->find('contoh-a.barang')?->qualified('kode'));

        foreach (['berawalan id module', 'BelongsToTenant', 'kolom mata uang', 'kolom warna tidak ada', 'definisi rusak di kode module'] as $reason) {
            Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, $reason))->once();
        }

        // Dataset rusak di database ini dicatat sekali per proses, bukan di setiap permintaan.
        $registry->all();
        $registry->find('contoh-a.warna');
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, 'kolom warna tidak ada'))->once();
    }

    public function test_a_dataset_whose_table_is_missing_is_unavailable_without_warning_until_its_module_is_installed(): void
    {
        Log::spy();
        $registry = $this->registry();
        $registry->register(new BarangDataset);

        // Module belum dipasang di database environment ini: keadaan wajar, bukan cacat.
        $this->assertNull($registry->find('contoh-a.barang'));
        $this->assertSame([], $registry->all());
        Log::shouldNotHaveReceived('warning');

        // Pemasangan sesudahnya di proses yang sama — pekerja FrankenPHP hidup lama — langsung terbaca.
        $this->migrateFixture();
        $this->assertNotNull($registry->find('contoh-a.barang'));
    }

    public function test_for_tenant_offers_only_datasets_of_modules_installed_and_licensed_for_that_tenant(): void
    {
        $this->migrateFixture();
        $registry = app(DatasetRegistry::class);
        $installed = (string) Str::ulid();
        $disabled = (string) Str::ulid();
        $this->install($installed, 'contoh-a', ModuleInstallation::STATUS_INSTALLED);
        $this->install($disabled, 'contoh-a', ModuleInstallation::STATUS_DISABLED);

        $codes = static fn (array $datasets): array => array_map(static fn (CompiledDataset $dataset): string => $dataset->code, $datasets);

        $this->assertSame(['contoh-a.barang', 'contoh-a.penjualan'], $codes($registry->forTenant($installed)));
        $this->assertSame([], $codes($registry->forTenant($disabled)), 'Module yang dinonaktifkan tidak terpasang; datasetnya tidak boleh ditawarkan.');
        $this->assertSame([], $codes($registry->forTenant((string) Str::ulid())));

        // Lisensi situs yang tidak mencantumkan module-nya menutup datasetnya, walau catatan pemasangannya ada.
        $this->prepareSiteLicenseDirectory();
        config()->set('coreerp.license.required', true);
        $this->installSignedLicense($this->licenseJson(now()->addMonth()->toDateString(), ['management-aset']));
        $this->app->forgetScopedInstances();
        $this->assertSame([], $codes($registry->forTenant($installed)));

        $this->installSignedLicense($this->licenseJson(now()->addMonth()->toDateString(), ['contoh-a']));
        $this->app->forgetScopedInstances();
        $this->assertSame(['contoh-a.barang', 'contoh-a.penjualan'], $codes($registry->forTenant($installed)));
    }

    public function test_diagnose_reports_every_registered_dataset_without_skipping_or_logging(): void
    {
        $this->migrateFixture();
        Log::spy();
        $registry = $this->registry();
        $registry->register(new BarangDataset);
        $registry->register(new BarangDataset);
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-a.warna', 'Warna')
            ->model(Barang::class)->permission('contoh-a.barang.read')->measure('total', 'Total', Aggregate::Sum, field: 'warna')));

        $rows = array_map(static fn (array $row): array => [$row['code'], $row['status'], $row['problem']], $registry->diagnose());

        $this->assertSame([
            ['contoh-a.barang', 'valid', null],
            ['contoh-a.barang', 'invalid', 'kodenya sudah dipakai dataset lain.'],
            ['contoh-a.warna', 'invalid', 'kolom warna tidak ada di tabel contoh_a_m_barang (measure total).'],
        ], $rows);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_command_lists_datasets_and_fails_when_one_is_broken(): void
    {
        $this->migrateFixture();

        $this->artisan('analytics:datasets')
            ->expectsOutputToContain('contoh-a.penjualan')
            ->expectsOutputToContain('management-aset.asset-register')
            ->assertSuccessful();

        app(DatasetRegistry::class)->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-a.warna', 'Warna')
            ->model(Barang::class)->permission('contoh-a.barang.read')->measure('total', 'Total', Aggregate::Sum, field: 'warna')));

        $this->artisan('analytics:datasets')
            ->expectsOutputToContain('kolom warna tidak ada')
            ->expectsOutputToContain('1 dataset rusak')
            ->assertFailed();
    }

    private function registry(): DatasetRegistry
    {
        return new DatasetRegistry(app(DatasetValidator::class));
    }

    private function migrateFixture(): void
    {
        // Tabel module contoh dibuat test-nya sendiri, seperti penjaga batas lain (`TenantScopeBoundaryTest`).
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 3).'/Fixtures/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    private function install(string $tenantId, string $moduleId, string $status): void
    {
        DB::table('core_module_installations')->insert([
            'tenant_id' => $tenantId, 'module_id' => $moduleId, 'version' => '0.1.0', 'status' => $status,
            'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dataset(string $moduleId, DatasetDefinition $definition): Dataset
    {
        return new readonly class($moduleId, $definition) implements Dataset
        {
            public function __construct(private string $module, private DatasetDefinition $definition) {}

            public function moduleId(): string
            {
                return $this->module;
            }

            public function definition(): DatasetDefinition
            {
                return $this->definition;
            }
        };
    }
}
