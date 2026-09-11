<?php

declare(strict_types=1);

namespace Tests\Feature\Observabilitas;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\User;
use App\Support\Observabilitas\BerkasLaporan;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Penjaga: laporan kesalahan dari permintaan sungguhan benar-benar memuat identitasnya.
 *
 * Test lain di folder ini memasang atribut permintaan dengan tangan, yang membuktikan laporan
 * **membaca** atribut itu dengan benar. Yang tidak dibuktikannya adalah apakah atribut itu
 * memang sudah terisi pada saat kesalahan terjadi — dan itu pertanyaan yang berbeda, karena
 * jawabannya bergantung pada urutan middleware.
 *
 * `ResolveModuleContext` dipasang per grup rute module, sementara pelapor berjalan dari
 * penangan kesalahan global. Kalau urutannya salah, laporan tetap tersusun rapi dan tetap
 * lulus semua test lain — hanya saja setiap barisnya berisi `-`. Kegagalan yang tidak
 * berbunyi, dan baru ketahuan saat seseorang membuka laporan sungguhan di tengah insiden.
 *
 * Karena itu test ini menembak lewat stack middleware yang sebenarnya, dengan pengguna yang
 * benar-benar login dan tenant yang benar-benar ada.
 */
class KonteksLaporanPermintaanTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

    private int $panjangAwal = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AppCatalogSeeder::class);

        $this->pemilik = app(RegisterBusiness::class)->handle([
            'name' => 'Budi Santoso',
            'business_name' => 'Apotek Sejahtera',
            'app_ids' => [],
            'email' => 'budi@apotek.test',
            'password' => 'password',
        ]);

        // Berkas laporan dibagi seluruh proses yang menulis pada hari dan peran yang sama —
        // termasuk seluruh worker ParaTest. Menghapusnya di sini akan menghapus laporan milik
        // test lain yang sedang berjalan, dan sebaliknya; kegagalannya muncul sebagai test
        // yang membaca laporan orang lain. Terjadi persis begitu pada jalannya suite penuh.
        //
        // Karena itu yang dicatat adalah **panjangnya sebelum test**, dan yang dibaca hanya
        // bagian yang bertambah sesudahnya.
        $this->panjangAwal = is_file(BerkasLaporan::jalur()) ? (int) filesize(BerkasLaporan::jalur()) : 0;
    }

    public function test_laporan_dari_permintaan_terautentikasi_memuat_tenant_dan_pengguna(): void
    {
        Route::middleware(['web', 'auth'])
            ->get('/uji/meledak', function (): JsonResponse {
                throw new RuntimeException('perhitungan stok meledak');
            });

        $this->actingAs($this->pemilik)
            ->get('/uji/meledak')
            ->assertStatus(500);

        $isi = $this->isiLaporan();

        // Identitas yang membuat laporan bisa ditelusuri: siapa, dan tenant mana.
        $this->assertStringContainsString('Budi Santoso', $isi);
        $this->assertStringContainsString((string) $this->pemilik->getAuthIdentifier(), $isi);
        $this->assertStringContainsString('Apotek Sejahtera', $isi);

        // Dan konteks permintaannya sendiri.
        $this->assertStringContainsString('GET', $isi);
        $this->assertStringContainsString('/uji/meledak', $isi);
        $this->assertStringContainsString('perhitungan stok meledak', $isi);
    }

    public function test_kegagalan_database_tetap_melaporkan_tanpa_bertanya_ke_database(): void
    {
        // Kebalikan dari dua test di atas, dan sengaja: ketika yang gagal justru database,
        // identitas tidak boleh dikejar. Yang dijaga di sini bukan kelengkapan laporan,
        // melainkan bahwa laporannya tetap ada dan permintaan tetap selesai.
        Route::middleware(['web', 'auth'])
            ->get('/uji/database-mati', function (): JsonResponse {
                throw new QueryException(
                    connectionName: 'pgsql',
                    sql: 'select * from "aset" where "id" = ?',
                    bindings: ['x'],
                    previous: new \PDOException('server closed the connection unexpectedly'),
                    connectionDetails: ['driver' => 'pgsql', 'database' => 'core_erp'],
                );
            });

        $this->actingAs($this->pemilik)
            ->get('/uji/database-mati')
            ->assertStatus(500);

        $isi = $this->isiLaporan();

        $this->assertStringContainsString('server closed the connection', $isi);
        $this->assertStringContainsString('pgsql · core_erp', $isi);
    }

    /** Hanya bagian yang ditambahkan test ini, bukan seluruh isi berkas bersama. */
    private function isiLaporan(): string
    {
        $berkas = BerkasLaporan::jalur();

        $this->assertFileExists($berkas, 'Laporan kesalahan tidak ditulis untuk permintaan yang gagal.');

        $isi = (string) file_get_contents($berkas);

        $this->assertGreaterThan($this->panjangAwal, strlen($isi), 'Berkas laporan tidak bertambah; tidak ada laporan baru yang ditulis.');

        return substr($isi, $this->panjangAwal);
    }
}
