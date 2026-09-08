<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

use App\Support\Modules\TenantScope;
use RuntimeException;

/**
 * Menandai sebuah model module sebagai milik tenant.
 *
 * Dipakai begini, dan hanya itu yang perlu diketahui module:
 *
 *     final class Barang extends Model
 *     {
 *         use MilikTenant;
 *     }
 *
 * Trait ini ada supaya aturan batas bisa berbunyi satu kalimat: **module hanya boleh
 * menyebut `App\Support\Modules\Contracts`.** Sebelumnya model module menyebut `TenantScope`
 * langsung, dan itu memaksa aturannya melonggar menjadi "seluruh `App\Support\Modules`" —
 * yang berarti kelas apa pun yang kelak ditaruh di folder itu ikut boleh disentuh module,
 * tanpa ada yang menahan dan tanpa ada yang memutuskan.
 *
 * **Menjaga baca dan tulis, karena menjaga baca saja tidak cukup.** Penyaringan baca ada di
 * `TenantScope` dan tetap gagal-menutup: query tanpa tenant aktif dibatalkan, bukan
 * dijalankan tanpa saringan. Tetapi sebuah scope baca tidak pernah melihat baris yang sedang
 * ditulis. Sebelum trait ini menjaga penulisan, module bisa menyimpan baris dengan
 * `tenant_id` milik tenant lain sementara tenant aktif berbeda, dan tidak ada satu pun yang
 * menahannya — bukan `NOT NULL`, karena kolomnya terisi; bukan scope, karena scope hanya
 * menyaring `select`. Kebocoran seperti itu bahkan tidak terlihat oleh tenant yang menulis.
 *
 * Karena itu penulisan diperlakukan begini:
 *
 * - `tenant_id` yang tidak diisi **diisi sendiri** dari tenant aktif. Module tidak perlu
 *   menuliskannya, dan yang tidak ditulis tidak bisa salah tulis.
 * - `tenant_id` yang diisi berbeda dari tenant aktif **membatalkan penyimpanan**. Ini bukan
 *   sekadar kehati-hatian: nilai itu biasanya datang dari permintaan, dan nilai dari
 *   permintaan adalah cara paling wajar sebuah tenant menulis ke tenant lain.
 *
 * Akibatnya kode module menjadi lebih pendek, bukan lebih panjang. Itu disengaja: aturan yang
 * membuat pekerjaan bertambah akan dilanggar diam-diam, sedangkan aturan yang menghapus
 * pekerjaan tidak punya alasan untuk dilanggar.
 */
trait MilikTenant
{
    public static function bootMilikTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::saving(function ($model): void {
            $aktif = TenantScope::tenantAktif();
            $tertulis = $model->getAttribute('tenant_id');

            if ($tertulis === null || $tertulis === '') {
                $model->setAttribute('tenant_id', $aktif);

                return;
            }

            if ($tertulis !== $aktif) {
                throw new RuntimeException(
                    'Baris module hendak disimpan dengan tenant_id '.$tertulis.
                    ' sementara tenant aktif '.$aktif.'. Penyimpanan dibatalkan; '.
                    'menyimpannya berarti menulis ke data tenant lain.'
                );
            }
        });
    }
}
