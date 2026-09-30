<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\Contracts\AuditColumns;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Setiap tabel tenant milik Core membawa kolom jejak pembuat dan pengubah beserta trigger pengisinya
 * (K-01, area 1 TODO analisa gap BC fase 1), dan versi baris beserta trigger penaiknya (K-03, area 3),
 * seperti `tenant_id` yang juga wajib ada.
 *
 * Tabel sisi pusat tidak ikut; daftarnya diturunkan dari model ber-`OwnedByControlPlane`, bukan
 * ditulis ulang di sini. Tabel module dijaga `ModuleTableBoundaryTest`.
 */
final class AuditColumnsBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** Kolom `version` di sini nomor rilis module yang terpasang, bukan versi baris; tidak ada form yang mengubahnya. */
    private const WITHOUT_ROW_VERSION = ['core_module_installations'];

    public function test_setiap_tabel_tenant_core_membawa_kolom_jejak_dan_triggernya(): void
    {
        $inspector = new AuditColumnInspector(DB::connection());
        $tables = array_values(array_diff($inspector->tenantTables(), AuditColumnInspector::controlPlaneTables()));

        $this->assertContains('vendors', $tables, 'Daftar tabel tenant tidak memuat vendors; penjaga ini membaca skema yang salah.');

        $missing = $inspector->missing($tables, self::WITHOUT_ROW_VERSION);
        $this->assertSame([], $missing, "Tabel tenant berikut belum membawa kolom jejak:\n"
            .implode("\n", array_map(fn ($table, $problem) => "  - {$table}: {$problem}", array_keys($missing), $missing))
            ."\n\nDi Schema::create panggil AuditColumns::add(\$table), lalu AuditColumns::attach('<tabel>') sesudahnya.");
    }

    public function test_pemeriksa_menangkap_tabel_tanpa_kolom_jejak(): void
    {
        Schema::create('contoh_tanpa_jejak', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
        });
        Schema::create('contoh_dengan_jejak', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            AuditColumns::add($table);
        });
        AuditColumns::attach('contoh_dengan_jejak');

        $missing = (new AuditColumnInspector(DB::connection()))->missing(['contoh_tanpa_jejak', 'contoh_dengan_jejak']);

        $this->assertSame(['contoh_tanpa_jejak'], array_keys($missing));
        $this->assertStringContainsString('trigger', $missing['contoh_tanpa_jejak']);
    }
}
