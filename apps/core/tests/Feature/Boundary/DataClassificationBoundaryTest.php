<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Platform\Modules\Contracts\AuditColumns;
use App\Platform\Modules\Contracts\DataClass;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Setiap tabel tenant, milik Core maupun module, membawa klasifikasi data (gap 5, K-06, K-19): padanan
 * AS0016 di Business Central, yang menolak field tanpa `DataClassification` atau bernilai
 * `ToBeClassified`.
 *
 * Daftar tabelnya sama dengan `AuditColumnsBoundaryTest`: semua tabel ber-`tenant_id` dikurangi tabel
 * sisi pusat. Tabel module ikut karena `Tests\TestCase` membuat tabel module produk bersama migration
 * Core.
 */
final class DataClassificationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kolom yang namanya memuat potongan data pribadi (`name`, `address`, ...) tetapi isinya bukan data
     * orang atau organisasi. Berlaku untuk nama kolom ini di tabel mana pun.
     *
     * @var list<string>
     */
    private const NAME_EXCEPTIONS = [
        'table_name',   // nama tabel database di log perubahan dan setelannya
        'field_name',   // nama kolom database di log perubahan dan setelannya
        'report_name',  // judul laporan dari katalog laporan module
        'layout_name',  // judul layout cetak
        'file_name',    // nama berkas hasil ekspor atau berkas yang diimpor
    ];

    public function test_every_tenant_table_is_classified(): void
    {
        $tables = array_values(array_diff(
            (new AuditColumnInspector(DB::connection()))->tenantTables(),
            AuditColumnInspector::controlPlaneTables(),
        ));

        foreach (['vendors', 'aset_tr_aset', 'hr_workers', 'access_audit_events'] as $expected) {
            $this->assertContains($expected, $tables, "Daftar tabel tenant tidak memuat {$expected}; penjaga ini membaca skema yang salah.");
        }

        $inspector = DataClassificationInspector::fromCodebase(DB::connection());
        $problems = $inspector->problems($tables, self::NAME_EXCEPTIONS);

        $this->assertSame([], $problems, "Tabel tenant berikut belum diklasifikasi dengan benar:\n"
            .implode("\n", array_map(
                fn (string $table, array $found): string => "  - {$table}: ".implode('; ', $found),
                array_keys($problems),
                $problems,
            ))
            ."\n\nModel: pasang #[DataClassification(DataClass::...)] dan timpa kolom di COLUMN_CLASSIFICATION."
            ."\nTabel tanpa model: tambahkan ke registry pemiliknya (App\\Models\\UnmodeledTables atau registry module).");

        // Contoh yang dijaga eksplisit: nama dan email pekerja adalah data pribadi, dan kolom jejak
        // mendapat klasifikasinya dari platform.
        $workers = $inspector->effective('hr_workers');
        $this->assertNotNull($workers);
        $this->assertSame(DataClass::EndUserIdentifiableInformation, $workers['name']);
        $this->assertSame(DataClass::EndUserIdentifiableInformation, $workers['email']);
        $this->assertSame(DataClass::EndUserPseudonymousIdentifiers, $workers[AuditColumns::CREATED_BY]);
    }

    public function test_checker_catches_unclassified_tables(): void
    {
        foreach (['contoh_tanpa_klasifikasi', 'contoh_belum_diputuskan', 'contoh_timpaan_salah', 'contoh_nama_ikut_bawaan', 'contoh_benar'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('tenant_id');
                $blueprint->string('nama');
                $blueprint->string('table_name')->nullable();
            });
        }

        $inspector = new DataClassificationInspector(DB::connection(), [
            'contoh_belum_diputuskan' => ['source' => 'contoh', 'default' => DataClass::ToBeClassified, 'columns' => ['nama' => DataClass::EndUserIdentifiableInformation]],
            'contoh_timpaan_salah' => ['source' => 'contoh', 'default' => DataClass::CustomerContent, 'columns' => ['nama' => DataClass::CustomerContent, 'email' => DataClass::EndUserIdentifiableInformation]],
            'contoh_nama_ikut_bawaan' => ['source' => 'contoh', 'default' => DataClass::CustomerContent, 'columns' => []],
            'contoh_benar' => ['source' => 'contoh', 'default' => DataClass::CustomerContent, 'columns' => ['nama' => DataClass::EndUserIdentifiableInformation]],
        ], undeclaredModels: ['contoh_tanpa_klasifikasi' => 'App\\Models\\Contoh']);

        $problems = $inspector->problems(
            ['contoh_tanpa_klasifikasi', 'contoh_belum_diputuskan', 'contoh_timpaan_salah', 'contoh_nama_ikut_bawaan', 'contoh_benar'],
            self::NAME_EXCEPTIONS,
        );

        $this->assertSame(['contoh_tanpa_klasifikasi', 'contoh_belum_diputuskan', 'contoh_timpaan_salah', 'contoh_nama_ikut_bawaan'], array_keys($problems));
        $this->assertStringContainsString('App\\Models\\Contoh', $problems['contoh_tanpa_klasifikasi'][0]);
        $this->assertSame(['bawaan tabel masih ToBeClassified'], $problems['contoh_belum_diputuskan']);
        $this->assertSame(['timpaan menyebut kolom email yang tidak ada'], $problems['contoh_timpaan_salah']);
        $this->assertCount(1, $problems['contoh_nama_ikut_bawaan']);
        $this->assertStringContainsString('kolom nama', $problems['contoh_nama_ikut_bawaan'][0]);

        $this->assertSame(DataClass::EndUserIdentifiableInformation, $inspector->effective('contoh_benar')['nama'] ?? null);
        $this->assertNull($inspector->effective('contoh_tanpa_klasifikasi'));
    }
}
