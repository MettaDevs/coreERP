<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\ModulTanpaAnalisaTipe;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Penjaga atas pengecualian analisa tipe.
 *
 * Daftar ini dipisahkan dari `ModulSedangDipindah` pada 9 September 2026, dan pemisahan itu
 * membawa risikonya sendiri: pengecualian yang berdiri sendiri lebih mudah dilupakan daripada
 * pengecualian yang menumpang daftar yang setiap hari dilihat orang. Berkas ini yang
 * menahannya — dua cara berakhir, dan keduanya dibuktikan bisa merah.
 */
class ModulTanpaAnalisaTipeTest extends TestCase
{
    /**
     * Cara berakhir pertama: tenggat.
     */
    public function test_tenggat_tiap_entri_belum_lewat(): void
    {
        $lewat = ModulTanpaAnalisaTipe::bawaan()->tenggatYangLewat(new DateTimeImmutable('today'));

        $this->assertSame([], $lewat, implode("\n", [
            'Tenggat pengecualian analisa tipe sudah lewat: '.implode(', ', $lewat).'.',
            'Bereskan temuannya lalu buang entrinya, atau perpanjang tenggatnya dengan alasan',
            'yang ditulis. Yang tidak boleh: membiarkannya lewat tanpa keputusan, karena sejak',
            'saat itu ia berhenti menjadi pengecualian dan menjadi pelonggaran permanen.',
        ]));
    }

    /**
     * Tenggat yang lewat benar-benar membuat alur merah.
     *
     * Tanpa test ini, `tenggatYangLewat()` bisa saja selalu memulangkan daftar kosong dan
     * penjaga di atas hijau selamanya tanpa pernah menguji apa pun.
     */
    public function test_tenggat_yang_lewat_terdeteksi(): void
    {
        $daftar = ModulTanpaAnalisaTipe::dariDaftar([
            'modul-uji' => ['alasan' => 'Bahan uji.', 'tenggat' => '2020-01-01'],
        ]);

        $this->assertSame(['modul-uji'], $daftar->tenggatYangLewat(new DateTimeImmutable('today')));
    }

    /**
     * Cara berakhir kedua: berkas setelan dan daftar ini tidak boleh menyimpang.
     *
     * Yang dijaga bukan kerapian. Entri yang tertinggal di `phpstan.neon` setelah dibuang dari
     * sini membuat modulnya lolos analisa selamanya, dan tidak ada yang gagal karenanya —
     * kegagalan diam yang persis sama seperti yang dijaga `ModulSedangDipindahTest` untuk
     * pengecualian lainnya.
     */
    public function test_pengecualian_phpstan_sama_dengan_daftar(): void
    {
        $berkas = dirname(__DIR__, 3).'/phpstan.neon';
        $this->assertFileExists($berkas);

        $isi = (string) file_get_contents($berkas);
        $mulai = strpos($isi, 'excludePaths');
        $this->assertNotFalse($mulai, 'phpstan.neon tidak lagi punya blok excludePaths.');

        preg_match_all(
            '#\.\./\.\./modules/[^/\s]+/([^/\s]+)#',
            substr($isi, $mulai),
            $cocok,
        );

        $diBerkas = array_values(array_unique($cocok[1]));
        sort($diBerkas);

        $this->assertSame(
            ModulTanpaAnalisaTipe::bawaan()->namaFolder(),
            $diBerkas,
            implode("\n", [
                'Daftar module yang dikecualikan analisa tipe (phpstan.neon) tidak sama dengan',
                'ModulTanpaAnalisaTipe. Yang kurang membuat alur merah pada modul yang memang',
                'belum siap dianalisa. Yang berlebih membiarkan modul yang sudah selesai lolos',
                'pemeriksaan selamanya — dan itu tidak terlihat siapa pun, karena tidak ada yang',
                'gagal.',
            ]),
        );
    }

    /**
     * Tiap entri menyebut alasan dan tenggat, dan alasannya menyebut angka terukur.
     *
     * Alasan tanpa angka tidak bisa ditinjau: "masih banyak temuan" benar selamanya, sedangkan
     * "405 temuan di 86 berkas, diukur 9 September 2026" bisa diperiksa ulang siapa pun.
     */
    public function test_tiap_entri_menyebut_alasan_terukur_dan_tenggat(): void
    {
        foreach (ModulTanpaAnalisaTipe::bawaan()->semua() as $nama => $entri) {
            $this->assertNotSame('', trim($entri['alasan']), sprintf(
                'Entri "%s" tidak menyebut alasan. Pengecualian tanpa alasan tidak bisa ditinjau, hanya bisa diwarisi.',
                $nama,
            ));

            $this->assertMatchesRegularExpression('/\d/', $entri['alasan'], sprintf(
                'Alasan entri "%s" tidak menyebut satu pun angka. Sebut jumlah temuan dan kapan diukur, '.
                'supaya orang berikutnya dapat memeriksa apakah keadaannya masih sama.',
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
}
