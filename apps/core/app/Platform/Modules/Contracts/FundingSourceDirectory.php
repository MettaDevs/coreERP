<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Illuminate\Support\Collection;

interface FundingSourceDirectory
{
    /**
     * Pilihan aktif dimuat bertahap; record_ids memulihkan label referensi tersimpan termasuk arsip.
     *
     * @param  array<string, mixed>  $input
     * @return array{data: Collection<int, \stdClass>, next_cursor: ?string}
     */
    public function lookup(string $tenantId, array $input): array;

    /**
     * Record tidak diarsipkan, termasuk nonaktif agar pemanggil dapat memvalidasi statusnya.
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, name: string, description: ?string, active: bool, version: int}>
     */
    public function resolve(string $tenantId, array $ids): array;

    /**
     * Pemindahan keterangan sumber dana lama; pemanggilan ulang tidak menggandakan master.
     * Hanya untuk migration data, bukan pembuatan dari input transaksi baru.
     *
     * @return array{id: string, name: string, description: ?string, active: bool, version: int}
     */
    public function importLegacy(string $tenantId, string $name): array;
}
