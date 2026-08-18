<?php

namespace App\Http\Controllers\Organization;

use App\Actions\Onboarding\RegisterBusiness;
use App\Http\Controllers\Controller;
use App\Models\CoreApp;
use App\Models\TenantMembership;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $activeMembershipId = $request->session()->get('workspace.membership_id');

        $businesses = $memberships->map(fn (TenantMembership $m) => [
            'id' => $m->id,
            'tenant_id' => $m->tenant_id,
            'tenant_name' => $m->tenant?->name ?? 'Bisnis',
            'system_role' => $m->system_role,
            'status' => $m->status,
        ])->values();

        $availableApps = CoreApp::query()
            ->where('status', 'available')
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (CoreApp $app): array => [
                'id' => $app->id,
                'name' => $app->name,
                'description' => $app->description ?? '',
            ])->values();

        return Inertia::render('auth/select-workspace', [
            'businesses' => $businesses,
            'activeMembershipId' => $activeMembershipId,
            'maxBusinesses' => (int) config('coreerp.max_businesses_per_user', 3),
            'availableApps' => $availableApps,
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

    public function createBusiness(Request $request, RegisterBusiness $registerBusiness, CurrentWorkspace $workspace): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $max = (int) config('coreerp.max_businesses_per_user', 3);
        $ownedCount = $user->memberships()->where('system_role', 'owner')->count();

        if ($ownedCount >= $max) {
            throw ValidationException::withMessages([
                'business_name' => ["Batas maksimal bisnis ({$max}) untuk akun Anda telah tercapai."],
            ]);
        }

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:35'],
            'app_ids' => ['required', 'array', 'min:1'],
            'app_ids.*' => ['required', 'string', 'distinct', 'exists:apps,id'],
        ], [
            'business_name.required' => 'Nama bisnis / perusahaan wajib diisi.',
            'business_name.min' => 'Nama bisnis / perusahaan minimal 2 karakter.',
            'business_name.max' => 'Nama bisnis / perusahaan maksimal 35 karakter.',
            'app_ids.required' => 'Pilih minimal 1 modul aplikasi.',
            'app_ids.min' => 'Pilih minimal 1 modul aplikasi.',
        ]);

        $businessName = trim($validated['business_name']);

        $existingUserBusiness = $user->memberships()
            ->whereHas('tenant', fn ($q) => $q->whereRaw('LOWER(name) = ?', [Str::lower($businessName)]))
            ->exists();

        if ($existingUserBusiness) {
            throw ValidationException::withMessages([
                'business_name' => ['Anda sudah memiliki bisnis dengan nama ini. Silakan gunakan nama lain.'],
            ]);
        }

        $membership = $registerBusiness->createForUser($user, [
            'business_name' => $businessName,
            'app_ids' => $validated['app_ids'],
        ]);

        $request->session()->forget(['workspace.legal_entity_id', 'workspace.org_unit_id']);
        $workspace->activate($request, $membership);

        return redirect()->intended(config('fortify.home', '/dashboard'));
    }

    public function destroy(Request $request, string $membershipId): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $membership = $user->memberships()
            ->with('tenant')
            ->where('id', $membershipId)
            ->first();

        if (! $membership instanceof TenantMembership) {
            throw ValidationException::withMessages([
                'membership' => ['Bisnis tidak ditemukan atau Anda tidak memiliki akses.'],
            ]);
        }

        if ($membership->system_role !== 'owner') {
            throw ValidationException::withMessages([
                'membership' => ['Hanya pemilik (Owner) yang dapat menghapus bisnis.'],
            ]);
        }

        $tenant = $membership->tenant;
        $tenantName = $tenant?->name ?? 'Bisnis';

        \Illuminate\Support\Facades\DB::transaction(function () use ($membership, $tenant, $request) {
            $activeMembershipId = $request->session()->get('workspace.membership_id');
            if ($activeMembershipId === $membership->id) {
                $request->session()->forget([
                    'workspace.membership_id',
                    'workspace.legal_entity_id',
                    'workspace.org_unit_id',
                ]);
            }

            if ($tenant) {
                \Illuminate\Support\Facades\DB::table('tenant_app_entitlements')
                    ->where('tenant_id', $tenant->id)
                    ->delete();

                \Illuminate\Support\Facades\DB::table('role_assignments')
                    ->where('membership_id', $membership->id)
                    ->delete();

                $membership->delete();

                $otherMembersCount = TenantMembership::where('tenant_id', $tenant->id)->count();
                if ($otherMembersCount === 0) {
                    $tenant->delete();
                }
            } else {
                $membership->delete();
            }
        });

        return redirect()->route('workspace.select')->with('status', "Bisnis {$tenantName} berhasil dihapus.");
    }
}
