<?php

namespace App\Http\Controllers\Access;

use App\Actions\Access\CreateInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\InvitationRequest;
use App\Models\InvitationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InvitationCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);

        return response()->json(['data' => InvitationCode::query()
            ->where('tenant_id', $membership->tenant_id)
            ->with('roles:id,name')
            ->latest()
            ->get()]);
    }

    public function store(InvitationRequest $request, CreateInvitation $action): JsonResponse|RedirectResponse
    {
        $result = $action->handle($this->currentMembership($request), $request->payload());

        if ($request->is('api/*')) {
            return response()->json([
                'data' => [
                    'id' => $result['invitation']->id,
                    'code' => $result['code'],
                    'expires_at' => $result['invitation']->expires_at,
                ],
            ], 201);
        }

        return back()->with('new_invitation_code', $result['code']);
    }

    public function destroy(Request $request, InvitationCode $invitationCode): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess() && $invitationCode->tenant_id === $membership->tenant_id, 403);
        $invitationCode->update(['revoked_at' => now()]);

        return $request->is('api/*') ? response()->json(null, 204) : back();
    }
}
