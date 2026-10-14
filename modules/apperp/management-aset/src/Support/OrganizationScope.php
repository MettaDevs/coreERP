<?php

namespace Modules\Apperp\ManagementAset\Support;

use App\Platform\Modules\Contracts\CompanyContextFilter;
use App\Platform\Modules\Contracts\DataPolicyFilter;
use Illuminate\Http\Request;

final class OrganizationScope
{
    /**
     * Ejaannya `asset`, dan itu disengaja.
     *
     * Kode kebijakan adalah pengenal, bukan tulisan yang dibaca siapa pun di produk. Ia
     * tercatat pada hibah lingkup milik tenant, jadi menggantinya membuat hibah yang sudah
     * ada tidak lagi cocok dengan kebijakan yang mencarinya — dan pengguna kehilangan akses
     * ke asetnya sendiri tanpa satu pun kesalahan yang terlihat. Sapuan KBBI 18 September
     * 2026 sempat menggantinya dan dikembalikan pada hari yang sama.
     */
    private const POLICY_CODE = 'management-aset.asset-responsibility';

    public function allows(Request $request, ?string $legalEntityId, ?string $operatingUnitId): bool
    {
        if ($legalEntityId !== null && $legalEntityId !== $request->attributes->get('coreerp.legal_entity_id')) {
            return false;
        }

        $scope = $this->scope($request);

        return $scope['all'] || collect($scope['scope_grants'])->contains(
            fn (array $grant): bool => ($legalEntityId === null || $grant['legal_entity_id'] === $legalEntityId)
                && ($operatingUnitId === null || in_array($operatingUnitId, $grant['operating_unit_ids'], true)),
        );
    }

    /**
     * Pengguna menjangkau seluruh organisasi, bukan hanya pasangan entitas legal dan unit tertentu.
     *
     * Dipakai untuk record yang boleh tidak menyebut unit kerja: tanpa unit, tidak ada hibah yang
     * dapat dicocokkan, jadi hanya jangkauan penuh yang boleh membuat dan melihatnya.
     */
    public function unrestricted(Request $request): bool
    {
        return $this->scope($request)['all'];
    }

    public function require(Request $request, ?string $legalEntityId, ?string $operatingUnitId): void
    {
        CompanyContextFilter::require($request->attributes->get('coreerp.legal_entity_id'), $legalEntityId);
        abort_unless($this->allows($request, $legalEntityId, $operatingUnitId), 403, 'Data ini berada di luar unit kerja yang dapat Anda akses.');
    }

    public function asetQuery(mixed $query, Request $request, string $alias = 'aset_tr_aset'): mixed
    {
        return $this->query($query, $request, "{$alias}.legal_entity_id", "{$alias}.responsible_org_unit_id");
    }

    public function query(mixed $query, Request $request, string $legalEntityColumn, string $operatingUnitColumn): mixed
    {
        CompanyContextFilter::apply($query, $legalEntityColumn, $request->attributes->get('coreerp.legal_entity_id'));

        return DataPolicyFilter::apply($query, $this->scope($request), $legalEntityColumn, $operatingUnitColumn);
    }

    /**
     * Penyaring untuk record milik entitas legal tanpa unit kerja, seperti polis asuransi dan kontrak
     * servis: terlihat bila pengguna punya hibah pada entitas legal itu, unit mana pun. Sama dengan
     * {@see self::allows()} yang dipanggil tanpa unit.
     */
    public function legalEntityQuery(mixed $query, Request $request, string $legalEntityColumn): mixed
    {
        CompanyContextFilter::apply($query, $legalEntityColumn, $request->attributes->get('coreerp.legal_entity_id'));

        return DataPolicyFilter::apply($query, $this->scope($request), $legalEntityColumn, null);
    }

    /** @return array{all:bool,scope_grants:list<array{legal_entity_id:?string,operating_unit_ids:list<string>}>} */
    private function scope(Request $request): array
    {
        $policies = $request->attributes->get('coreerp.data_policies', []);
        $scope = is_array($policies) ? ($policies[self::POLICY_CODE] ?? []) : [];
        if (! is_array($scope)) {
            return ['all' => false, 'scope_grants' => []];
        }

        $daftarHibah = $scope['scope_grants'] ?? null;
        $hibah = [];
        foreach (is_array($daftarHibah) ? $daftarHibah : [] as $grant) {
            if (! is_array($grant)) {
                continue;
            }
            // Id unit dikumpulkan satu per satu, bukan lewat `array_filter`, supaya tipenya
            // benar-benar terbaca sebagai daftar string — dan supaya isi yang bukan daftar
            // sama sekali tidak menjatuhkan permintaan.
            $unitIds = $grant['operating_unit_ids'] ?? null;
            $unitTersaring = [];
            foreach (is_array($unitIds) ? $unitIds : [] as $unitId) {
                if (is_string($unitId)) {
                    $unitTersaring[] = $unitId;
                }
            }
            $hibah[] = [
                'legal_entity_id' => is_string($grant['legal_entity_id'] ?? null) ? $grant['legal_entity_id'] : null,
                'operating_unit_ids' => $unitTersaring,
            ];
        }

        return [
            // `?? false`, bukan akses langsung: kebijakan yang tidak diberikan kepada
            // pengguna sama sekali menghasilkan array kosong, dan itu keadaan normal — bukan
            // alasan untuk melempar. Dulu tidak pernah terjadi karena token selalu memuat
            // kunci kebijakannya walau isinya kosong; Core menyusunnya hanya bila ada.
            'all' => ($scope['all'] ?? false) === true,
            'scope_grants' => $hibah,
        ];
    }
}
