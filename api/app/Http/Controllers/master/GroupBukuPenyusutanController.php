<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterLinkController;
use App\Models\master\BukuPenyusutan;
use Illuminate\Validation\Rule;

/**
 * Matriks group x buku; padanan "Fixed asset group/book" di Dynamics 365 F&O.
 *
 * Baris di sinilah yang menentukan aset dari satu group mendapat buku apa saja, dengan
 * masa manfaat dan konvensi apa. Nilainya menjadi default
 * yang disalin ke buku aset saat aset diterima, bukan acuan hidup: mengubah matriks
 * tidak menulis ulang aset yang sudah berjalan.
 */
class GroupBukuPenyusutanController extends MasterLinkController
{
    protected function ownerResource(): string
    {
        return 'group-aset';
    }

    protected function ownerTable(): string
    {
        return 'm_group_aset';
    }

    protected function ownerColumn(): string
    {
        return 'group_aset_id';
    }

    protected function table(): string
    {
        return 'm_group_buku_penyusutan';
    }

    protected function rowRules(string $tenantId): array
    {
        $sameTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        return [
            'buku_id' => ['required', 'ulid', $sameTenant('m_buku_penyusutan')],
            'depreciation_profile_id' => ['nullable', 'ulid', $sameTenant('m_profil_penyusutan')],
            'alternative_profile_id' => ['nullable', 'ulid', $sameTenant('m_profil_penyusutan')],
            'useful_life_periods' => ['nullable', 'integer', 'min:1'],
            'convention' => ['nullable', Rule::in(BukuPenyusutan::CONVENTIONS)],
            'depreciate' => ['sometimes', 'boolean'],
            'round_off_depreciation' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function identity(array $row): array
    {
        return ['buku_id' => $row['buku_id']];
    }

    protected function rowPayload(array $row): array
    {
        return [
            'depreciation_profile_id' => $row['depreciation_profile_id'] ?? null,
            'alternative_profile_id' => $row['alternative_profile_id'] ?? null,
            'useful_life_periods' => $row['useful_life_periods'] ?? null,
            'convention' => $row['convention'] ?? null,
            'depreciate' => filter_var($row['depreciate'] ?? true, FILTER_VALIDATE_BOOL),
            'round_off_depreciation' => $row['round_off_depreciation'] ?? null,
        ];
    }

    protected function columns(): array
    {
        return [
            'id', 'buku_id', 'depreciation_profile_id', 'alternative_profile_id',
            'useful_life_periods', 'convention', 'depreciate', 'round_off_depreciation',
        ];
    }
}
