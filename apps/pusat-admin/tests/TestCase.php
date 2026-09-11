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
