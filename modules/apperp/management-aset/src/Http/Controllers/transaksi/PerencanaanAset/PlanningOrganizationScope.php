<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PerencanaanAset;

use App\Platform\Modules\Contracts\OrganizationDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PlanningOrganizationScope
{
    /** @return array{data: Collection<int, \stdClass>, next_cursor: ?string} */
    public function choices(Request $request, string $kind, ?string $companyId = null): array
    {
        $policy = $this->policy($request);
        $all = ($policy['all'] ?? false) === true;
        $grants = collect($policy['scope_grants'] ?? []);
        if ($kind === 'operating_units') {
            $this->require($request, (string) $companyId);
            $ids = $grants->where('legal_entity_id', $companyId)->flatMap(fn ($grant) => $grant['operating_unit_ids'] ?? [])->unique()->values()->all();
        } else {
            $ids = $grants->pluck('legal_entity_id')->filter()->unique()->values()->all();
        }
        if (! $all && $ids === []) {
            return ['data' => collect(), 'next_cursor' => null];
        }
        $input = $request->query();
        unset($input['kind'], $input['legal_entity_id']);

        return app(OrganizationDirectory::class)->lookup((string) $request->attributes->get('coreerp.tenant_id'), $kind, $input, $all ? null : $ids);
    }

    public function require(Request $request, string $companyId, ?string $unitId = null): void
    {
        $policy = $this->policy($request);
        $allowed = ($policy['all'] ?? false) === true || collect($policy['scope_grants'] ?? [])->contains(
            fn ($grant) => ($grant['legal_entity_id'] ?? null) === $companyId
                && ($unitId === null || in_array($unitId, $grant['operating_unit_ids'] ?? [], true)),
        );
        abort_unless($allowed, 403, 'Perusahaan atau unit pengusul berada di luar akses Anda.');
        $directory = app(OrganizationDirectory::class);
        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        $company = $directory->lookup($tenantId, 'legal_entities', ['record_ids' => [$companyId], 'per_page' => 1]);
        abort_unless(collect($company['data'])->contains(fn ($row) => (string) $row->id === $companyId), 403);
        if ($unitId !== null) {
            $unit = $directory->lookup($tenantId, 'operating_units', ['record_ids' => [$unitId], 'per_page' => 1]);
            abort_unless(collect($unit['data'])->contains(fn ($row) => (string) $row->id === $unitId), 403);
        }
    }

    /** @return array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>} */
    private function policy(Request $request): array
    {
        return $request->attributes->get('coreerp.data_policies', [])['management-aset.asset-responsibility']
            ?? ['all' => false, 'scope_grants' => []];
    }
}
