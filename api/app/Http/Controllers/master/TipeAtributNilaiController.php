<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterLinkController;

/**
 * Pilihan nilai untuk atribut bertipe daftar tetap, disunting di dalam form atributnya.
 */
class TipeAtributNilaiController extends MasterLinkController
{
    protected function ownerResource(): string
    {
        return 'tipe-atribut';
    }

    protected function ownerTable(): string
    {
        return 'm_tipe_atribut';
    }

    protected function ownerColumn(): string
    {
        return 'tipe_atribut_id';
    }

    protected function table(): string
    {
        return 'm_tipe_atribut_nilai';
    }

    protected function rowRules(string $tenantId): array
    {
        return [
            'nilai' => ['required', 'string', 'max:150'],
            'urutan' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function identity(array $row): array
    {
        return ['nilai' => $row['nilai']];
    }

    protected function rowPayload(array $row): array
    {
        return ['urutan' => (int) ($row['urutan'] ?? 0)];
    }

    protected function columns(): array
    {
        return ['id', 'nilai', 'urutan'];
    }
}
