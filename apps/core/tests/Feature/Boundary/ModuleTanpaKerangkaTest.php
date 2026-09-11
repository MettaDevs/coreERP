<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use PHPUnit\Framework\TestCase;

/**
 * Module tidak punya kerangka aplikasi sendiri.
 *
 * Runtime-nya satu, dan yang menyalakannya Core. Sebuah module yang membawa `artisan`,
 * `bootstrap/app.php`, atau `public/index.php` miliknya sendiri berarti masih bisa dijalankan
 * sebagai aplikasi terpisah — dan selama itu masih bisa, seseorang akan menjalankannya begitu,
 * lalu heran kenapa perubahannya tidak terlihat di Core.
 *
 * Yang paling berbahaya bukan berkas kerangka itu sendiri melainkan **migration kerangka**.
 * `users`, `jobs`, `cache`, dan `failed_jobs` sudah dimiliki Core; migration module yang
 * membuatnya lagi bertabrakan pasti, dan tabrakannya muncul saat pemasangan module di tenant
 * sungguhan — bukan saat siapa pun sedang memperhatikan.
 *
 * Test ini berlaku untuk **semua** module tanpa kecuali, termasuk yang sedang dipindah masuk:
 * membuang kerangka adalah langkah pertama pemindahan, jadi tidak ada keadaan sah di mana
 * sebuah folder module boleh membawanya.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel, jadi ia memakai TestCase polos.
 */
class ModuleTanpaKerangkaTest extends TestCase
{
    /**
     * Jalur di dalam folder module yang menandakan kerangka aplikasi tersendiri.
     *
     * @var list<string>
     */
    private const KERANGKA = [
        'artisan',
        'api/artisan',
        'bootstrap/app.php',
        'api/bootstrap/app.php',
        'public/index.php',
        'api/public/index.php',
    ];

    /**
     * Tabel milik Core yang tidak boleh dibuat migration module mana pun.
     *
     * @var list<string>
     */
    private const TABEL_CORE = ['users', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'];

    public function test_tidak_ada_module_yang_membawa_kerangka_aplikasi_sendiri(): void
    {
        $pelanggaran = [];

        foreach ($this->folderModule() as $folder) {
            foreach (self::KERANGKA as $jalur) {
                if (file_exists($folder.'/'.$jalur)) {
                    $pelanggaran[] = basename($folder).'/'.$jalur;
                }
            }
        }

        $this->assertSame([], $pelanggaran, sprintf(
            "Module membawa kerangka aplikasinya sendiri: %s\n".
            'Runtime-nya satu dan Core yang menyalakannya. Selama berkas ini ada, module masih bisa '.
            'dijalankan sebagai aplikasi terpisah, dan perubahan yang dibuat di sana tidak akan '.
            'terlihat di Core.',
            implode(', ', $pelanggaran),
        ));
    }

    public function test_tidak_ada_migration_module_yang_membuat_tabel_milik_core(): void
    {
        $berkasDiperiksa = 0;
        $pelanggaran = [];

        foreach ($this->folderModule() as $folder) {
            foreach ($this->berkasMigrasi($folder) as $berkas) {
                $berkasDiperiksa++;
                $isi = (string) file_get_contents($berkas);

                foreach (self::TABEL_CORE as $tabel) {
                    if (preg_match('/Schema::create\(\s*[\'"]'.preg_quote($tabel, '/').'[\'"]/', $isi) === 1) {
                        $pelanggaran[] = basename($folder).'/'.basename($berkas).' → '.$tabel;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $berkasDiperiksa, 'Tidak satu pun migration module terbaca; pemindaiannya salah alamat.');

        $this->assertSame([], $pelanggaran, sprintf(
            "Migration module membuat tabel milik Core: %s\n".
            'Tabel itu sudah ada sebelum module dipasang, jadi tabrakannya pasti — dan ia muncul '.
            'saat pemasangan module di tenant sungguhan, bukan saat ada yang memperhatikan.',
            implode(', ', $pelanggaran),
        ));
    }

    /** @return list<string> */
    private function folderModule(): array
    {
        $pola = dirname(__DIR__, 5).'/modules/*/*';
        $folder = glob($pola, GLOB_ONLYDIR);

        $folder = $folder === false ? [] : $folder;

        $this->assertNotSame([], $folder, 'Tidak ada folder module yang terbaca; pemindaiannya salah alamat.');

        return $folder;
    }

    /** @return list<string> */
    private function berkasMigrasi(string $folder): array
    {
        $ditemukan = [];

        foreach (['database/migrations', 'api/database/migrations'] as $anak) {
            $berkas = glob($folder.'/'.$anak.'/*.php');

            if ($berkas !== false) {
                $ditemukan = [...$ditemukan, ...$berkas];
            }
        }

        return $ditemukan;
    }
}
