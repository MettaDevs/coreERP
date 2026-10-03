<?php

declare(strict_types=1);

namespace App\Platform\Environment\Listeners;

use App\Platform\ChangeLog\Support\AuditActor;
use App\Platform\ChangeLog\Support\ChangeLogSwitch;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Pendengar Octane di akhir setiap permintaan, task, dan tick: koneksi database yang hidup selama umur
 * worker dikembalikan ke keadaan yang sama dengan koneksi baru. Didaftarkan di `config/octane.php`.
 *
 * Di Apache setiap permintaan membuka koneksi baru, jadi tidak ada yang perlu dibereskan. Di worker
 * Octane koneksinya dipakai ulang, dan tiga hal ikut terbawa ke permintaan berikutnya — yang bisa saja
 * milik tenant lain atau tanpa pengguna sama sekali:
 *
 * - **Variabel sesi PostgreSQL.** Pelaku kolom jejak ({@see AuditActor}) dipasang tiap kali guard memegang
 *   pengguna dan baru dilepas saat logout; tanpa pelepasan ini pendaftaran tamu berikutnya tercatat
 *   dibuat oleh pengguna permintaan sebelumnya (diuji 3 Oktober 2026 dengan satu worker: `created_by_user_id`
 *   baris tenant tamu berisi id pengguna tenant lain). Saklar log perubahan ({@see ChangeLogSwitch}) yang
 *   dimatikan migration dan tidak dinyalakan lagi karena migration-nya gagal mematikan log perubahan
 *   untuk ratusan permintaan berikutnya.
 * - **Koneksi database environment** (`environment_<id>`). Satu worker melayani banyak environment; tanpa
 *   ditutup, setiap environment yang pernah dilayani menyisakan satu koneksi menganggur per worker sampai
 *   PostgreSQL kehabisan slot koneksi. Ditutup di sini, dan disambungkan lagi oleh permintaan berikutnya
 *   ke alamat itu — sama seperti di Apache.
 * - **Transaksi yang tertinggal terbuka.** Koneksinya ditutup, bukan di-rollback: menutup koneksi membuang
 *   transaksi sekaligus seluruh variabel sesinya, tanpa bergantung pada koneksi yang mungkin sudah rusak.
 *
 * Koneksi yang belum benar-benar tersambung dilewati, supaya `/up` tidak membayar koneksi database.
 */
final class ResetDatabaseConnections
{
    public function handle(object $event): void
    {
        foreach (DB::getConnections() as $name => $connection) {
            if (! $connection->getRawPdo() instanceof PDO) {
                continue;
            }

            if (str_starts_with((string) $name, 'environment_') || $connection->transactionLevel() > 0) {
                DB::purge($name);

                continue;
            }

            $this->resetSessionSettings($name, $connection);
        }
    }

    private function resetSessionSettings(string $name, Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            $connection->select('select set_config(?, ?, false), set_config(?, ?, false)', [
                AuditActor::SETTING, '',
                ChangeLogSwitch::SETTING, '',
            ]);
        } catch (Throwable) {
            // Koneksi yang tidak dapat dibereskan tidak boleh dipakai ulang dengan keadaan lamanya.
            DB::purge($name);
        }
    }
}
