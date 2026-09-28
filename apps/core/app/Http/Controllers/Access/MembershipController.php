<?php

namespace App\Http\Controllers\Access;

use App\Actions\Access\UpdateMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\MembershipRequest;
use App\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function show(Request $request, TenantMembership $membership): JsonResponse
    {
        $actor = $this->currentMembership($request);
        abort_unless($actor->tenant_id === $membership->tenant_id, 404);

        return response()->json(['data' => $membership->load(['user:id,name,email', 'roleAssignments.role'])]);
    }

    public function update(MembershipRequest $request, TenantMembership $membership, UpdateMembership $action): JsonResponse|RedirectResponse
    {
        $updated = $action->handle($this->currentMembership($request), $membership, $request->payload());

        return $request->is('api/*')
            ? response()->json(['data' => $updated])
            : back()->with('status', 'Member access updated.');
    }
}
