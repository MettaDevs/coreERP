<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Support\Modules\EditionModules;
use App\Support\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Penentu isi image yang dibagikan ke klien.
 *
 * Aturannya tinggal satu sejak edisi per pelanggan dicabut — modul bahan uji tidak pernah ikut —
 * dan justru karena tinggal satu ia harus diuji pada modul buatan, bukan pada repo apa adanya.
 * Repo hari ini kebetulan memuat dua bahan uji dan dua modul bisnis; test yang hanya membaca repo
 * akan tetap hijau pada hari seseorang menghapus `kind` dari sebuah manifest, karena yang
 * dibandingkannya ikut berubah.
 *
 * Modul palsu ditulis ke folder sementara, bukan ke `modules/` yang sungguhan: sisa dari run yang
 * gagal di tengah akan terbaca `module:list`, penjaga batas, Pint, dan PHPStan — kegagalan yang
 * muncul di tempat yang sama sekali tidak berhubungan dengan sebabnya.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel.
 */
class EditionModulesTest extends TestCase
{
    private ?string $akarSementara = null;

    protected function tearDown(): void
    {
        if ($this->akarSementara !== null) {
            $this->hapusFolder($this->akarSementara);
            $this->akarSementara = null;
        }

        parent::tearDown();
    }

    /**
     * Seluruh modul yang dijual ikut, tanpa ada yang harus menyebutnya lebih dulu.
     *
     * Ini perbedaan yang menggantikan edisi: dulu sebuah modul hanya ikut bila manifest pelanggan
     * menyebutnya, sehingga modul yang baru mendarat tidak sampai ke klien mana pun sampai ada
     * yang ingat menambahkannya. Sekarang ia ikut karena berkasnya ada.
     */
    public function test_setiap_modul_yang_dijual_ikut(): void
    {
        $modul = $this->modulUntuk([
            'apotek' => [],
            'inventori' => [],
            'rawat-jalan' => [],
        ]);

        $this->assertSame(['apotek', 'inventori', 'rawat-jalan'], $modul->daftar());
    }

    /**
     * Modul bahan uji tidak pernah ikut, dan ini satu-satunya aturan yang tersisa.
     *
     * Ia tidak bergantung pada pelanggan sama sekali, jadi ia selamat dari pencabutan edisi. Sebuah
     * menu bernama "Contoh A" di layar klien adalah kegagalan yang tidak boleh mungkin terjadi.
     */
    public function test_bahan_uji_internal_tidak_pernah_ikut(): void
    {
        $modul = $this->modulUntuk([
            'apotek' => [],
            'contoh-a' => ['kind' => 'internal-fixture'],
            'contoh-b' => ['kind' => 'internal-fixture'],
        ]);

        $this->assertSame(['apotek'], $modul->daftar());
    }

    /**
     * Modul penghubung ikut seperti modul lain.
     *
     * Dulu ia menunggu kedua sisinya terpilih, karena sisi yang tidak dibeli membuat integrasinya
     * tidak punya lawan bicara. Syarat itu menjadi hampa begitu setiap modul ikut, dan test ini
     * yang menuliskannya sebagai keputusan alih-alih membiarkannya terbaca sebagai kelalaian.
     */
    public function test_modul_penghubung_ikut_seperti_yang_lain(): void
    {
        $modul = $this->modulUntuk([
            'apotek' => [],
            'apotek-ke-rawat-jalan' => ['kind' => 'link'],
            'rawat-jalan' => [],
        ]);

        $this->assertSame(['apotek', 'apotek-ke-rawat-jalan', 'rawat-jalan'], $modul->daftar());
    }

    /**
     * Folder modul yang tidak terbaca ditolak, bukan dipulangkan sebagai daftar kosong.
     *
     * Daftar kosong menghasilkan image berisi Core saja, dan image itu lulus setiap pemeriksaan
     * kebocoran karena memang tidak ada yang bocor. Kegagalannya baru terlihat sebagai menu yang
     * hilang di layar klien, yaitu di tempat yang paling mahal.
     */
    public function test_folder_modul_yang_kosong_ditolak(): void
    {
        $akar = rtrim(sys_get_temp_dir(), '/\\').'/coreerp-edisi-'.bin2hex(random_bytes(6));
        mkdir($akar.'/modules/apperp', 0o777, true);
        $this->akarSementara = $akar;

        $modul = new EditionModules(new ModuleRegistry($akar.'/modules'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tidak satu pun manifest modul terbaca');

        $modul->daftar();
    }

    /**
     * Repo apa adanya dapat dihitung, dan bahan ujinya memang tidak ikut.
     *
     * Ini yang menghubungkan test ini dengan berkas sungguhan. Yang dituntut bukan daftar tetap —
     * daftar itu berubah setiap kali sebuah modul mendarat — melainkan dua hal yang tidak boleh
     * berubah: hasilnya tidak kosong, dan tidak satu pun bahan uji ada di dalamnya.
     */
    public function test_repo_apa_adanya_tidak_memuat_bahan_uji(): void
    {
        $akar = dirname(__DIR__, 5);
        $daftar = (new EditionModules(new ModuleRegistry($akar.'/modules')))->daftar();

        $this->assertNotEmpty($daftar, 'Repo ini memuat modul bisnis; daftar kosong berarti pemindaiannya salah alamat.');
        $this->assertNotContains('contoh-a', $daftar);
        $this->assertNotContains('contoh-b', $daftar);
        $this->assertContains('management-aset', $daftar);
    }

    /**
     * Penghitung yang membaca folder modul palsu.
     *
     * @param  array<string, array{kind?: string}>  $modul
     */
    private function modulUntuk(array $modul): EditionModules
    {
        $akar = rtrim(sys_get_temp_dir(), '/\\').'/coreerp-edisi-'.bin2hex(random_bytes(6));
        mkdir($akar.'/modules/apperp', 0o777, true);
        $this->akarSementara = $akar;

        foreach ($modul as $id => $entri) {
            $folder = $akar.'/modules/apperp/'.$id;
            mkdir($folder, 0o777, true);

            $baris = [
                'id: '.$id,
                'name: '.ucwords(str_replace('-', ' ', $id)),
                'version: 0.1.0',
                'publisher: apperp',
                'kind: '.($entri['kind'] ?? 'business-app'),
                'table_prefix: '.str_replace('-', '_', $id).'_',
                // Bentuknya peta id ke rentang versi, sama dengan manifest sungguhan dan sama
                // dengan yang dibaca katalog provider. Menuliskannya sebagai daftar membuat
                // manifest buatan ini berbeda bentuk dari yang sungguhan, dan sejak itu test
                // berhenti menguji yang nyata.
                'dependsOn: {}',
            ];

            file_put_contents($folder.'/app.yaml', implode("\n", $baris)."\n");
        }

        return new EditionModules(new ModuleRegistry($akar.'/modules'));
    }

    private function hapusFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $isi = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $berkas */
        foreach ($isi as $berkas) {
            $berkas->isDir() ? rmdir($berkas->getPathname()) : unlink($berkas->getPathname());
        }

        rmdir($folder);
    }
}
