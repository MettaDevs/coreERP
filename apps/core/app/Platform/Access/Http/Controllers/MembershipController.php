<?php

namespace App\Platform\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\Actions\UpdateMembership;
use App\Platform\Access\Http\Requests\MembershipRequest;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Tenant\Models\TenantMembership;
use App\Support\Modules\Contracts\RowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function show(Request $request, TenantMembership $membership): JsonResponse
    {
        $actor = $this->currentMembership($request);
        abort_unless($actor->tenant_id === $membership->tenant_id, 404);
        abort_unless($actor->hasCorePermission(CoreSecurityCatalog::ACCESS_READ), 403);

        return response()->json(['data' => $membership->load(['user:id,name,email', 'roleAssignments.role'])])
            ->header('ETag', RowVersion::etag($membership->version));
    }

    public function update(MembershipRequest $request, TenantMembership $membership, UpdateMembership $action): JsonResponse|RedirectResponse
    {
        $updated = $action->handle($this->currentMembership($request), $membership, $request->payload(), RowVersion::expected($request));

        return $request->is('api/*')
            ? response()->json(['data' => $updated])
            : back()->with('status', 'Member access updated.');
    }
}
