<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Penjaga atas penjaga: yang diuji di sini adalah pengecualiannya sendiri.
 *
 * Pengecualian tanpa cara berakhir bukan pengecualian, melainkan pelonggaran permanen yang
 * kebetulan ditulis dengan kata "sementara". Berkas ini memberi `ModulSedangDipindah` dua cara
 * berakhir dan membuktikan keduanya bekerja, lalu membuktikan hal yang paling mudah salah:
 * melonggarkan untuk satu modul tidak melonggarkan untuk modul lain.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel, jadi ia memakai TestCase polos
 * PHPUnit.
 */
class ModulSedangDipindahTest extends TestCase
{
    /**
     * Akar folder sementara tempat modul palsu dibuat, atau null bila belum ada.
     *
     * Modul palsu **tidak** ditulis ke `modules/` sungguhan. Sebuah run yang gagal di tengah
     * akan meninggalkannya di sana, dan sisa itu lalu terbaca `module:list`, penjaga batas yang
     * lain, Pint, dan PHPStan — kegagalan yang muncul di tempat yang sama sekali tidak
     * berhubungan dengan sebabnya. Folder sementara membuat kemungkinan itu tidak ada, dan
     * nama modulnya tetap diacak per jalan supaya dua jalan yang tumpang tindih tidak bertemu.
     */
    private ?string $akarSementara = null;

    protected function tearDown(): void
    {
        // Dijalankan PHPUnit walau test-nya gagal atau melempar di tengah. Pembersihan yang
        // hanya ditulis di akhir badan test adalah pembersihan yang tidak berjalan justru pada
        // saat ia paling dibutuhkan.
        if ($this->akarSementara !== null) {
            $this->hapusFolder($this->akarSementara);
            $this->akarSementara = null;
        }

        parent::tearDown();
    }

    public function test_tiap_entri_menyebut_alasan_dan_tenggat(): void
    {
        $daftar = ModulSedangDipindah::bawaan()->semua();

        foreach ($daftar as $nama => $entri) {
            $this->assertNotSame('', trim($entri['alasan']), sprintf(
                'Entri "%s" tidak menyebut alasan. Pengecualian tanpa alasan tidak bisa ditinjau, hanya bisa diwarisi.',
                $nama,
            ));

            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $entri['tenggat'],
                sprintf('Tenggat entri "%s" harus ditulis sebagai YYYY-MM-DD.', $nama),
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Cara berakhir yang pertama: tenggat.
     */
    public function test_tenggat_tiap_entri_belum_lewat(): void
    {
        $lewat = ModulSedangDipindah::bawaan()->tenggatYangLewat(new DateTimeImmutable('today'));

        $this->assertSame([], $lewat, implode("\n", [
            'Ada modul yang masih dikecualikan padahal tenggatnya sudah lewat: '.implode(', ', array_keys($lewat)).'.',
            'Pilihannya dua, dan keduanya harus ditulis pada pull request: selesaikan pembentukan',
            'ulang modulnya lalu buang entrinya dari ModulSedangDipindah, atau perpanjang tenggatnya',
            'dengan alasan kenapa perkiraan sebelumnya meleset. Yang tidak boleh adalah membiarkannya.',
        ]));
    }

    /**
     * Cara berakhir yang kedua: pemeriksaan basi.
     *
     * Modul yang dikecualikan tetap dipindai penuh. Bila ia ternyata sudah tidak melanggar apa
     * pun, yang gagal adalah entrinya, bukan modulnya. Inilah yang menjawab "bagaimana orang
     * tahu pengecualian ini sudah boleh dibuang" tanpa mengandalkan ingatan siapa pun.
     */
    public function test_modul_yang_dikecualikan_masih_benar_benar_melanggar(): void
    {
        $pemindai = PemindaiModul::padaRepo();
        $dipindah = ModulSedangDipindah::bawaan();

        $basi = $this->entriBasi($pemindai, $dipindah);

        $this->assertSame([], $basi, implode("\n", [
            'Modul ini sudah bersih, buang entrinya dari ModulSedangDipindah: '.implode(', ', $basi).'.',
            'Pemindaian penuh atas foldernya tidak menemukan satu pun pelanggaran namespace,',
            'kelas Core di luar kontrak, maupun query builder mentah. Pengecualian yang tidak lagi',
            'mengecualikan apa pun hanya menyisakan lubang yang menunggu dipakai orang berikutnya.',
        ]));
    }

    /**
     * Inti task ini: penandaan berlaku per modul, bukan per repo.
     *
     * Dua modul palsu yang isinya identik sampai ke barisnya, beda hanya pada nama folder dan
     * pada apakah namanya terdaftar. Kalau penandaannya bocor, keduanya akan lolos.
     */
    public function test_penandaan_melonggarkan_modul_yang_ditandai_saja(): void
    {
        [$pemindai, $ditandai, $tanpaTanda] = $this->duaModulPalsuYangMelanggar();
        $dipindah = $this->daftarBerisi($ditandai);

        $pelanggaranTanpaTanda = [];
        $pelanggaranDitandai = [];

        foreach ($pemindai->folderModul() as $nama => $folder) {
            $temuan = array_merge(
                $pemindai->pelanggaranNamespace($folder),
                $pemindai->pelanggaranKelasCore($folder),
                $pemindai->pelanggaranQueryMentah($folder),
            );

            if ($dipindah->menandai($nama)) {
                $pelanggaranDitandai = $temuan;

                continue;
            }

            $pelanggaranTanpaTanda = $temuan;
        }

        $this->assertSame(
            [
                sprintf('modules/apperp/%s/src/Pelanggar.php menyebut Apperp\\ContohB', $tanpaTanda),
                sprintf('modules/apperp/%s/src/Pelanggar.php menyebut App\\Http\\Controllers', $tanpaTanda),
                sprintf('modules/apperp/%s/src/Pelanggar.php menyebut App\\Models\\Tenant', $tanpaTanda),
                sprintf('modules/apperp/%s/src/Pelanggar.php memakai DB::table(', $tanpaTanda),
            ],
            $pelanggaranTanpaTanda,
            'Modul yang tidak ditandai harus tetap merah pada pelanggaran yang sama persis.',
        );

        $this->assertNotSame(
            [],
            $pelanggaranDitandai,
            'Modul yang ditandai tetap dipindai penuh; pengecualian ini pembalik, bukan pelewat.',
        );

        // Yang membuktikan kelonggaran itu benar-benar dipakai penjaga: modul yang ditandai
        // dilewati saat penjaganya merakit daftar pelanggaran, sedangkan yang tidak ditandai
        // tidak. Bandingkan dengan potongan yang sama di ketiga penjaga.
        $dirakitPenjaga = [];

        foreach ($pemindai->folderModul() as $nama => $folder) {
            if ($dipindah->menandai($nama)) {
                continue;
            }

            $dirakitPenjaga = array_merge($dirakitPenjaga, $pemindai->pelanggaranBerkas($folder));
        }

        $this->assertSame($pelanggaranTanpaTanda, $dirakitPenjaga);
    }

    /**
     * Penjaga tabel: modul yang ditandai tidak dijalankan sama sekali.
     *
     * Dibuktikan pada daftar yang dirakit penjaga itu, bukan dengan menjalankan migration-nya.
     * Menjalankannya justru hal yang harus dihindari — itu sebabnya pengecualiannya ada.
     */
    public function test_penjaga_tabel_melewatkan_modul_yang_ditandai_dan_tetap_menjalankan_yang_lain(): void
    {
        [$pemindai, $ditandai, $tanpaTanda] = $this->duaModulPalsuYangMelanggar();
        $dipindah = $this->daftarBerisi($ditandai);

        $dijalankan = array_column($pemindai->modulDenganMigration($dipindah), 'nama');

        $this->assertSame([$tanpaTanda], $dijalankan, implode("\n", [
            'Modul yang ditandai harus hilang dari daftar yang dijalankan penjaga tabel,',
            'dan modul yang tidak ditandai harus tetap ada di sana.',
        ]));

        $tanpaDaftar = array_column($pemindai->modulDenganMigration($this->daftarBerisi('modul-yang-tidak-ada')), 'nama');
        sort($tanpaDaftar);

        $harapan = [$ditandai, $tanpaTanda];
        sort($harapan);

        $this->assertSame($harapan, $tanpaDaftar, 'Tanpa penandaan, keduanya dijalankan; jadi yang membedakan memang penandaannya.');
    }

    /**
     * Pemeriksaan basi diuji pada modul palsu yang memang sudah bersih.
     *
     * Tanpa test ini, pemeriksaan basi baru diketahui bekerja pada saat ia pertama kali harus
     * bekerja — yaitu berbulan-bulan dari sekarang, saat modul aslinya selesai dibentuk ulang.
     */
    public function test_pengecualian_gagal_ketika_modulnya_sudah_bersih(): void
    {
        $akar = $this->akarSementaraBaru();
        $bersih = 'pindah-bersih-'.bin2hex(random_bytes(4));

        $this->buatModulPalsu($akar, $bersih, melanggar: false);

        $pemindai = new PemindaiModul($akar.'/modules');

        $this->assertSame(
            [$bersih],
            $this->entriBasi($pemindai, $this->daftarBerisi($bersih)),
            'Modul yang dikecualikan tetapi sudah tidak melanggar apa pun harus dilaporkan sebagai entri basi.',
        );
    }

    public function test_entri_yang_modulnya_belum_mendarat_belum_bisa_dinilai_basi(): void
    {
        $akar = $this->akarSementaraBaru();
        mkdir($akar.'/modules/apperp', 0o777, true);

        $pemindai = new PemindaiModul($akar.'/modules');

        // Entri boleh — dan memang harus — didaftarkan sebelum subtree-nya mendarat, karena
        // justru pull request pemindahan itulah yang butuh kelonggarannya. Selama foldernya
        // belum ada, tidak ada yang bisa dipindai, jadi ia tidak boleh dinyatakan bersih.
        // Pada rentang itu, tenggat adalah satu-satunya yang mengakhirinya.
        $this->assertSame([], $this->entriBasi($pemindai, $this->daftarBerisi('belum-mendarat')));
    }

    /**
     * Entri yang modulnya ada di disk tetapi sudah tidak melanggar apa pun.
     *
     * @return list<string>
     */
    private function entriBasi(PemindaiModul $pemindai, ModulSedangDipindah $dipindah): array
    {
        $folderModul = $pemindai->folderModul();
        $basi = [];

        foreach (array_keys($dipindah->semua()) as $nama) {
            if (! isset($folderModul[$nama])) {
                continue;
            }

            if ($pemindai->pelanggaranBerkas($folderModul[$nama]) === []) {
                $basi[] = $nama;
            }
        }

        return $basi;
    }

    /**
     * Dua modul palsu yang isinya identik, satu akan ditandai dan satu tidak.
     *
     * @return array{0: PemindaiModul, 1: string, 2: string}
     */
    private function duaModulPalsuYangMelanggar(): array
    {
        $akar = $this->akarSementaraBaru();

        // Nama diacak per jalan. Nama tetap akan bertabrakan bila dua jalan tumpang tindih,
        // dan tabrakan seperti itu muncul sebagai kegagalan yang tidak bisa diulang.
        $acak = bin2hex(random_bytes(4));
        $ditandai = 'pindah-ditandai-'.$acak;
        $tanpaTanda = 'pindah-tanpa-tanda-'.$acak;

        $this->buatModulPalsu($akar, $ditandai, melanggar: true);
        $this->buatModulPalsu($akar, $tanpaTanda, melanggar: true);

        return [new PemindaiModul($akar.'/modules'), $ditandai, $tanpaTanda];
    }

    private function daftarBerisi(string $namaFolder): ModulSedangDipindah
    {
        return ModulSedangDipindah::buatan([
            $namaFolder => [
                'alasan' => 'Modul palsu milik test ini.',
                'tenggat' => '2999-12-31',
            ],
        ]);
    }

    private function akarSementaraBaru(): string
    {
        $akar = rtrim(sys_get_temp_dir(), '/\\').'/coreerp-penjaga-'.bin2hex(random_bytes(6));
        mkdir($akar, 0o777, true);
        $this->akarSementara = $akar;

        return $akar;
    }

    /**
     * Modul palsu yang bentuknya cukup untuk dikenali ketiga penjaga.
     *
     * Yang melanggar dibuat menyerupai modul yang belum dibentuk ulang: namespace `App\`,
     * menyentuh model Core langsung, dan memakai query builder mentah — persis tiga hal yang
     * diukur pada repo aset.
     */
    private function buatModulPalsu(string $akar, string $nama, bool $melanggar): void
    {
        $folder = $akar.'/modules/apperp/'.$nama;
        mkdir($folder.'/src', 0o777, true);
        mkdir($folder.'/database/migrations', 0o777, true);

        file_put_contents($folder.'/composer.json', json_encode([
            'name' => 'apperp/'.$nama,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Modul yang sedang dipindah belum tentu menyatakan table_prefix — repo aset memang
        // tidak. Manifest palsu ini ikut menghilangkannya supaya bentuk yang diuji sama.
        file_put_contents($folder.'/app.yaml', implode("\n", array_filter([
            'id: '.$nama,
            'publisher: apperp',
            $melanggar ? null : 'table_prefix: '.str_replace('-', '_', $nama).'_',
        ]))."\n");

        file_put_contents(
            $folder.'/database/migrations/2026_01_01_000000_buat_tabel.php',
            "<?php\n\n// Isi tidak dijalankan test ini; yang diuji hanya daftar modulnya.\n",
        );

        file_put_contents(
            $folder.'/src/'.($melanggar ? 'Pelanggar' : 'Barang').'.php',
            $melanggar ? $this->berkasMelanggar() : $this->berkasBersih($nama),
        );
    }

    private function berkasMelanggar(): string
    {
        return <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use App\Models\Tenant;
        use Illuminate\Support\Facades\DB;
        use Modules\Apperp\ContohB\Models\Rak;

        class Pelanggar
        {
            public function jalan(Tenant $tenant, Rak $rak): void
            {
                DB::table('barang')->get();
            }
        }
        PHP;
    }

    private function berkasBersih(string $nama): string
    {
        $namespace = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $nama)));

        return <<<PHP
        <?php

        namespace Modules\\Apperp\\{$namespace}\\Models;

        class Barang
        {
            public function kode(): string
            {
                return 'BRG';
            }
        }
        PHP;
    }

    private function hapusFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($folder);
    }
}
