<?php

namespace App\Support\Database;

use App\Support\Modules\Contracts\AuditColumns;
use Illuminate\Support\Facades\DB;

/**
 * Pengguna yang tercatat sebagai pelaku penulisan di koneksi database aktif.
 *
 * Nilainya variabel sesi PostgreSQL `coreerp.user_id`, dibaca trigger kolom jejak
 * ({@see AuditColumns}) dan kelak trigger log perubahan. Dipasang saat pengguna terpasang di guard
 * (event `Authenticated`), sehingga berlaku untuk halaman, API module, dan `actingAs` di test.
 * Koneksi yang dipakai adalah koneksi bawaan, yang sudah dialihkan `ResolveEnvironment` ke database
 * tenant sebelum sesi dan autentikasi berjalan.
 *
 * Nilainya tingkat sesi (`is_local = false`), bukan per transaksi, karena kebanyakan penulisan tidak
 * dibungkus transaksi. Akibatnya proses yang hidup lama, seperti worker antrean, wajib memakai
 * {@see self::runAs()} supaya pelaku satu job tidak terbawa ke job berikutnya.
 */
final class AuditActor
{
    public const SETTING = 'coreerp.user_id';

    public static function set(int|string|null $userId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Hanya id angka yang dipasang. Trigger mengubahnya ke bigint, dan nilai lain — misalnya id ULID dari
        // guard yang kelak ditambahkan — akan menggagalkan setiap penulisan, bukan hanya jejaknya.
        $value = is_int($userId) || (is_string($userId) && ctype_digit($userId)) ? (string) $userId : '';

        DB::select('select set_config(?, ?, false)', [self::SETTING, $value]);
    }

    public static function clear(): void
    {
        self::set(null);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runAs(int|string|null $userId, callable $callback): mixed
    {
        self::set($userId);

        try {
            return $callback();
        } finally {
            self::clear();
        }
    }
}
