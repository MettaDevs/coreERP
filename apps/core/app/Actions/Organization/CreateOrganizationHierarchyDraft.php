<?php

namespace App\Actions\Organization;

use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrganizationHierarchyDraft
{
    public function handle(TenantMembership $actor, OrganizationHierarchyVersion $source, string $effectiveFrom): OrganizationHierarchyVersion
    {
        $source->loadMissing(['hierarchy', 'nodes']);

        if (! $actor->canManageAccess() || $source->hierarchy->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }
        if ($source->status !== 'published') {
            throw ValidationException::withMessages(['version' => 'Draft baru hanya dapat dibuat dari versi yang sudah dipublikasikan.']);
        }

        return DB::transaction(function () use ($source, $effectiveFrom): OrganizationHierarchyVersion {
            $hierarchy = $source->hierarchy()->lockForUpdate()->firstOrFail();
            $nextNumber = $hierarchy->versions()->max('version_number') + 1;
            $draft = $hierarchy->versions()->create([
                'version_number' => $nextNumber,
                'status' => 'draft',
                'effective_from' => $effectiveFrom,
            ]);

            $remaining = $source->nodes->keyBy('id');
            $nodeIds = [];
            while ($remaining->isNotEmpty()) {
                foreach ($remaining as $node) {
                    if ($node->parent_node_id && ! isset($nodeIds[$node->parent_node_id])) {
                        continue;
                    }

                    $nodeIds[$node->id] = $draft->nodes()->create([
                        'organization_id' => $node->organization_id,
                        'parent_node_id' => $node->parent_node_id ? $nodeIds[$node->parent_node_id] : null,
                    ])->id;
                    $remaining->forget($node->id);
                }
            }

            DB::table('organization_hierarchy_closures')->insertUsing(
                ['version_id', 'ancestor_organization_id', 'descendant_organization_id', 'distance'],
                DB::table('organization_hierarchy_closures')
                    ->selectRaw('? as version_id, ancestor_organization_id, descendant_organization_id, distance', [$draft->id])
                    ->where('version_id', $source->id),
            );

            return $draft;
        });
    }
}
