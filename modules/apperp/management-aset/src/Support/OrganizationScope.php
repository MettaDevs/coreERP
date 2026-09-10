<?php

namespace Modules\Apperp\ManagementAset\Support;

use Illuminate\Http\Request;

final class OrganizationScope
{
    private const POLICY_CODE = 'management-aset.asset-responsibility';

    public function allows(Request $request, ?string $legalEntityId, ?string $operatingUnitId): bool
    {
        $scope = $this->scope($request);

        return $scope['all'] || collect($scope['scope_grants'])->contains(
            fn (array $grant): bool => ($legalEntityId === null || $grant['legal_entity_id'] === $legalEntityId)
                && ($operatingUnitId === null || in_array($operatingUnitId, $grant['operating_unit_ids'], true)),
        );
    }

    public function require(Request $request, ?string $legalEntityId, ?string $operatingUnitId): void
    {
        abort_unless($this->allows($request, $legalEntityId, $operatingUnitId), 403, 'Data ini berada di luar unit kerja yang dapat Anda akses.');
    }

    public function assetQuery(mixed $query, Request $request, string $alias = 'aset_tr_penerimaan_aset'): mixed
    {
        return $this->query($query, $request, "{$alias}.legal_entity_id", "{$alias}.responsible_org_unit_id");
    }

    public function query(mixed $query, Request $request, string $legalEntityColumn, string $operatingUnitColumn): mixed
    {
        $scope = $this->scope($request);
        if ($scope['all']) {
            return $query;
        }
        if ($scope['scope_grants'] === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($query) use ($scope, $legalEntityColumn, $operatingUnitColumn): void {
            foreach ($scope['scope_grants'] as $grant) {
                $query->orWhere(function ($query) use ($grant, $legalEntityColumn, $operatingUnitColumn): void {
                    if ($grant['legal_entity_id'] === null || $grant['operating_unit_ids'] === []) {
                        $query->whereRaw('1 = 0');

                        return;
                    }
                    $query->where($legalEntityColumn, $grant['legal_entity_id'])->whereIn($operatingUnitColumn, $grant['operating_unit_ids']);
                });
            }
        });
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
