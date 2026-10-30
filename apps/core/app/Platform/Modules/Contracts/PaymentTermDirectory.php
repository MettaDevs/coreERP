<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Illuminate\Support\Collection;

interface PaymentTermDirectory
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
     * @return array<string, array{id: string, code: string, name: string, due_days: int, description: ?string, active: bool, version: int}>
     */
    public function resolve(string $tenantId, array $ids): array;

    /**
     * Daftar termin pembayaran aktif untuk pemilih/dropdown.
     *
     * @return list<array{id: string, code: string, name: string, due_days: int}>
     */
    public function active(string $tenantId): array;
}
