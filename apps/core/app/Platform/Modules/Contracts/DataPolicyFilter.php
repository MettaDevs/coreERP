<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Menyaring query menurut hibah satu kebijakan data, bentuk yang dipulangkan
 * `DataPolicyAccessResolver::resolve()` untuk satu kode kebijakan:
 * `{all: bool, scope_grants: list<{legal_entity_id: ?string, operating_unit_ids: list<string>}>}`.
 *
 * Gagal tertutup: tanpa hibah, nol baris. Hibah tanpa legal entity atau tanpa unit tidak menjangkau
 * apa pun pada mode legal entity + unit. Aturan ini sama dengan `OrganizationScope::query()` module
 * aset; module boleh pindah memakainya, tetapi tidak wajib.
 *
 * Kenapa di kontrak, bukan di engine analitik: analitik harus menyaring persis seperti layar module
 * (keamanan engine analitik, prinsip 1). Dua salinan aturan yang sama akan menyimpang, jadi
 * aturannya tinggal di tempat yang boleh disebut module dan Core sekaligus.
 */
final class DataPolicyFilter
{
    /**
     * Nama kolom datang dari definisi yang didaftarkan module, tidak pernah dari pemanggil. Tanpa
     * `$operatingUnitColumn`, hanya legal entity yang dicocokkan — sama dengan
     * `OrganizationScope::legalEntityQuery()` untuk record tanpa unit kerja.
     *
     * @param  array{all?: mixed, scope_grants?: mixed}  $scope
     */
    public static function apply(Builder $query, array $scope, string $legalEntityColumn, ?string $operatingUnitColumn): Builder
    {
        if (($scope['all'] ?? false) === true) {
            return $query;
        }

        $grants = self::grants($scope);
        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }

        if ($operatingUnitColumn === null) {
            $legalEntities = array_values(array_unique(array_filter(array_column($grants, 'legal_entity_id'))));

            return $legalEntities === [] ? $query->whereRaw('1 = 0') : $query->whereIn($legalEntityColumn, $legalEntities);
        }

        return $query->where(function (Builder $query) use ($grants, $legalEntityColumn, $operatingUnitColumn): void {
            foreach ($grants as $grant) {
                $query->orWhere(function (Builder $query) use ($grant, $legalEntityColumn, $operatingUnitColumn): void {
                    if ($grant['legal_entity_id'] === null || $grant['operating_unit_ids'] === []) {
                        $query->whereRaw('1 = 0');

                        return;
                    }
                    $query->where($legalEntityColumn, $grant['legal_entity_id'])
                        ->whereIn($operatingUnitColumn, $grant['operating_unit_ids']);
                });
            }
        });
    }

    /**
     * @param  array{all?: mixed, scope_grants?: mixed}  $scope
     * @return list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>
     */
    private static function grants(array $scope): array
    {
        // Bentuk dibaca satu per satu, bukan dipercaya: nilai yang bukan daftar string tidak boleh
        // menjatuhkan permintaan, dan tidak boleh melebarkan jangkauan.
        $out = [];
        foreach (is_array($scope['scope_grants'] ?? null) ? $scope['scope_grants'] : [] as $grant) {
            if (! is_array($grant)) {
                continue;
            }
            $units = [];
            foreach (is_array($grant['operating_unit_ids'] ?? null) ? $grant['operating_unit_ids'] : [] as $unit) {
                if (is_string($unit)) {
                    $units[] = $unit;
                }
            }
            $out[] = [
                'legal_entity_id' => is_string($grant['legal_entity_id'] ?? null) ? $grant['legal_entity_id'] : null,
                'operating_unit_ids' => $units,
            ];
        }

        return $out;
    }
}
