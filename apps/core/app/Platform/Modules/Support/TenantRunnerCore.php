<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use App\Platform\Modules\Contracts\TenantRunner;
use Illuminate\Container\Container;

/**
 * Satu-satunya tempat tenant aktif diganti untuk sementara.
 *
 * Pola ikat-lalu-pulihkan ini sempat berdiri sendiri di empat tempat — penyemai module,
 * pemancar event ke module, pembaca laporan module, dan perintah artisan module. Empat
 * salinan satu aturan adalah empat salinan yang akan menyimpang, dan menyimpangnya tidak
 * berisik: yang lupa memulihkan meninggalkan tenant sebelumnya menempel pada pekerjaan
 * berikutnya, dan pekerjaan itu berjalan mulus di tenant yang salah.
 *
 * Container diambil saat dipanggil, bukan disuntikkan, supaya selalu container yang sama dengan yang
 * dibaca {@see TenantScope::activeTenant()} lewat `app()`. Di Octane keduanya berbeda: registry yang
 * dibangun saat boot memegang container induk, sedangkan permintaan berjalan di salinannya. Ikatan yang
 * ditulis ke container induk tidak pernah terbaca, dan query module berhenti dengan "tanpa tenant aktif".
 */
final class TenantRunnerCore implements TenantRunner
{
    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public function runFor(string $tenantId, callable $action): mixed
    {
        // Ikatan sebelumnya dipersempit ke `string|null`, lalu dipulihkan lewat `instance()`
        // juga ketika sebelumnya memang tidak ada — antarmuka container tidak punya cara
        // melupakan sebuah ikatan. Keduanya bersandar pada aturan yang sama: `TenantScope`
        // menolak apa pun yang bukan string berisi, jadi null dan nilai bertipe lain sama-sama
        // berarti "tidak ada tenant aktif". Menyimpan nilai asing kembali apa adanya hanya
        // memindahkannya ke pekerjaan berikutnya.
        $container = Container::getInstance();
        $bound = $container->bound(TenantScope::KEY) ? $container->make(TenantScope::KEY) : null;
        $previous = is_string($bound) ? $bound : null;
        $container->instance(TenantScope::KEY, $tenantId);

        try {
            return $action();
        } finally {
            $container->instance(TenantScope::KEY, $previous);
        }
    }
}
