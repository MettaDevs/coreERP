<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * Menjawab satu pertanyaan: setelah migration sebuah module dijalankan, tabel apa saja yang
 * lahir, dan adakah yang bukan miliknya?
 *
 * Semua tabel kini berada di satu database, jadi tidak ada lagi database terpisah yang
 * menahan sebuah module membuat tabel milik Core atau module lain. Pelanggaran di lapisan
 * migration tidak terlihat sampai datanya telanjur bercampur, dan sesudah itu memisahkannya
 * berarti menebak-nebak.
 */
final class TableOwnershipInspector
{
    /**
     * Nama tabel yang terlihat pada schema yang sedang dipakai koneksi.
     *
     * @return list<string>
     */
    public function currentTables(ConnectionInterface $connection): array
    {
        /** @var list<object{table_name: string}> $rows */
        $rows = $connection->select(
            'SELECT table_name FROM information_schema.tables '.
            'WHERE table_schema = current_schema() AND table_type = ? ORDER BY table_name',
            ['BASE TABLE']
        );

        return array_map(static fn (object $row): string => (string) $row->table_name, $rows);
    }

    /**
     * Tabel yang lahir di antara dua pengamatan tetapi tidak berawalan milik module.
     *
     * Pengecualian sengaja diminta sebagai argumen, bukan dibaca dari konfigurasi: sebuah
     * pengecualian baru harus terlihat pada diff pull request, bukan tersembunyi di berkas
     * setelan yang jarang dibuka.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @param  list<string>  $exemption
     * @return list<string>
     */
    public function violations(array $before, array $after, string $prefix, array $exemption = []): array
    {
        $newTables = array_values(array_diff($after, $before));

        $violates = array_values(array_filter(
            $newTables,
            static fn (string $table): bool => ! str_starts_with($table, $prefix)
                && ! in_array($table, $exemption, true),
        ));

        sort($violates);

        return $violates;
    }
}
