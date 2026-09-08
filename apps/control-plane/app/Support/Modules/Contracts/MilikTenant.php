<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

use App\Support\Modules\TenantScope;

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
 * Penyaringannya sendiri tetap di `TenantScope`, dan tetap **gagal menutup**: query yang
 * berjalan tanpa tenant aktif dibatalkan, bukan dijalankan tanpa saringan.
 */
trait MilikTenant
{
    public static function bootMilikTenant(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
