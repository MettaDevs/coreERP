<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Support\MasterChild;
use Modules\Apperp\ManagementAset\Support\MasterParent;

/**
 * @extends MasterDataController<LokasiAset>
 */
class LokasiAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'lokasi-aset';
    }

    protected function model(): string
    {
        return LokasiAset::class;
    }

    protected function parentMasters(): array
    {
        return [
            // Induk lokasi menunjuk tabel yang sama, jadi inilah satu-satunya master
            // yang perlu dijaga dari siklus.
            new MasterParent(
                table: 'aset_m_lokasi_aset',
                column: 'parent_id',
                relation: 'parentLocation',
                label: 'lokasi induk',
                required: false,
            ),
            new MasterParent(
                table: 'aset_m_tipe_lokasi_aset',
                column: 'tipe_lokasi_id',
                relation: 'tipeLokasi',
                label: 'tipe lokasi',
                required: false,
            ),
        ];
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'aset_m_lokasi_aset', column: 'parent_id', label: 'lokasi anak'),
            new MasterChild(table: 'aset_tr_penerimaan_aset', column: 'asset_location_id', label: 'aset'),
            // Group yang memakai lokasi ini sebagai lokasi bawaan penerimaan. Tanpa ini
            // mengarsipkan lokasi meninggalkan group yang diam-diam menunjuk data mati.
            new MasterChild(table: 'aset_m_group_aset', column: 'asset_location_id', label: 'group aset'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        // Unit organisasi dimiliki Core dan tidak direplikasi di sini, jadi yang dapat
        // diperiksa hanya bentuknya. Keabsahan unit ditegakkan saat aset ditempatkan,
        // lewat scope organisasi pada token konteks.
        return ['org_unit_id' => ['sometimes', 'nullable', 'ulid']];
    }

    protected function extraPayload(array $data): array
    {
        return array_key_exists('org_unit_id', $data) ? ['org_unit_id' => $data['org_unit_id']] : [];
    }

    protected function extraPresent(MasterData $record): array
    {
        return ['org_unit_id' => $record->org_unit_id];
    }
}
