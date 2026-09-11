<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use PHPUnit\Framework\TestCase;

/**
 * Rute module hanya boleh memakai middleware yang benar-benar terdaftar di Core.
 *
 * Alias middleware yang tidak terdaftar bukan kesalahan yang terlihat saat menulis rute; ia
 * baru muncul saat rutenya dipanggil, sebagai kegagalan 500 di tangan pengguna. Dan selama
 * berkas rutenya belum dimuat siapa pun — keadaan module yang sedang dipindah — tidak ada satu
 * pun yang memberi tahu bahwa aliasnya sudah lenyap.
 *
 * Itu bukan bahaya hipotetis: module aset membawa alias `coreerp` dan `coreerp-event` yang
 * dulu didaftarkan `bootstrap/app.php` miliknya sendiri, dan berkas itu dihapus pada F3-02.
 * Sejak saat itu sampai penjaga ini dibuat, rutenya menunjuk alias yang tidak ada di mana pun.
 *
 * Pengecualian harus ditulis di sini beserta alasan dan task yang membereskannya, sehingga
 * "sengaja belum" bisa dibedakan dari "terlupakan".
 */
class RuteModuleTerlindungiTest extends TestCase
{
    /**
     * Alias yang sengaja dibiarkan menunjuk ke tempat yang tidak terdaftar.
     *
     * @var array<string, string>
     */
    private const SENGAJA_BELUM = [];

    /**
     * Grup dan alias bawaan Laravel, yang tidak perlu didaftarkan siapa pun.
     *
     * @var list<string>
     */
    private const BAWAAN_LARAVEL = [
        'web', 'api', 'auth', 'auth.basic', 'auth.session', 'guest', 'verified', 'signed',
        'throttle', 'can', 'password.confirm', 'precognitive', 'cache.headers', 'subscribed',
    ];

    public function test_alias_middleware_rute_module_terdaftar_di_core(): void
    {
        $terdaftar = $this->aliasTerdaftar();
        $this->assertNotSame([], $terdaftar, 'Tidak satu pun alias middleware Core terbaca; pemindaiannya salah alamat.');

        $diperiksa = 0;
        $pelanggaran = [];

        foreach ($this->berkasRuteModule() as $modul => $berkas) {
            $isi = (string) file_get_contents($berkas);

            preg_match_all("/middleware\(\s*\[?\s*'([^']+)'/", $isi, $cocok);

            foreach ($cocok[1] as $alias) {
                $diperiksa++;
                $nama = explode(':', $alias)[0];

                if (in_array($nama, $terdaftar, true)
                    || in_array($nama, self::BAWAAN_LARAVEL, true)
                    || array_key_exists($nama, self::SENGAJA_BELUM)) {
                    continue;
                }

                $pelanggaran[] = sprintf('%s (%s) memakai middleware "%s"', $modul, basename($berkas), $nama);
            }
        }

        $this->assertGreaterThan(0, $diperiksa, 'Tidak satu pun middleware pada rute module terbaca; pemindaiannya salah alamat.');

        $this->assertSame([], $pelanggaran, sprintf(
            "Rute module memakai middleware yang tidak terdaftar di Core:\n- %s\n".
            'Alias yang tidak terdaftar gagal saat rutenya dipanggil, bukan saat ditulis. Daftarkan '.
            'aliasnya di bootstrap/app.php, atau catat di SENGAJA_BELUM beserta task yang membereskannya.',
            implode("\n- ", $pelanggaran),
        ));
    }

    /**
     * Alias middleware yang didaftarkan Core.
     *
     * @return list<string>
     */
    private function aliasTerdaftar(): array
    {
        $isi = (string) file_get_contents(dirname(__DIR__, 3).'/bootstrap/app.php');

        preg_match_all("/'([a-z0-9\-\.]+)'\s*=>\s*[A-Za-z]+::class/", $isi, $cocok);
        $terdaftar = $cocok[1];

        // Module boleh mendaftarkan aliasnya sendiri lewat penyedia layanannya, dan memang itu
        // rumah yang benar: alias yang hanya dipakai satu module tidak perlu diketahui Core.
        // Penjaga ini karena itu ikut membaca penyedia layanan module, bukan hanya Core.
        $penyedia = glob(dirname(__DIR__, 5).'/modules/*/*/src/ModuleServiceProvider.php');

        foreach ($penyedia === false ? [] : $penyedia as $berkas) {
            preg_match_all("/aliasMiddleware\(\s*'([^']+)'/", (string) file_get_contents($berkas), $cocokModule);
            $terdaftar = [...$terdaftar, ...$cocokModule[1]];
        }

        return array_values(array_unique($terdaftar));
    }

    /**
     * Berkas rute tiap module.
     *
     * @return array<string, string>
     */
    private function berkasRuteModule(): array
    {
        $berkas = glob(dirname(__DIR__, 5).'/modules/*/*/routes/*.php');
        $berkas = $berkas === false ? [] : $berkas;

        $this->assertNotSame([], $berkas, 'Tidak ada berkas rute module yang terbaca; pemindaiannya salah alamat.');

        $hasil = [];

        foreach ($berkas as $b) {
            $hasil[basename(dirname($b, 2)).'/'.basename($b)] = $b;
        }

        return $hasil;
    }
}
