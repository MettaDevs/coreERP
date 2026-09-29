<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Mematikan log perubahan di satu koneksi selama migration dan upgrade versi, seperti BC yang tidak
 * mencatat Change Log saat upgrade: perubahan skema dan data migration bukan perubahan oleh pengguna,
 * dan mencatatnya hanya membanjiri log.
 *
 * Nilainya variabel sesi PostgreSQL `coreerp.change_log`, dibaca trigger `coreerp_log_change`.
 */
final class ChangeLogSwitch
{
    public const SETTING = 'coreerp.change_log';

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function pausedOn(?string $connection, callable $callback): mixed
    {
        self::pause($connection);

        try {
            return $callback();
        } finally {
            self::resume($connection);
        }
    }

    public static function pause(?string $connection = null): void
    {
        self::set($connection, 'off');
    }

    public static function resume(?string $connection = null): void
    {
        self::set($connection, '');
    }

    private static function set(?string $connection, string $value): void
    {
        $db = DB::connection($connection);
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }

        $db->select('select set_config(?, ?, false)', [self::SETTING, $value]);
    }
}
