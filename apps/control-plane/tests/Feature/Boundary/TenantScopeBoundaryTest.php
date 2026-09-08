<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ContohA\Models\Barang;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\TestCase;

/**
 * Penjaga ketiga: tidak ada query module yang berjalan tanpa penyaringan tenant.
 *
 * Dulu kebocoran antar tenant tertahan oleh database yang memang terpisah. Sekarang semua
 * tenant berada di satu tabel, dan satu query yang lupa menyaring mengembalikan baris milik
 * seluruh pelanggan sekaligus. Ini kegagalan paling mahal yang bisa terjadi pada penempatan
 * gabungan, dan database tidak bisa mencegahnya.
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
        $berkasDiperiksa = 0;
        $pelanggaran = [];

        foreach ($this->berkasPhpModule() as $berkas) {
            $berkasDiperiksa++;
            $isi = (string) file_get_contents($berkas->getPathname());

            foreach (['DB::table(', 'DB::select(', 'DB::statement('] as $pola) {
                if (str_contains($isi, $pola)) {
                    $pelanggaran[] = $this->jalurRingkas($berkas->getPathname()).' memakai '.$pola;
                }
            }
        }

        $this->assertGreaterThan(0, $berkasDiperiksa, 'Tidak ada berkas module yang dibaca; penjaga ini tidak menguji apa pun.');
        $this->assertSame([], $pelanggaran, implode("\n", [
            'Kode module memakai query builder mentah pada tabelnya sendiri.',
            'Query mentah melewati global scope tenant, jadi ia tidak tersaring dan tidak ada yang memberi tahu.',
            'Pakai model module; bila memang butuh SQL langsung, saring tenant secara eksplisit dan',
            'daftarkan pengecualiannya di berkas test ini supaya terlihat pada diff.',
        ]));
    }

    private function jadikanTenantAktif(string $tenantId): void
    {
        $this->app->instance(TenantScope::KUNCI, $tenantId);
    }

    /**
     * Berkas PHP module, kecuali migration.
     *
     * Migration memang menulis SQL langsung — indeks unik parsial ditulis begitu — dan ia
     * berjalan sebelum ada tenant mana pun, jadi tidak masuk akal menuntutnya tersaring.
     *
     * @return list<SplFileInfo>
     */
    private function berkasPhpModule(): array
    {
        $akar = dirname(__DIR__, 5).'/modules';
        $berkas = [];

        if (! is_dir($akar)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if (! $item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }

            if (str_contains(str_replace('\\', '/', $item->getPathname()), '/database/migrations/')) {
                continue;
            }

            $berkas[] = $item;
        }

        return $berkas;
    }

    private function jalurRingkas(string $jalur): string
    {
        $jalur = str_replace('\\', '/', $jalur);
        $potong = strpos($jalur, '/modules/');

        return $potong === false ? $jalur : substr($jalur, $potong + 1);
    }
}
