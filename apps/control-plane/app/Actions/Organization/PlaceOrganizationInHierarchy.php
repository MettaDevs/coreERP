<?php

namespace App\Actions\Organization;

use App\Models\Organization;
use App\Models\OrganizationHierarchyNode;
use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlaceOrganizationInHierarchy
{
    public function handle(TenantMembership $actor, OrganizationHierarchyVersion $version, string $organizationId, string $parentOrganizationId): void
    {
        if (! $actor->canManageAccess() || $version->hierarchy->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'Hierarchy yang sudah dipublikasikan tidak dapat diubah.']);
        }

        $organization = Organization::query()->where('tenant_id', $actor->tenant_id)->find($organizationId);
        $parent = OrganizationHierarchyNode::query()
            ->where('version_id', $version->id)
            ->where('organization_id', $parentOrganizationId)
            ->first();
        if (! $organization || ! $parent) {
            throw ValidationException::withMessages(['organization_id' => 'Organisasi dan parent harus berasal dari tenant serta versi yang sama.']);
        }

        DB::transaction(function () use ($version, $organization, $parent): void {
            $version->nodes()->create([
                'organization_id' => $organization->id,
                'parent_node_id' => $parent->id,
            ]);
            $ancestors = DB::table('organization_hierarchy_closures')
                ->where('version_id', $version->id)
                ->where('descendant_organization_id', $parent->organization_id)
                ->get();
            foreach ($ancestors as $ancestor) {
                DB::table('organization_hierarchy_closures')->insert([
                    'version_id' => $version->id,
                    'ancestor_organization_id' => $ancestor->ancestor_organization_id,
                    'descendant_organization_id' => $organization->id,
                    'distance' => $ancestor->distance + 1,
                ]);
            }
            DB::table('organization_hierarchy_closures')->insert([
                'version_id' => $version->id,
                'ancestor_organization_id' => $organization->id,
                'descendant_organization_id' => $organization->id,
                'distance' => 0,
            ]);
        });
    }
}
