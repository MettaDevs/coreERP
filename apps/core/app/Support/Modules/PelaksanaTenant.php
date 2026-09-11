<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use Illuminate\Contracts\Container\Container;

/**
 * Satu-satunya tempat tenant aktif diganti untuk sementara.
 *
 * Pola ikat-lalu-pulihkan ini sempat berdiri sendiri di empat tempat — penyemai module,
 * pemancar event ke module, pembaca laporan module, dan perintah artisan module. Empat
 * salinan satu aturan adalah empat salinan yang akan menyimpang, dan menyimpangnya tidak
 * berisik: yang lupa memulihkan meninggalkan tenant sebelumnya menempel pada pekerjaan
 * berikutnya, dan pekerjaan itu berjalan mulus di tenant yang salah.
 */
final class PelaksanaTenant implements PelaksanaUntukTenant
{
    public function __construct(private readonly Container $wadah) {}

    /**
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     */
    public function jalankanUntuk(string $tenantId, callable $aksi): mixed
    {
        // Ikatan sebelumnya dipersempit ke `string|null`, lalu dipulihkan lewat `instance()`
        // juga ketika sebelumnya memang tidak ada — antarmuka container tidak punya cara
        // melupakan sebuah ikatan. Keduanya bersandar pada aturan yang sama: `TenantScope`
        // menolak apa pun yang bukan string berisi, jadi null dan nilai bertipe lain sama-sama
        // berarti "tidak ada tenant aktif". Menyimpan nilai asing kembali apa adanya hanya
        // memindahkannya ke pekerjaan berikutnya.
        $terikat = $this->wadah->bound(TenantScope::KUNCI) ? $this->wadah->make(TenantScope::KUNCI) : null;
        $sebelumnya = is_string($terikat) ? $terikat : null;
        $this->wadah->instance(TenantScope::KUNCI, $tenantId);

        try {
            return $aksi();
        } finally {
            $this->wadah->instance(TenantScope::KUNCI, $sebelumnya);
        }
    }
}
