<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\SecurityDuty;
use App\Models\SecurityPrivilege;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SecurityConfigurationController extends Controller
{
    public function index(Request $request): Response
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $appIds = $this->entitledAppIds($membership);

        return Inertia::render('settings/security-configuration', [
            'permissions' => Permission::query()
                ->whereIn('app_id', $appIds)
                ->with('entryPoint:code,name,type')
                ->orderBy('app_id')->orderBy('name')->get()
                ->map(fn (Permission $permission) => [
                    'code' => $permission->code,
                    'name' => $permission->name,
                    'app_id' => $permission->app_id,
                    'access_level' => $permission->access_level,
                    'entry_point' => $permission->entryPoint?->only(['code', 'name', 'type']),
                ])->values(),
            'privileges' => SecurityPrivilege::query()
                ->where(function ($query) use ($membership, $appIds): void {
                    $query->whereIn('app_id', $appIds)->orWhere('tenant_id', $membership->tenant_id);
                })
                ->with('permissions:code,name,entry_point_code,access_level')
                ->orderBy('source')->orderBy('name')->get()
                ->map(fn (SecurityPrivilege $privilege) => $this->privilegeData($privilege))->values(),
            'duties' => SecurityDuty::query()
                ->where(function ($query) use ($membership, $appIds): void {
                    $query->whereIn('app_id', $appIds)->orWhere('tenant_id', $membership->tenant_id);
                })
                ->with('privileges:code,name,app_id,tenant_id,source,status')
                ->orderBy('source')->orderBy('name')->get()
                ->map(fn (SecurityDuty $duty) => $this->dutyData($duty))->values(),
        ]);
    }

    public function storePrivilege(Request $request): RedirectResponse
    {
        $membership = $this->manager($request);
        $permissionCodes = $this->permissionCodes($request, $membership);
        $privilege = SecurityPrivilege::create([
            'code' => $this->customCode($membership->tenant_id),
            'tenant_id' => $membership->tenant_id,
            'name' => $request->string('name')->toString(),
            'source' => 'custom', 'status' => 'draft',
        ]);
        $privilege->permissions()->sync($permissionCodes);

        return back()->with('status', 'Tugas akses disimpan sebagai draf.');
    }

    public function updatePrivilege(Request $request, string $privilege): RedirectResponse
    {
        $membership = $this->manager($request);
        $item = $this->customPrivilege($privilege, $membership);
        $this->draftOnly($item->status);
        $item->update(['name' => $request->validate(['name' => ['required', 'string', 'max:120']])['name']]);
        $item->permissions()->sync($this->permissionCodes($request, $membership));

        return back()->with('status', 'Draf tugas akses diperbarui.');
    }

    public function publishPrivilege(Request $request, string $privilege): RedirectResponse
    {
        $item = $this->customPrivilege($privilege, $this->manager($request));
        $this->draftOnly($item->status);
        abort_if($item->permissions()->doesntExist(), 422, 'Pilih sedikitnya satu izin sebelum menerbitkan tugas akses.');
        $item->update(['status' => 'active', 'published_at' => now()]);

        return back()->with('status', 'Tugas akses diterbitkan.');
    }

    public function storeDuty(Request $request): RedirectResponse
    {
        $membership = $this->manager($request);
        $privilegeCodes = $this->privilegeCodes($request, $membership);
        $duty = SecurityDuty::create([
            'code' => $this->customCode($membership->tenant_id),
            'tenant_id' => $membership->tenant_id,
            'name' => $request->string('name')->toString(),
            'source' => 'custom', 'status' => 'draft',
        ]);
        $duty->privileges()->sync($privilegeCodes);

        return back()->with('status', 'Tanggung jawab disimpan sebagai draf.');
    }

    public function updateDuty(Request $request, string $duty): RedirectResponse
    {
        $membership = $this->manager($request);
        $item = $this->customDuty($duty, $membership);
        $this->draftOnly($item->status);
        $item->update(['name' => $request->validate(['name' => ['required', 'string', 'max:120']])['name']]);
        $item->privileges()->sync($this->privilegeCodes($request, $membership));

        return back()->with('status', 'Draf tanggung jawab diperbarui.');
    }

    public function publishDuty(Request $request, string $duty): RedirectResponse
    {
        $membership = $this->manager($request);
        $item = $this->customDuty($duty, $membership);
        $this->draftOnly($item->status);
        $draftPrivilege = $item->privileges()->where('source', 'custom')->where('status', '!=', 'active')->exists();
        abort_if($draftPrivilege, 422, 'Terbitkan seluruh tugas akses di dalam tanggung jawab ini terlebih dahulu.');
        $item->update(['status' => 'active', 'published_at' => now()]);

        return back()->with('status', 'Tanggung jawab diterbitkan dan siap dipakai pada role.');
    }

    private function manager(Request $request): TenantMembership
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);

        return $membership;
    }

    /** @return list<string> */
    private function permissionCodes(Request $request, TenantMembership $membership): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'permission_codes' => ['required', 'array', 'min:1'],
            'permission_codes.*' => ['required', 'string', Rule::exists('permissions', 'code')],
        ]);
        $codes = array_values(array_unique($data['permission_codes']));
        $count = Permission::query()->whereIn('code', $codes)->whereIn('app_id', $this->entitledAppIds($membership))->count();
        if ($count !== count($codes)) {
            throw ValidationException::withMessages(['permission_codes' => 'Izin harus berasal dari aplikasi yang dapat digunakan tenant.']);
        }

        return $codes;
    }

    /** @return list<string> */
    private function privilegeCodes(Request $request, TenantMembership $membership): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'privilege_codes' => ['required', 'array', 'min:1'],
            'privilege_codes.*' => ['required', 'string', Rule::exists('security_privileges', 'code')],
        ]);
        $codes = array_values(array_unique($data['privilege_codes']));
        $count = SecurityPrivilege::query()->whereIn('code', $codes)
            ->where(function ($query) use ($membership): void {
                $query->whereIn('app_id', $this->entitledAppIds($membership))
                    ->orWhere('tenant_id', $membership->tenant_id);
            })->count();
        if ($count !== count($codes)) {
            throw ValidationException::withMessages(['privilege_codes' => 'Tugas akses harus berasal dari aplikasi tenant atau konfigurasi tenant ini.']);
        }

        return $codes;
    }

    /** @return list<string> */
    private function entitledAppIds(TenantMembership $membership): array
    {
        return DB::table('tenant_app_entitlements')->where('tenant_id', $membership->tenant_id)
            ->where('status', 'active')->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('app_id')->all();
    }

    private function customPrivilege(string $code, TenantMembership $membership): SecurityPrivilege
    {
        return SecurityPrivilege::query()->where(['code' => $code, 'tenant_id' => $membership->tenant_id, 'source' => 'custom'])->firstOrFail();
    }

    private function customDuty(string $code, TenantMembership $membership): SecurityDuty
    {
        return SecurityDuty::query()->where(['code' => $code, 'tenant_id' => $membership->tenant_id, 'source' => 'custom'])->firstOrFail();
    }

    private function draftOnly(string $status): void
    {
        abort_unless($status === 'draft', 422, 'Konfigurasi yang sudah diterbitkan tidak dapat diubah. Buat draf baru untuk perubahan.');
    }

    private function customCode(string $tenantId): string
    {
        return 'custom.'.$tenantId.'.'.Str::ulid();
    }

    /** @return array<string, mixed> */
    private function privilegeData(SecurityPrivilege $item): array
    {
        return ['code' => $item->code, 'name' => $item->name, 'app_id' => $item->app_id, 'source' => $item->source, 'status' => $item->status,
            'permissions' => $item->permissions->map(fn (Permission $permission) => $permission->only(['code', 'name', 'entry_point_code', 'access_level']))->values()];
    }

    /** @return array<string, mixed> */
    private function dutyData(SecurityDuty $item): array
    {
        return ['code' => $item->code, 'name' => $item->name, 'app_id' => $item->app_id, 'source' => $item->source, 'status' => $item->status,
            'privileges' => $item->privileges->map(fn (SecurityPrivilege $privilege) => $privilege->only(['code', 'name', 'app_id', 'tenant_id', 'source', 'status']))->values()];
    }
}
