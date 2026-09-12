<?php

declare(strict_types=1);

namespace ControlPlane\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        /*
         * Suite ini membangun skema Core dari nol lewat `migrate:fresh`, dan perintah itu
         * **membuang seluruh tabel** pada schema yang sedang aktif. Trait penyiap database yang
         * lain mengosongkannya dengan cara berbeda; akibatnya sama. Selama koneksinya `pgsql_test`
         * dengan schema tersendiri, tidak ada yang hilang. Begitu ia menunjuk `public`, perintah
         * yang sama menghapus database kerja pengembang — dan yang terlihat hanyalah suite yang
         * hijau, lalu stack lokal yang tiba-tiba kosong.
         *
         * Sudah terjadi sekali pada 12 September 2026. Pemeriksaannya berdiri sebelum
         * `parent::setUp()` karena di situlah pengosongannya berjalan, dan kerusakannya tidak
         * dapat dibatalkan.
         */
        $baca = static fn (string $kunci): string => (string) (getenv($kunci) ?: ($_ENV[$kunci] ?? ''));

        $koneksi = $baca('DB_CONNECTION');
        $jalur = $baca('DB_TEST_SCHEMA') ?: 'coreerp_test';

        // Akarnya, dan ia tidak bergantung pada schema sama sekali: selama database test dan
        // database kerja adalah database yang sama, tiap jalur yang kebetulan menunjuk `public`
        // akan mengosongkan data kerja. Suite ini membangun ulang seluruh skema Core, jadi ia
        // justru yang paling merusak kalau salah alamat.
        $databaseKerja = $baca('DB_DATABASE');
        $databaseUji = $baca('DB_TEST_DATABASE');

        if ($databaseKerja !== '' && $databaseUji === $databaseKerja) {
            $this->fail(
                'DB_TEST_DATABASE sama dengan DB_DATABASE ("'.$databaseKerja.'"). Suite ini '
                .'menjalankan migrate:fresh, jadi ia akan membuang seluruh tabel database yang '
                .'sedang dipakai bekerja. Buat database terpisah lalu setel DB_TEST_DATABASE.'
            );
        }

        if ($koneksi !== 'pgsql_test' || $jalur === 'public') {
            $this->fail(
                'Suite ini menunjuk koneksi "'.$koneksi.'" dengan schema "'.$jalur.'". '
                .'Konsol operator membangun ulang skema Core saat test, jadi ia hanya boleh '
                .'berjalan di schema test — bukan di schema kerja siapa pun.'
            );
        }

        parent::setUp();

        /*
         * Manifest Vite tidak ikut diuji di sini.
         *
         * Tanpa baris ini setiap test yang merender halaman gagal dengan "Vite manifest not found"
         * di mesin yang belum menjalankan `npm run build` — dan yang gagal bukan hal yang sedang
         * diuji. Apakah asetnya benar-benar terbangun adalah pertanyaan yang dijawab alur build,
         * bukan oleh suite ini.
         */
        $this->withoutVite();
    }
}
