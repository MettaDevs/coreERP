<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\Datasets;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Models\ModuleInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Modules\Apperp\ContohA\Models\Barang;
use Modules\Apperp\ContohB\Models\Rak;
use Tests\TestCase;

/**
 * Pemeriksaan minimal registry dataset (area 0): dataset yang definisinya tidak dapat dipakai dilewati
 * dengan peringatan, tidak menjatuhkan aplikasi, dan tidak pernah dibaca tanpa penyaringan tenant.
 * Pemeriksaan lengkap milik `DatasetValidator` di area 1.
 */
class DatasetRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_is_one_shared_instance_for_modules_and_core(): void
    {
        $this->assertSame(app(DatasetRegistry::class), app(Datasets::class));
        $this->assertNotNull(app(DatasetRegistry::class)->find('management-aset.asset-register'), 'Dataset register aset tidak terdaftar dari penyedia layanan module.');
    }

    public function test_unusable_definitions_are_skipped_with_a_warning(): void
    {
        // Tabel module contoh dibuat test-nya sendiri, seperti penjaga batas lain (`TenantScopeBoundaryTest`).
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 3).'/Fixtures/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
        Log::spy();
        $registry = new DatasetRegistry;

        // Kode yang tidak berawalan id module pemiliknya.
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-b.barang', 'Barang')
            ->model(Barang::class)->permission('contoh-a.barang.read')->measure('count', 'Jumlah', Aggregate::Count)));
        // Model tanpa BelongsToTenant: penyaringan tenant tidak ikut dari model.
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-a.pemasangan', 'Pemasangan')
            ->model(ModuleInstallation::class)->permission('contoh-a.barang.read')->measure('count', 'Jumlah', Aggregate::Count)));
        // Uang tanpa kolom mata uang tidak dapat dicegah dijumlah lintas mata uang (KA-22).
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-a.nilai', 'Nilai')
            ->model(Barang::class)->permission('contoh-a.barang.read')->measure('total', 'Total', Aggregate::Sum, field: 'nilai', format: MeasureFormat::Money)));
        $registry->register($this->dataset('contoh-a', DatasetDefinition::make('contoh-a.barang', 'Barang')
            ->model(Barang::class)->permission('contoh-a.barang.read')->measure('count', 'Jumlah', Aggregate::Count)));
        // Definisi sah, tetapi tabel module-nya belum ada di database ini (module belum dipasang di sini):
        // tidak tersedia, tanpa peringatan, karena itu keadaan wajar pada database per environment.
        $registry->register($this->dataset('contoh-b', DatasetDefinition::make('contoh-b.rak', 'Rak')
            ->model(Rak::class)->permission('contoh-b.rak.read')->measure('count', 'Jumlah', Aggregate::Count)));

        $this->assertNull($registry->find('contoh-b.barang'));
        $this->assertNull($registry->find('contoh-a.pemasangan'));
        $this->assertNull($registry->find('contoh-a.nilai'));
        $this->assertNull($registry->find('contoh-b.rak'));
        $this->assertSame(['contoh-a.barang'], array_map(static fn ($dataset): string => $dataset->code, $registry->all()));
        $this->assertSame('contoh_a_m_barang.kode', $registry->find('contoh-a.barang')?->qualified('kode'));

        foreach (['berawalan id module', 'BelongsToTenant', 'kolom mata uang'] as $reason) {
            Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, $reason))->once();
        }
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
