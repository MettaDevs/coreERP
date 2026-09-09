<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterLinkController;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAsetAtribut;

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

    protected function ownerModel(): string
    {
        return JenisAset::class;
    }

    protected function ownerColumn(): string
    {
        return 'jenis_aset_id';
    }

    protected function model(): string
    {
        return JenisAsetAtribut::class;
    }

    protected function rowRules(string $tenantId): array
    {
        return [
            'tipe_atribut_id' => [
                'required', 'ulid',
                Rule::exists('aset_m_tipe_atribut', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
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
