<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSelectionController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $memberships = $workspace->memberships($request);

        if ($memberships->isEmpty()) {
            return redirect()->route('dashboard');
        }

        $activeMembershipId = $request->session()->get('workspace.membership_id');

        $businesses = $memberships->map(fn (TenantMembership $m) => [
            'id' => $m->id,
            'tenant_id' => $m->tenant_id,
            'tenant_name' => $m->tenant->name,
            'system_role' => $m->system_role,
            'status' => $m->status,
        ])->values();

        return Inertia::render('auth/select-workspace', [
            'businesses' => $businesses,
            'activeMembershipId' => $activeMembershipId,
        ]);
    }

    public function store(Request $request, CurrentWorkspace $workspace): RedirectResponse
    {
        $request->validate([
            'membership_id' => ['required', 'string'],
        ], [
            'membership_id.required' => 'Pilih salah satu workspace.',
        ]);

        $user = $request->user();
        $membership = $user?->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->find($request->input('membership_id'));

        if (! $membership instanceof TenantMembership) {
            throw ValidationException::withMessages([
                'membership_id' => ['Workspace / bisnis tidak tersedia untuk akun Anda.'],
            ]);
        }

        $request->session()->forget(['workspace.legal_entity_id', 'workspace.org_unit_id']);
        $workspace->activate($request, $membership);

        return redirect()->intended(config('fortify.home', '/dashboard'));
    }
}
