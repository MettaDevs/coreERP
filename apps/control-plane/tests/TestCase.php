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

    /**
     * Database test tidak boleh sama dengan database kerja.
     *
     * Diperiksa di `setUpTraits()` dan bukan di `setUp()`, karena `.env` baru dimuat ketika
     * aplikasinya berdiri — dan aplikasinya berdiri **di dalam** `parent::setUp()`. Percobaan
     * pertama membaca `getenv()` di sana, dan kedua nilainya selalu kosong: penjaganya tidak pernah
     * dapat menyala. Penjaga yang tidak dapat merah lebih buruk daripada tidak ada, karena ia
     * mengakhiri pencarian.
     *
     * `setUpTraits()` dipanggil sesudah `config()` menjawab yang sebenarnya, dan sebelum trait
     * penyiap database menyentuh satu baris pun — keduanya justru dipasang oleh method ini.
     *
     * Suite ini membangun ulang seluruh skema Core lewat `migrate:fresh`, jadi ia yang paling
     * merusak kalau salah alamat.
     *
     * @return array<string, string> Trait yang dipakai kelas test ini, seperti yang dipulangkan Laravel.
     */
    protected function setUpTraits(): array
    {
        $bawaan = (string) config('database.default');
        $databaseUji = (string) config('database.connections.'.$bawaan.'.database');
        $databaseKerja = (string) config('database.connections.pgsql.database');

        if ($databaseKerja !== '' && $databaseUji === $databaseKerja) {
            $this->fail(
                'Koneksi test menunjuk database "'.$databaseUji.'", yang sama dengan database kerja. '
                .'Suite ini menjalankan migrate:fresh, jadi ia akan membuang seluruh tabel database '
                .'yang sedang dipakai bekerja. Buat database terpisah lalu setel DB_TEST_DATABASE.'
            );
        }

        return parent::setUpTraits();
    }
}
