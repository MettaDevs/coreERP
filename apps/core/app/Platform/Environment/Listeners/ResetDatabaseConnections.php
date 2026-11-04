<?php

declare(strict_types=1);

namespace App\Platform\Environment\Listeners;

use App\Platform\ChangeLog\Support\AuditActor;
use App\Platform\ChangeLog\Support\ChangeLogSwitch;
use App\Platform\Environment\Support\EnvironmentConnection;
use Illuminate\Database\Connection;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
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
    /** @var array<int, array{depth: int, connections: array<string, array{level: int, actor: string, change_log: string}>}> */
    private array $boundaries = [];

    public function begin(JobProcessing $event): void
    {
        if ($event->job instanceof SyncJob) {
            return;
        }
        $depth = app(EnvironmentConnection::class)->depth();
        $connections = [];
        foreach (DB::getConnections() as $name => $connection) {
            $level = $connection->transactionLevel();
            $actor = $changeLog = '';
            if (($level > 0 || $depth > 0) && $connection->getDriverName() === 'pgsql' && $connection->getRawPdo() instanceof PDO) {
                $settings = $connection->selectOne('select coalesce(current_setting(?, true), ?) as actor, coalesce(current_setting(?, true), ?) as change_log', [AuditActor::SETTING, '', ChangeLogSwitch::SETTING, '']);
                $actor = (string) $settings->actor;
                $changeLog = (string) $settings->change_log;
            }
            $connections[(string) $name] = ['level' => $level, 'actor' => $actor, 'change_log' => $changeLog];
        }
        $this->boundaries[spl_object_id($event->job)] = ['depth' => $depth, 'connections' => $connections];
    }

    public function handle(object $event): void
    {
        if (($event instanceof JobProcessed || $event instanceof JobExceptionOccurred) && $event->job instanceof SyncJob) {
            return;
        }
        $boundary = null;
        if ($event instanceof JobProcessed || $event instanceof JobExceptionOccurred) {
            $id = spl_object_id($event->job);
            if (! isset($this->boundaries[$id])) {
                return;
            }
            $boundary = $this->boundaries[$id];
            unset($this->boundaries[$id]);
        }
        app(EnvironmentConnection::class)->reset($boundary['depth'] ?? 0);
        foreach (DB::getConnections() as $name => $connection) {
            if (! $connection->getRawPdo() instanceof PDO) {
                continue;
            }

            $previous = $boundary['connections'][$name] ?? null;
            if (($previous['level'] ?? 0) > 0) {
                if ($connection->transactionLevel() > $previous['level']) {
                    $connection->rollBack($previous['level']);
                }
                $this->resetSessionSettings((string) $name, $connection, $previous['actor'], $previous['change_log']);

                continue;
            }
            $ownedEnvironment = str_starts_with((string) $name, 'environment_') && ! (($boundary['depth'] ?? 0) > 0 && $previous !== null);
            if ($ownedEnvironment || $connection->transactionLevel() > 0) {
                DB::purge($name);

                continue;
            }

            $this->resetSessionSettings((string) $name, $connection,
                ($boundary['depth'] ?? 0) > 0 ? ($previous['actor'] ?? '') : '',
                ($boundary['depth'] ?? 0) > 0 ? ($previous['change_log'] ?? '') : '',
            );
        }
    }

    private function resetSessionSettings(string $name, Connection $connection, string $actor = '', string $changeLog = ''): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            $connection->select('select set_config(?, ?, false), set_config(?, ?, false)', [
                AuditActor::SETTING, $actor,
                ChangeLogSwitch::SETTING, $changeLog,
            ]);
        } catch (Throwable) {
            // Koneksi yang tidak dapat dibereskan tidak boleh dipakai ulang dengan keadaan lamanya.
            DB::purge($name);
        }
    }
}
