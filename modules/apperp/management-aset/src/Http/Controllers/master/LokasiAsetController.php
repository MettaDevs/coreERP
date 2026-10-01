<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use App\Platform\Modules\Contracts\AddressDirectory;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;
use Modules\Apperp\ManagementAset\Services\LocationInheritance;
use Modules\Apperp\ManagementAset\Support\MasterChild;
use Modules\Apperp\ManagementAset\Support\MasterParent;
use stdClass;

/**
 * @extends MasterDataController<LokasiAset>
 */
class LokasiAsetController extends MasterDataController
{
    /**
     * Hafalan per permintaan untuk menghitung alamat dan unit kerja yang diwarisi: pohon lokasi tenant
     * dan alamat yang sudah dibaca. Disimpan di atribut permintaan, bukan di properti controller, karena
     * instance controller dipakai ulang oleh rutenya dan hafalan permintaan sebelumnya akan basi.
     * Penyajian selalu terjadi sesudah penulisan, jadi pohon yang terbaca sudah memuat perubahan
     * permintaan ini.
     */
    private const MEMO = 'management-aset.lokasi-aset.memo';

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
            new MasterChild(table: 'aset_tr_aset', column: 'lokasi_aset_id', label: 'aset'),
            // Group yang memakai lokasi ini sebagai lokasi bawaan penerimaan. Tanpa ini
            // mengarsipkan lokasi meninggalkan group yang diam-diam menunjuk data mati.
            new MasterChild(table: 'aset_m_group_aset', column: 'lokasi_aset_id', label: 'group aset'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        // Unit organisasi dimiliki Core dan tidak direplikasi di sini, jadi yang dapat
        // diperiksa hanya bentuknya. Keabsahan unit ditegakkan saat aset ditempatkan,
        // lewat scope organisasi pada token konteks.
        return [
            'org_unit_id' => ['sometimes', 'nullable', 'ulid'],
            // Keberadaan keduanya diperiksa lewat kontrak Core di afterWriteValidation().
            'alamat_id' => ['sometimes', 'nullable', 'ulid'],
            'departemen_bawaan_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }

    protected function afterWriteValidation(array $data, string $tenantId, bool $creating, ?MasterData $record = null): void
    {
        $errors = [];
        $address = $data['alamat_id'] ?? null;
        if ($address !== null && app(AddressDirectory::class)->describe($tenantId, [$address]) === []) {
            $errors['alamat_id'] = 'Alamat tidak ditemukan di buku alamat. Pilih alamat yang tersedia.';
        }
        $department = $data['departemen_bawaan_id'] ?? null;
        if ($department !== null && app(AssetOrganizationDirectory::class)->unitName($tenantId, $department) === null) {
            $errors['departemen_bawaan_id'] = 'Unit kerja tidak ditemukan. Pilih unit kerja yang tersedia.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function extraPayload(array $data): array
    {
        return array_intersect_key($data, array_flip(['org_unit_id', 'alamat_id', 'departemen_bawaan_id']));
    }

    protected function extraPresent(MasterData $record): array
    {
        $tenantId = $record->tenant_id;
        $inheritance = app(LocationInheritance::class);
        $tree = $this->tree();
        $units = app(AssetOrganizationDirectory::class);

        $address = $inheritance->nearest($record->id, LocationInheritance::ADDRESS, $tree);
        $department = $inheritance->nearest($record->id, LocationInheritance::DEFAULT_DEPARTMENT, $tree);
        $describe = fn (?string $id): ?array => $id === null ? null : $this->address($tenantId, $id);

        return [
            'org_unit_id' => $record->org_unit_id,
            'alamat_id' => $record->alamat_id,
            'alamat' => $describe($record->alamat_id),
            // Alamat yang berlaku: milik lokasi ini, atau warisan lokasi induk terdekat yang punya alamat.
            'alamat_efektif' => $address === null ? null : [
                ...($describe($address['value']) ?? ['id' => $address['value'], 'nama' => null, 'alamat' => null]),
                'diwarisi_dari' => $this->source($record->id, $address['location_id']),
            ],
            'departemen_bawaan_id' => $record->departemen_bawaan_id,
            'departemen_bawaan_nama' => $units->unitName($tenantId, $record->departemen_bawaan_id),
            'departemen_bawaan_efektif' => $department === null ? null : [
                'id' => $department['value'],
                'nama' => $units->unitName($tenantId, $department['value']),
                'diwarisi_dari' => $this->source($record->id, $department['location_id']),
            ],
        ];
    }

    /** @return array<string, stdClass> Pohon lokasi tenant, dibaca sekali per permintaan. */
    private function tree(): array
    {
        $attributes = request()->attributes;
        if (! $attributes->has(self::MEMO.'.tree')) {
            $attributes->set(self::MEMO.'.tree', app(LocationInheritance::class)->tree());
        }

        return $attributes->get(self::MEMO.'.tree');
    }

    /** @return array{id: string, nama: string, alamat: string}|null */
    private function address(string $tenantId, string $id): ?array
    {
        $attributes = request()->attributes;
        $key = self::MEMO.'.alamat.'.$id;
        if (! $attributes->has($key)) {
            $attributes->set($key, app(AddressDirectory::class)->describe($tenantId, [$id])[$id] ?? null);
        }

        return $attributes->get($key);
    }

    /** @return array{id: string, kode: string, nama: string}|null Lokasi pemilik nilai, bila bukan lokasi ini. */
    private function source(string $locationId, string $ownerId): ?array
    {
        if ($ownerId === $locationId) {
            return null;
        }
        $owner = $this->tree()[$ownerId] ?? null;

        return $owner === null ? null : ['id' => $ownerId, 'kode' => (string) $owner->kode, 'nama' => (string) $owner->nama];
    }
}
