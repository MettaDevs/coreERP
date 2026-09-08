<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Actions\Modules\DisableModule;
use App\Actions\Modules\InstallModule;
use App\Actions\Modules\UninstallModule;
use App\Models\ModuleInstallation;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Rangkaian penuh pasang, nonaktifkan, aktifkan lagi, dan cabut.
 *
 * Ini fitur produk yang menjadi alasan seluruh proyek: module dapat dipasang dan dicabut
 * per tenant. Test ini menguji urutan yang akan benar-benar dijalani pelanggan, bukan
 * setiap aksi sendiri-sendiri, karena kesalahannya justru muncul di sambungan antar aksi.
 */
class ModuleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSatu;

    private string $tenantDua;

    /** @var list<string> */
    private array $folderSementara = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantSatu = (string) Str::ulid();
        $this->tenantDua = (string) Str::ulid();
    }

    protected function tearDown(): void
    {
        foreach ($this->folderSementara as $folder) {
            $this->hapusFolder($folder);
        }

        parent::tearDown();
    }

    private function hapusFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        foreach (scandir($folder) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $jalur = $folder.'/'.$item;
            is_dir($jalur) ? $this->hapusFolder($jalur) : unlink($jalur);
        }

        rmdir($folder);
    }

    public function test_rangkaian_penuh_pasang_nonaktifkan_aktifkan_lagi_lalu_cabut(): void
    {
        $pasang = $this->app->make(InstallModule::class);

        $pasang->handle('contoh-a', $this->tenantSatu);
        $pasang->handle('contoh-b', $this->tenantSatu);
        $pasang->handle('contoh-a', $this->tenantDua);

        $this->assertSame(2, $this->jumlahBawaan('contoh_a_m_barang', $this->tenantSatu));
        $this->assertSame(1, $this->jumlahBawaan('contoh_b_m_rak', $this->tenantSatu));

        // Pengguna mengetik datanya sendiri.
        DB::table('contoh_a_m_barang')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantSatu,
            'kode' => 'BRG-PENGGUNA',
            'nama' => 'Diketik pengguna',
            'bawaan' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Nonaktifkan satu module: datanya utuh, module lain tidak terpengaruh.
        $this->app->make(DisableModule::class)->handle('contoh-a', $this->tenantSatu);

        $this->assertSame(ModuleInstallation::STATUS_DISABLED, $this->statusPemasangan('contoh-a', $this->tenantSatu));
        $this->assertSame(ModuleInstallation::STATUS_INSTALLED, $this->statusPemasangan('contoh-b', $this->tenantSatu));
        $this->assertSame(3, $this->jumlahSemua('contoh_a_m_barang', $this->tenantSatu));

        // Aktifkan lagi: data awal tidak dobel.
        $pasang->handle('contoh-a', $this->tenantSatu);

        $this->assertSame(ModuleInstallation::STATUS_INSTALLED, $this->statusPemasangan('contoh-a', $this->tenantSatu));
        $this->assertSame(2, $this->jumlahBawaan('contoh_a_m_barang', $this->tenantSatu));
        $this->assertSame(3, $this->jumlahSemua('contoh_a_m_barang', $this->tenantSatu));

        // Cabut: datanya masih ada, module lain dan tenant lain tetap utuh.
        $this->app->make(UninstallModule::class)->handle('contoh-a', $this->tenantSatu);

        $this->assertSame(ModuleInstallation::STATUS_UNINSTALLED, $this->statusPemasangan('contoh-a', $this->tenantSatu));
        $this->assertSame(3, $this->jumlahSemua('contoh_a_m_barang', $this->tenantSatu), 'Pencabutan tidak boleh menyentuh data.');
        $this->assertSame(ModuleInstallation::STATUS_INSTALLED, $this->statusPemasangan('contoh-b', $this->tenantSatu));
        $this->assertSame(1, $this->jumlahBawaan('contoh_b_m_rak', $this->tenantSatu));
        $this->assertSame(ModuleInstallation::STATUS_INSTALLED, $this->statusPemasangan('contoh-a', $this->tenantDua));
        $this->assertSame(2, $this->jumlahBawaan('contoh_a_m_barang', $this->tenantDua));
    }

    public function test_memasang_ulang_setelah_dicabut_tidak_mengisi_data_awal_lagi(): void
    {
        $pasang = $this->app->make(InstallModule::class);
        $pasang->handle('contoh-a', $this->tenantSatu);
        $this->app->make(UninstallModule::class)->handle('contoh-a', $this->tenantSatu);

        $pasang->handle('contoh-a', $this->tenantSatu);

        $this->assertSame(2, $this->jumlahBawaan('contoh_a_m_barang', $this->tenantSatu));
    }

    public function test_perintah_cabut_tidak_punya_opsi_penghapusan_data(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $perintah = $kernel->all()['module:uninstall'] ?? null;

        $this->assertNotNull($perintah);

        $opsi = array_map(
            static fn ($o): string => $o->getName(),
            $perintah->getDefinition()->getOptions(),
        );

        foreach ($opsi as $nama) {
            $this->assertStringNotContainsStringIgnoringCase('purge', $nama);
            $this->assertStringNotContainsStringIgnoringCase('delete', $nama);
            $this->assertStringNotContainsStringIgnoringCase('drop', $nama);
            $this->assertStringNotContainsStringIgnoringCase('hapus', $nama);
        }
    }

    public function test_mencabut_module_yang_masih_dibutuhkan_module_lain_ditolak(): void
    {
        $this->app->make(InstallModule::class)->handle('contoh-a', $this->tenantSatu);
        $this->app->make(InstallModule::class)->handle('contoh-b', $this->tenantSatu);

        $this->jadikanBergantung('contoh-b', 'contoh-a');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('masih dibutuhkan contoh-b');

        $this->app->make(UninstallModule::class)->handle('contoh-a', $this->tenantSatu);
    }

    public function test_module_yang_bergantung_tapi_tidak_dipakai_tenant_ini_tidak_menghalangi(): void
    {
        $this->app->make(InstallModule::class)->handle('contoh-a', $this->tenantSatu);
        $this->app->make(InstallModule::class)->handle('contoh-b', $this->tenantDua);

        $this->jadikanBergantung('contoh-b', 'contoh-a');

        $hasil = $this->app->make(UninstallModule::class)->handle('contoh-a', $this->tenantSatu);

        $this->assertSame(ModuleInstallation::STATUS_UNINSTALLED, $hasil->status);
    }

    public function test_memasang_module_yang_dependencynya_belum_ada_ditolak(): void
    {
        $this->jadikanBergantung('contoh-b', 'contoh-a');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('membutuhkan contoh-a');

        $this->app->make(InstallModule::class)->handle('contoh-b', $this->tenantSatu);
    }

    public function test_perintah_pasang_dan_cabut_berjalan_lewat_artisan(): void
    {
        $this->assertSame(0, Artisan::call('module:install', ['module' => 'contoh-a', 'tenant' => $this->tenantSatu]));
        $this->assertSame(0, Artisan::call('module:disable', ['module' => 'contoh-a', 'tenant' => $this->tenantSatu]));
        $this->assertSame(0, Artisan::call('module:uninstall', ['module' => 'contoh-a', 'tenant' => $this->tenantSatu]));
        $this->assertSame(1, Artisan::call('module:install', ['module' => 'tidak-ada', 'tenant' => $this->tenantSatu]));
    }

    /**
     * Menyatakan bahwa satu module bergantung pada module lain.
     *
     * Manifestnya disalin ke folder sementara dengan `depends_on` ditambahkan, lalu registry
     * diarahkan ke sana. Berkas manifest di repo tidak disentuh, dan kelas registry tidak
     * perlu dilonggarkan demi test.
     */
    private function jadikanBergantung(string $moduleId, string $bergantungPada): void
    {
        $akarAsli = dirname(base_path(), 2).'/modules';
        $akarSementara = sys_get_temp_dir().'/coreerp-lifecycle-'.bin2hex(random_bytes(6));

        foreach (glob($akarAsli.'/*/*/app.yaml') ?: [] as $manifest) {
            $folder = dirname($manifest);
            $tujuan = $akarSementara.'/'.basename(dirname($folder)).'/'.basename($folder);

            if (! is_dir($tujuan) && ! mkdir($tujuan, 0o777, true) && ! is_dir($tujuan)) {
                $this->fail('Tidak bisa membuat folder sementara: '.$tujuan);
            }

            $isi = (string) file_get_contents($manifest);

            if (str_contains($isi, 'id: '.$moduleId.'
')) {
                $isi = str_replace('depends_on: []', 'depends_on:
  - '.$bergantungPada, $isi);
            }

            file_put_contents($tujuan.'/app.yaml', $isi);
        }

        $this->folderSementara[] = $akarSementara;
        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($akarSementara));
    }

    private function statusPemasangan(string $moduleId, string $tenantId): string
    {
        return (string) DB::table('core_module_installations')
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->value('status');
    }

    private function jumlahSemua(string $tabel, string $tenantId): int
    {
        return DB::table($tabel)->where('tenant_id', $tenantId)->count();
    }

    private function jumlahBawaan(string $tabel, string $tenantId): int
    {
        return DB::table($tabel)->where('tenant_id', $tenantId)->where('bawaan', true)->count();
    }
}
