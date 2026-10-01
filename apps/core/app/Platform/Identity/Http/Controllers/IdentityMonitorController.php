<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IdentityMonitorController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('monitor-identities'), 403);
        $search = trim((string) $request->query('search'));
        // Akun aplikasi klien integrasi bukan identitas yang dapat masuk; ia tidak dipantau di sini.
        $identities = User::query()
            ->where('account_type', User::PERSON)
            ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->with(['memberships.tenant:id,name', 'memberships.roleAssignments' => fn ($query) => $query->where('status', 'active'), 'memberships.roleAssignments.role:id,name'])
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at,
                'last_login_at' => $user->last_login_at,
                'memberships' => $user->memberships->map(fn (TenantMembership $membership) => [
                    'id' => $membership->id,
                    'tenant' => $membership->tenant->name,
                    // Owner dan admin bukan lagi penanda keanggotaan (SEC-22); yang menjelaskan hak seseorang adalah role-nya.
                    'roles' => $membership->roleAssignments->map(fn ($assignment) => $assignment->role->name)->filter()->unique()->values()->all(),
                    'status' => $membership->status,
                ])->values()->all(),
            ]);

        if ($request->is('api/*')) {
            abort(500, 'API route must use apiIndex.');
        }

        return Inertia::render('platform/identity/identities', ['identities' => $identities, 'filters' => ['search' => $search]]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('monitor-identities'), 403);

        $users = User::query()->where('account_type', User::PERSON)->with(['memberships.tenant:id,name'])->latest()->paginate(20);

        return response()->json($users);
    }
}
