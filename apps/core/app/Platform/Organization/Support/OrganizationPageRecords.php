<?php

namespace App\Platform\Organization\Support;

use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Organization\Models\Organization;
use App\Platform\Organization\Models\OrganizationHierarchy;
use App\Platform\Organization\Models\OrganizationHierarchyVersion;
use Illuminate\Http\Request;

/** Query daftar layar organisasi; scope dan urutan diterapkan sebelum batas batch. */
final class OrganizationPageRecords
{
    /** @return array<string, mixed> */
    public static function batch(Request $request, string $tenantId, string $section): array
    {
        $request->validate(['resource' => ['nullable', 'in:organizations,hierarchies'], 'exclude_version_id' => ['nullable', 'ulid']]);
        $organizations = $section !== 'hierarchies' || $request->input('resource') === 'organizations';
        if (! $organizations) {
            if ($request->filled('record_id')) {
                $request->validate(['record_id' => ['required', 'ulid']]);

                return ['data' => [self::hierarchy($tenantId, $request->string('record_id')->toString())], 'next_cursor' => null];
            }

            return IncrementalList::query($request, OrganizationHierarchy::query()->where('tenant_id', $tenantId)->select(['id', 'name', 'status', 'version'])->toBase(), ['name' => 'name'], ['name'], 'name');
        }
        $query = Organization::query()->where('organizations.tenant_id', $tenantId)
            ->leftJoin('legal_entities as company', 'company.organization_id', '=', 'organizations.id')
            ->leftJoin('operating_units as unit', 'unit.organization_id', '=', 'organizations.id')
            ->select(['organizations.id', 'organizations.name', 'organizations.classification', 'organizations.status'])
            ->selectRaw('COALESCE(company.company_code, unit.number) AS code');
        if ($section !== 'hierarchies') {
            $query->where('organizations.classification', $section === 'legal-entities' ? 'legal_entity' : 'operating_unit');
        } else {
            $query->where('organizations.status', 'active');
        }
        if ($request->filled('exclude_version_id')) {
            $version = OrganizationHierarchyVersion::query()->whereHas('hierarchy', fn ($query) => $query->where('tenant_id', $tenantId))->findOrFail($request->string('exclude_version_id')->toString());
            $query->whereNotIn('organizations.id', $version->nodes()->select('organization_id'));
        }
        $batch = IncrementalList::query($request, $query->toBase(), ['name' => 'name', 'code' => 'code'], ['name', 'code'], 'name');
        if ($batch['data']->isEmpty()) {
            return $batch;
        }
        $records = Organization::query()->where('tenant_id', $tenantId)->whereIn('id', $batch['data']->pluck('id'))->with(['legalEntity', 'operatingUnit'])->get()->keyBy('id');
        $batch['data'] = $batch['data']->map(fn ($row) => $records[$row->id]);

        return $batch;
    }

    public static function hierarchy(string $tenantId, string $id): OrganizationHierarchy
    {
        // Graf versi yang dipilih dimuat utuh; daftar hierarki tetap dibatasi per batch.
        return OrganizationHierarchy::query()->where('tenant_id', $tenantId)->with([
            'purposes:id,code,name', 'purposes.allowedOrganizationTypes',
            'versions' => fn ($query) => $query->orderByDesc('version_number')->limit(1),
            'versions.nodes.organization:id,name,classification',
            'versions.nodes.organization.operatingUnit:organization_id,type,number',
            'versions.nodes.parentNode:id,organization_id',
            'versions.nodes.parentNode.organization:id,name',
        ])->findOrFail($id);
    }
}
