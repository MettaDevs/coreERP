<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Membuang database PostgreSQL sungguhan yang dibuat sebuah test, dan tetap berhasil ketika ada
 * sesi yang tidak boleh diputus.
 *
 * ## Kenapa ini ada
 *
 * Empat kelas bergrup `serial` membuat database lalu membuangnya di `tearDown` dengan
 * `DROP DATABASE ... WITH (FORCE)`. Pada 13 September 2026 pembuangan itu **sesekali** ditolak:
 *
 *   SQLSTATE[42501]: permission denied to terminate process
 *   DETAIL: Only roles with privileges of the role whose process is being terminated or with
 *   privileges of the "pg_signal_backend" role may terminate this process.
 *
 * `FORCE` hanya memutus sesi yang boleh diputus role saat ini (dokumentasi `DROP DATABASE`).
 * Server pengembang saat itu hanya punya dua role, dan setiap sesi klien yang teramati milik
 * `core_erp_app` sendiri — jadi yang menolak diputus bukan klien. Dugaan terkuat: pekerja
 * autovacuum, yang tidak punya role dan bangun sesudah seeder module mengisi baris. Tiga kali
 * pengintaian tiap 200 ms tidak menangkapnya; itu tetap dugaan.
 *
 * Yang bukan dugaan adalah akibatnya, dan itu yang dijaga kelas pemakainya: pengecualian ini
 * menghentikan `tearDown` sebelum `parent::tearDown()`, transaksi `RefreshDatabase` tidak pernah
 * dibatalkan, dan test berikutnya menunggu kunci `clients_slug_unique` selamanya.
 *
 * ## Urutannya
 *
 * `FORCE` lebih dulu, karena sesi milik test itu sendiri memang harus diputus. Penolakan izin
 * dicoba ulang sampai lima detik — pekerja latar hidup sebentar. Sesudah itu `DROP DATABASE` tanpa
 * `FORCE`, yang tidak memeriksa izin memutus sesi dan hanya gagal bila sesi biasa masih menempel.
 */
trait DropsTestDatabases
{
    protected function dropTestDatabase(Connection $maintenance, string $name): void
    {
        $forced = sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $name);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            try {
                $maintenance->unprepared($forced);

                return;
            } catch (QueryException $e) {
                // 42501 = insufficient_privilege. Galat lain dibiarkan naik apa adanya.
                if ((string) $e->getCode() !== '42501') {
                    throw $e;
                }

                usleep(250_000);
            }
        }

        $maintenance->unprepared(sprintf('DROP DATABASE IF EXISTS "%s"', $name));
    }
}
