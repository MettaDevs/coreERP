<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Models\User;
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
        $identities = User::query()
            ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->with(['memberships.tenant:id,name'])
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
                    'tenant' => $membership->tenant->name,
                    'system_role' => $membership->system_role,
                    'status' => $membership->status,
                ])->values()->all(),
            ]);

        if ($request->is('api/*')) {
            abort(500, 'API route must use apiIndex.');
        }

        return Inertia::render('control/identities', ['identities' => $identities, 'filters' => ['search' => $search]]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('monitor-identities'), 403);

        $users = User::query()->with(['memberships.tenant:id,name'])->latest()->paginate(20);

        return response()->json($users);
    }
}
