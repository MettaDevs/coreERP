<?php

namespace App\Platform\Organization\Actions;

use App\Models\TenantMembership;
use App\Platform\Organization\Models\OrganizationHierarchyVersion;
use App\Support\Access\CoreSecurityCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishOrganizationHierarchy
{
    public function handle(TenantMembership $actor, OrganizationHierarchyVersion $version): void
    {
        if (! $actor->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE) || $version->hierarchy->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['hierarchy_version' => 'Hanya draft hierarchy yang dapat dipublikasikan.']);
        }

        DB::transaction(function () use ($version): void {
            $version->update(['status' => 'published', 'published_at' => now()]);
            $version->hierarchy()->update(['status' => 'active']);
        });
    }
}
