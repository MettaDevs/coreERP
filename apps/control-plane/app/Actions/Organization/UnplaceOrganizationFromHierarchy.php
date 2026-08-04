<?php

namespace App\Actions\Organization;

use App\Models\OrganizationHierarchyNode;
use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnplaceOrganizationFromHierarchy
{
    public function handle(TenantMembership $actor, OrganizationHierarchyVersion $version, OrganizationHierarchyNode $node): void
    {
        if (! $actor->canManageAccess() || $version->hierarchy->tenant_id !== $actor->tenant_id || $node->version_id !== $version->id) {
            throw new AuthorizationException;
        }
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'Hierarchy yang sudah dipublikasikan tidak dapat diubah.']);
        }
        if ($node->parent_node_id === null) {
            throw ValidationException::withMessages(['node' => 'Organisasi paling atas tidak dapat dilepas dari draft ini.']);
        }
        if ($version->nodes()->where('parent_node_id', $node->id)->exists()) {
            throw ValidationException::withMessages(['node' => 'Pindahkan atau lepaskan organisasi di bawahnya terlebih dahulu.']);
        }

        DB::transaction(function () use ($version, $node): void {
            DB::table('organization_hierarchy_closures')
                ->where('version_id', $version->id)
                ->where(fn ($query) => $query
                    ->where('ancestor_organization_id', $node->organization_id)
                    ->orWhere('descendant_organization_id', $node->organization_id))
                ->delete();

            $node->delete();
        });
    }
}
