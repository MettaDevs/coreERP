<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Registry menemukan module dengan memindai folder, bukan membaca daftar yang ditulis
 * tangan. Test ini menjaga dua hal: apa yang ditemukan, dan apa yang sengaja dilewati.
 */
class ModuleRegistryTest extends TestCase
{
    private string $akarSementara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akarSementara = sys_get_temp_dir().'/coreerp-module-registry-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->hapusFolder($this->akarSementara);

        parent::tearDown();
    }

    /**
     * Yang dilayani adalah seluruh module di repo, termasuk modul produk.
     *
     * Daftarnya ditulis lengkap dan bukan sekadar "berisi", supaya module yang **hilang** dari
     * runtime ikut terlihat. `management-aset` masuk sejak F3-30: selama entrinya ada di
     * `ModulSedangDipindah` ia dimuat tetapi tidak dilayani, dan test ini yang menandai
     * perpindahannya.
     */
    public function test_registry_menemukan_seluruh_module_di_repo(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        $this->assertSame(
            ['contoh-a', 'contoh-b', 'human-resources', 'management-aset'],
            array_map(static fn ($m): string => $m->id, $registry->semua()),
        );
    }

    public function test_module_bercetakan_change_me_dilewati(): void
    {
        $this->tulisManifest('apperp/sudah-jadi', "id: sudah-jadi\nname: Sudah jadi\nversion: 1.2.3\npublisher: apperp\ntable_prefix: jadi_\n");
        $this->tulisManifest('apperp/belum-diisi', "id: change-me\nname: change-me\nversion: 0.0.0\npublisher: apperp\n");

        $registry = new ModuleRegistry($this->akarSementara);

        $this->assertSame(['sudah-jadi'], array_map(static fn ($m): string => $m->id, $registry->semua()));
    }

    public function test_module_tanpa_awalan_tabel_dilewati(): void
    {
        $this->tulisManifest('apperp/berawalan', 'id: berawalan
name: Berawalan
version: 1.0.0
publisher: apperp
table_prefix: awal_
');
        $this->tulisManifest('apperp/belum-dibentuk', 'id: belum-dibentuk
name: Belum dibentuk
version: 0.9.0
publisher: apperp
');

        $registry = new ModuleRegistry($this->akarSementara);

        $this->assertSame(
            ['berawalan'],
            array_map(static fn ($m): string => $m->id, $registry->semua()),
            'Module tanpa table_prefix tidak boleh dilayani: tabelnya akan memakai nama apa adanya '.
            'dan bertabrakan dengan milik Core. Ini keadaan modul yang baru ditarik masuk dan belum '.
            'dibentuk ulang.'
        );
    }

    public function test_manifest_rusak_dilewati_tanpa_menjatuhkan_runtime(): void
    {
        $this->tulisManifest('apperp/sehat', "id: sehat\nname: Sehat\nversion: 1.0.0\npublisher: apperp\ntable_prefix: sehat_\n");
        $this->tulisManifest('apperp/rusak', "id: rusak\n  name: [belum ditutup\n");

        $registry = new ModuleRegistry($this->akarSementara);

        $this->assertSame(
            ['sehat'],
            array_map(static fn ($m): string => $m->id, $registry->semua()),
            'Satu manifest yang rusak tidak boleh membuat seluruh runtime gagal menyala.'
        );
    }

    public function test_module_contoh_ditandai_bahan_uji_internal(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $contohA = $registry->cari('contoh-a');

        $this->assertNotNull($contohA);
        $this->assertTrue(
            $contohA->bahanUjiInternal(),
            'Module contoh wajib bertanda internal-fixture; tanda itulah yang menahannya masuk ke edisi pelanggan.'
        );
    }

    public function test_perintah_module_list_menampilkan_kedua_module_beserta_versinya(): void
    {
        Artisan::call('module:list');
        $keluaran = Artisan::output();

        foreach (['contoh-a', 'Contoh A', 'contoh-b', 'Contoh B', '0.1.0'] as $penggalan) {
            $this->assertStringContainsString($penggalan, $keluaran);
        }
    }

    private function tulisManifest(string $jalurRelatif, string $isi): void
    {
        $folder = $this->akarSementara.'/'.$jalurRelatif;

        if (! is_dir($folder) && ! mkdir($folder, 0o777, true) && ! is_dir($folder)) {
            $this->fail('Tidak bisa membuat folder sementara: '.$folder);
        }

        file_put_contents($folder.'/app.yaml', $isi);
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
}
