<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ContohA\Models\Barang;
use RuntimeException;
use Tests\TestCase;

/**
 * Penjaga ketiga: tidak ada query module yang berjalan tanpa penyaringan tenant.
 *
 * Dulu kebocoran antar tenant tertahan oleh database yang memang terpisah. Sekarang semua
 * tenant berada di satu tabel, dan satu query yang lupa menyaring mengembalikan baris milik
 * seluruh pelanggan sekaligus. Ini kegagalan paling mahal yang bisa terjadi pada penempatan
 * gabungan, dan database tidak bisa mencegahnya.
 *
 * Modul yang sedang dipindah masuk dan belum dibentuk ulang dilewati pada pemeriksaan berkas
 * di bawah. Ia tetap dipindai penuh oleh pemeriksaan basi di `ModulSedangDipindahTest`, jadi
 * yang berubah bukan cakupan pemindaiannya melainkan arti hasilnya: selama modulnya masih
 * melanggar, pengecualian itu sah; begitu ia bersih, pengecualiannya sendiri yang gagal.
 * Daftarnya, alasannya, dan tenggatnya ada di `ModulSedangDipindah`.
 */
class TenantScopeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSatu;

    private string $tenantDua;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 5).'/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->tenantSatu = (string) Str::ulid();
        $this->tenantDua = (string) Str::ulid();

        foreach ([[$this->tenantSatu, 'BRG-SATU'], [$this->tenantDua, 'BRG-DUA']] as [$tenantId, $kode]) {
            DB::table('contoh_a_m_barang')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'kode' => $kode,
                'nama' => 'Barang '.$kode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_query_module_hanya_mengembalikan_baris_tenant_aktif(): void
    {
        $this->jadikanTenantAktif($this->tenantSatu);

        $this->assertSame(['BRG-SATU'], Barang::query()->pluck('kode')->all());

        $this->jadikanTenantAktif($this->tenantDua);

        $this->assertSame(['BRG-DUA'], Barang::query()->pluck('kode')->all());
    }

    public function test_mengambil_baris_tenant_lain_lewat_id_tidak_bisa(): void
    {
        $this->jadikanTenantAktif($this->tenantDua);
        $idMilikTenantDua = Barang::query()->firstOrFail()->id;

        $this->jadikanTenantAktif($this->tenantSatu);

        $this->assertNull(
            Barang::query()->find($idMilikTenantDua),
            'Menebak id milik tenant lain tidak boleh cukup untuk membacanya.'
        );
    }

    public function test_query_tanpa_tenant_aktif_dibatalkan_bukan_dibiarkan(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tanpa tenant aktif');

        Barang::query()->count();
    }

    public function test_module_tidak_memakai_query_builder_mentah_pada_tabelnya(): void
    {
        $pemindai = PemindaiModul::padaRepo();
        $dipindah = ModulSedangDipindah::bawaan();

        $berkasDiperiksa = 0;
        $moduleDiperiksa = 0;
        $pelanggaran = [];

        foreach ($pemindai->folderModul() as $nama => $folder) {
            if ($dipindah->menandai($nama)) {
                continue;
            }

            $moduleDiperiksa++;
            $berkasDiperiksa += count($pemindai->berkasPhp($folder, tanpaMigration: true));
            $pelanggaran = array_merge($pelanggaran, $pemindai->pelanggaranQueryMentah($folder));
        }

        $this->assertGreaterThan(0, $moduleDiperiksa, 'Tidak ada module yang diperiksa; penjaga ini akan lulus tanpa menguji apa pun.');
        $this->assertGreaterThan(0, $berkasDiperiksa, 'Tidak ada berkas module yang dibaca; penjaga ini tidak menguji apa pun.');
        $this->assertSame([], $pelanggaran, implode("\n", [
            'Kode module memakai query builder mentah pada tabelnya sendiri.',
            'Query mentah melewati global scope tenant, jadi ia tidak tersaring dan tidak ada yang memberi tahu.',
            'Pakai model module; bila memang butuh SQL langsung, saring tenant secara eksplisit dan',
            'daftarkan pengecualiannya di berkas test ini supaya terlihat pada diff.',
            'Modul yang sedang dipindah masuk dan belum dibentuk ulang punya pintu lain, dengan',
            'tenggat dan pemeriksaan basi: ModulSedangDipindah.',
        ]));
    }

    private function jadikanTenantAktif(string $tenantId): void
    {
        $this->app->instance(TenantScope::KUNCI, $tenantId);
    }
}
