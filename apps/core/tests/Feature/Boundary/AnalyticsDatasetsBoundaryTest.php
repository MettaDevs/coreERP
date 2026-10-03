<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Datasets\DatasetValidator;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Apperp\ContohA\Models\Penjualan;
use Tests\TestCase;

/**
 * Penjaga katalog dataset analitik: setiap dataset yang didaftarkan module — module produk dan module
 * contoh bahan uji — lolos `DatasetValidator` terhadap database sungguhan. Kolom dan join ada di tabelnya,
 * kolom kebijakan ada, setiap field berklasifikasi, permission dan kebijakan ada di manifest module, dan
 * kode dataset berawalan id module serta unik.
 *
 * Di runtime dataset rusak hanya dilewati dengan peringatan di log, dan tidak ada yang membaca log itu
 * sampai pelanggan bertanya kenapa datanya tidak ada. Di sini dataset rusak membuat suite merah, jadi
 * salah ketik nama kolom terungkap di CI, bukan di layar pelanggan.
 */
class AnalyticsDatasetsBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tabel module produk sudah dibuat `Tests\TestCase`; tabel module contoh dibuat test-nya sendiri.
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 2).'/Fixtures/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    public function test_every_registered_dataset_passes_the_validator(): void
    {
        $rows = app(DatasetRegistry::class)->diagnose();
        $codes = array_column($rows, 'code');

        $this->assertContains('management-aset.asset-register', $codes, 'Dataset module produk tidak terdaftar; penjaga ini akan lulus tanpa menguji apa pun.');
        $this->assertContains('contoh-a.penjualan', $codes, 'Dataset module contoh tidak terdaftar; penjaga ini akan lulus tanpa menguji apa pun.');

        $broken = array_map(
            static fn (array $row): string => "{$row['code']} ({$row['module']}): {$row['problem']}",
            array_values(array_filter($rows, static fn (array $row): bool => $row['status'] !== 'valid')),
        );

        $this->assertSame([], $broken, implode("\n", [
            'Dataset analitik berikut ditolak validator, dan di runtime dilewati tanpa satu pun galat di layar:',
            ...$broken,
            'Aturannya ada di docs/todo/analitik/model-semantik.md bagian "Yang diperiksa DatasetValidator";',
            'jalankan `php artisan analytics:datasets` untuk melihat semuanya.',
        ]));
    }

    public function test_detector_reports_a_dataset_with_a_wrong_column(): void
    {
        $registry = new DatasetRegistry(app(DatasetValidator::class));
        $registry->register(new readonly class implements Dataset
        {
            public function moduleId(): string
            {
                return 'contoh-a';
            }

            public function definition(): DatasetDefinition
            {
                // Kolom unit yang salah: kesalahan yang membuat kepala unit melihat unit lain bila lolos.
                return DatasetDefinition::make('contoh-a.penjualan', 'Penjualan')
                    ->model(Penjualan::class)
                    ->permission('contoh-a.penjualan.read')
                    ->dataPolicy('contoh-a.penjualan-unit', legalEntity: 'legal_entity_id', operatingUnit: 'unit_kerja_id')
                    ->measure('count', 'Jumlah', Aggregate::Count);
            }
        });

        $this->assertSame([[
            'contoh-a.penjualan', 'invalid', 'kolom unit_kerja_id tidak ada di tabel contoh_a_tr_penjualan (kolom unit kerja kebijakan).',
        ]], array_map(static fn (array $row): array => [$row['code'], $row['status'], $row['problem']], $registry->diagnose()));
    }
}
