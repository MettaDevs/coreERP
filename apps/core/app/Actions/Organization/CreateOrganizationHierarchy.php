<?php

namespace App\Actions\Organization;

use App\Models\HierarchyPurpose;
use App\Models\Organization;
use App\Models\OrganizationHierarchy;
use App\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrganizationHierarchy
{
    /** @param array{name:string,purpose_codes:list<string>,root_organization_id:string,effective_from:string} $data */
    public function handle(TenantMembership $actor, array $data): OrganizationHierarchy
    {
        if (! $actor->canManageAccess()) {
            throw new AuthorizationException;
        }

        $root = Organization::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('status', 'active')
            ->find($data['root_organization_id']);
        $purposes = HierarchyPurpose::query()->whereIn('code', $data['purpose_codes'])->get();
        if (! $root || $purposes->count() !== count(array_unique($data['purpose_codes']))) {
            throw ValidationException::withMessages(['root_organization_id' => 'Organisasi atau tujuan hierarchy tidak valid.']);
        }

        try {
            return DB::transaction(function () use ($actor, $data, $root, $purposes): OrganizationHierarchy {
                $hierarchy = OrganizationHierarchy::create([
                    'tenant_id' => $actor->tenant_id,
                    'name' => $data['name'],
                    'status' => 'draft',
                ]);
                $hierarchy->purposes()->sync($purposes->modelKeys());
                $version = $hierarchy->versions()->create([
                    'version_number' => 1,
                    'status' => 'draft',
                    'effective_from' => $data['effective_from'],
                ]);
                $version->nodes()->create(['organization_id' => $root->id]);
                DB::table('organization_hierarchy_closures')->insert([
                    'version_id' => $version->id,
                    'ancestor_organization_id' => $root->id,
                    'descendant_organization_id' => $root->id,
                    'distance' => 0,
                ]);

                return $hierarchy->load(['purposes', 'versions.nodes.organization']);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'organization_hierarchies_tenant_id_name_unique')) {
                throw ValidationException::withMessages([
                    'name' => 'Nama susunan organisasi sudah dipakai. Pilih nama lain.',
                ]);
            }

            throw $exception;
        }
    }
}
