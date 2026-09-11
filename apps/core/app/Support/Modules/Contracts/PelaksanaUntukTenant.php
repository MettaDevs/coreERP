<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Menjalankan sepotong pekerjaan module dengan tenant tertentu sebagai tenant aktif.
 *
 * Model module menyaring lewat tenant aktif, dan penyaringan itu **gagal menutup**: tanpa
 * tenant aktif, query dibatalkan, bukan dijalankan tanpa saringan. Pada permintaan HTTP
 * tenant itu diikat middleware konteks module, jadi tidak ada yang perlu memikirkannya.
 *
 * Yang tidak lewat permintaan HTTP tidak punya middleware itu: perintah artisan, pekerja
 * antrean, listener event Core, dan test yang memanggil layanan module secara langsung. Di
 * sana tenant harus disebut, dan antarmuka ini satu-satunya cara module boleh menyebutnya —
 * batasnya berbunyi module hanya boleh menyentuh `App\Support\Modules\Contracts`, dan kelas
 * yang menyimpan tenant aktif tidak ada di dalamnya.
 *
 * Dipakai begini:
 *
 *     $pelaksana->jalankanUntuk($tenantId, fn () => $penyedia->forTenant($tenantId));
 *
 * **Tenant sebelumnya dikembalikan setelah selesai, termasuk bila pekerjaannya melempar.**
 * Itu bukan kerapian: satu perintah yang memproses dua tenant berturut-turut akan memakai
 * tenant pertama untuk keduanya kalau ikatannya tidak dipulihkan, dan tidak ada yang gagal
 * karenanya — hasilnya hanya data yang mendarat di tenant yang salah.
 */
interface PelaksanaUntukTenant
{
    /**
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     */
    public function jalankanUntuk(string $tenantId, callable $aksi): mixed;
}
