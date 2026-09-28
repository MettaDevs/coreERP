<?php

namespace App\Actions\Organization;

use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishOrganizationHierarchy
{
    public function handle(TenantMembership $actor, OrganizationHierarchyVersion $version): void
    {
        if (! $actor->canManageAccess() || $version->hierarchy->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'Hanya draft hierarchy yang dapat dipublikasikan.']);
        }

        DB::transaction(function () use ($version): void {
            $version->update(['status' => 'published', 'published_at' => now()]);
            $version->hierarchy()->update(['status' => 'active']);
        });
    }
}
