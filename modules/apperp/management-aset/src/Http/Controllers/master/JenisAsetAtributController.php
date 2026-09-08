<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterLinkController;

/**
 * Atribut yang menempel pada satu jenis aset. Aset mewarisi daftar ini dari jenisnya,
 * sehingga menambah pembeda baru cukup dengan menambah atribut, bukan tabel.
 */
class JenisAsetAtributController extends MasterLinkController
{
    protected function ownerResource(): string
    {
        return 'jenis-aset';
    }

    protected function ownerTable(): string
    {
        return 'm_jenis_aset';
    }

    protected function ownerColumn(): string
    {
        return 'jenis_aset_id';
    }

    protected function table(): string
    {
        return 'm_jenis_aset_atribut';
    }

    protected function rowRules(string $tenantId): array
    {
        return [
            'tipe_atribut_id' => [
                'required', 'ulid',
                Rule::exists('m_tipe_atribut', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'wajib' => ['sometimes', 'boolean'],
            'urutan' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function identity(array $row): array
    {
        return ['tipe_atribut_id' => $row['tipe_atribut_id']];
    }

    protected function rowPayload(array $row): array
    {
        return [
            'wajib' => filter_var($row['wajib'] ?? false, FILTER_VALIDATE_BOOL),
            'urutan' => (int) ($row['urutan'] ?? 0),
        ];
    }

    protected function columns(): array
    {
        return ['id', 'tipe_atribut_id', 'wajib', 'urutan'];
    }
}
