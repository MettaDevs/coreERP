<?php

namespace App\Http\Controllers\Access;

use App\Actions\Access\UpsertRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\RoleRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);

        return response()->json(['data' => Role::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('is_active', true)
            ->with('duties', 'children:id,name')
            ->get()]);
    }

    public function store(RoleRequest $request, UpsertRole $action): JsonResponse|RedirectResponse
    {
        $role = $action->handle($this->currentMembership($request), $request->payload());

        return $this->response($request, $role, 201);
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($role->tenant_id === $membership->tenant_id, 404);

        return response()->json(['data' => $role->load('duties', 'children:id,name')]);
    }

    public function update(RoleRequest $request, Role $role, UpsertRole $action): JsonResponse|RedirectResponse
    {
        return $this->response($request, $action->handle($this->currentMembership($request), $request->payload(), $role));
    }

    public function destroy(Request $request, Role $role): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess() && $role->tenant_id === $membership->tenant_id, 403);
        $role->delete();

        return $request->is('api/*') ? response()->json(null, 204) : back();
    }

    private function response(Request $request, Role $role, int $status = 200): JsonResponse|RedirectResponse
    {
        return $request->is('api/*')
            ? response()->json(['data' => $role], $status)
            : back()->with('status', 'Role saved.');
    }
}
