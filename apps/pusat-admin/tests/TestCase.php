<?php

declare(strict_types=1);

namespace PusatAdmin\Tests;

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
         * **membuang seluruh tabel** pada schema yang sedang aktif. Selama koneksinya `pgsql_test`
         * dengan schema tersendiri, tidak ada yang hilang. Begitu ia menunjuk `public`, perintah
         * yang sama menghapus database kerja pengembang — dan yang terlihat hanyalah suite yang
         * hijau, lalu stack lokal yang tiba-tiba kosong.
         *
         * Sudah terjadi sekali pada 12 September 2026. Pemeriksaannya berdiri sebelum
         * `parent::setUp()` karena di situlah `migrate:fresh` berjalan, dan kerusakannya tidak
         * dapat dibatalkan.
         */
        $koneksi = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? ''));
        $jalur = (string) (getenv('DB_TEST_SCHEMA') ?: ($_ENV['DB_TEST_SCHEMA'] ?? 'coreerp_test'));

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
