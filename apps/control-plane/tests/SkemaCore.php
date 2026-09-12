<?php

declare(strict_types=1);

namespace ControlPlane\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Skema yang diuji dibangun dari folder migration Core, satu-satunya sumbernya.
 *
 * Alternatifnya — menumpang skema yang sudah disiapkan suite Core — membuat suite ini diam-diam
 * bergantung pada urutan: hijau di mesin yang baru saja menjalankan Core, merah di mesin yang belum,
 * dan menguji skema basi di antara keduanya. Konsol ini memang menulis ke tabel milik aplikasi lain;
 * justru karena itu ia harus membuktikan dirinya terhadap definisi tabel yang sedang berlaku, bukan
 * terhadap salinan yang kebetulan tertinggal.
 */
trait SkemaCore
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function migrateFreshUsing(): array
    {
        $folder = realpath(__DIR__.'/../../core/database/migrations');

        if ($folder === false) {
            $this->fail('Folder migration Core tidak ditemukan. Konsol ini tidak punya skema sendiri untuk diuji.');
        }

        return [
            '--drop-views' => false,
            '--drop-types' => true,
            '--path' => [$folder],
            '--realpath' => true,
        ];
    }
}
