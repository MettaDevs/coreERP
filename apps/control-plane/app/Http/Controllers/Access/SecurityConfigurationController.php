<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\CoreApp;
use App\Models\Permission;
use App\Models\Role;
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
        $tenantId = $membership->tenant_id;

        // Graf dikirim ternormalisasi: setiap tingkat hanya membawa kode anaknya.
        // Referensi arah balik ("dipakai oleh") dihitung di klien dari susunan
        // yang sama, sehingga tidak ada query tambahan dan tidak ada state
        // seleksi yang disimpan di server.
        return Inertia::render('settings/security-configuration', [
            'canManage' => $membership->canManageAccess(),
            'apps' => CoreApp::query()->whereIn('id', $appIds)
                ->orderBy('name')->get(['id', 'name']),
            'roles' => Role::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->with(['duties:code', 'children:id,name', 'parents:id,name'])
                ->orderBy('name')->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'duty_codes' => $role->duties->pluck('code')->values(),
                    'child_roles' => $role->children->map(fn (Role $child) => $child->only(['id', 'name']))->values(),
                    'parent_roles' => $role->parents->map(fn (Role $parent) => $parent->only(['id', 'name']))->values(),
                ])->values(),
            'duties' => SecurityDuty::query()
                ->where(fn ($query) => $this->visibleTo($query, $appIds, $tenantId))
                ->with('privileges:code')
                ->orderBy('name')->get()
                ->map(fn (SecurityDuty $duty) => [
                    'code' => $duty->code,
                    'name' => $duty->name,
                    'description' => $duty->description,
                    'app_id' => $duty->app_id,
                    'source' => $duty->source,
                    'status' => $duty->status,
                    'privilege_codes' => $duty->privileges->pluck('code')->values(),
                ])->values(),
            'privileges' => SecurityPrivilege::query()
                ->where(fn ($query) => $this->visibleTo($query, $appIds, $tenantId))
                ->with('permissions:code')
                ->orderBy('name')->get()
                ->map(fn (SecurityPrivilege $privilege) => [
                    'code' => $privilege->code,
                    'name' => $privilege->name,
                    'description' => $privilege->description,
                    'app_id' => $privilege->app_id,
                    'source' => $privilege->source,
                    'status' => $privilege->status,
                    'permission_codes' => $privilege->permissions->pluck('code')->values(),
                ])->values(),
            'permissions' => Permission::query()
                ->whereIn('app_id', $appIds)
                ->with('entryPoint:code,name,type')
                ->orderBy('name')->get()
                ->map(fn (Permission $permission) => [
                    'code' => $permission->code,
                    'name' => $permission->name,
                    'description' => $permission->description,
                    'app_id' => $permission->app_id,
                    'access_level' => $permission->access_level,
                    'entry_point' => $permission->entryPoint?->only(['code', 'name', 'type']),
                ])->values(),
        ]);
    }

    /**
     * Objek yang boleh dilihat tenant: bawaan aplikasi yang dientitle, atau
     * konfigurasi milik tenant itu sendiri.
     *
     * @param  list<string>  $appIds
     */
    private function visibleTo(mixed $query, array $appIds, string $tenantId): void
    {
        $query->whereIn('app_id', $appIds)->orWhere('tenant_id', $tenantId);
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

    /**
     * Menyalin tugas akses bawaan aplikasi menjadi draf milik tenant. Ini cara
     * tenant menyempitkan hak tanpa mengubah objek yang diterbitkan aplikasi.
     */
    public function duplicatePrivilege(Request $request, string $privilege): RedirectResponse
    {
        $membership = $this->manager($request);
        $source = $this->visiblePrivilege($privilege, $membership);
        $copy = SecurityPrivilege::create([
            'code' => $this->customCode($membership->tenant_id),
            'tenant_id' => $membership->tenant_id,
            'name' => $this->copyName($source->name),
            'description' => $source->description,
            'source' => 'custom', 'status' => 'draft',
        ]);
        $copy->permissions()->sync($source->permissions->pluck('code')->all());

        return back()->with('status', 'Salinan tugas akses dibuat sebagai draf.');
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

    /** Draf boleh dibuang. Yang sudah diterbitkan tidak, karena role memakainya. */
    public function destroyPrivilege(Request $request, string $privilege): RedirectResponse
    {
        $item = $this->customPrivilege($privilege, $this->manager($request));
        $this->draftOnly($item->status);
        $item->permissions()->detach();
        $item->delete();

        return back()->with('status', 'Draf tugas akses dihapus.');
    }

    public function destroyDuty(Request $request, string $duty): RedirectResponse
    {
        $item = $this->customDuty($duty, $this->manager($request));
        $this->draftOnly($item->status);
        $item->privileges()->detach();
        $item->delete();

        return back()->with('status', 'Draf tanggung jawab dihapus.');
    }

    public function duplicateDuty(Request $request, string $duty): RedirectResponse
    {
        $membership = $this->manager($request);
        $source = $this->visibleDuty($duty, $membership);
        $copy = SecurityDuty::create([
            'code' => $this->customCode($membership->tenant_id),
            'tenant_id' => $membership->tenant_id,
            'name' => $this->copyName($source->name),
            'description' => $source->description,
            'source' => 'custom', 'status' => 'draft',
        ]);
        $copy->privileges()->sync($source->privileges->pluck('code')->all());

        return back()->with('status', 'Salinan tanggung jawab dibuat sebagai draf.');
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

    /** Sumber salinan boleh bawaan aplikasi maupun milik tenant sendiri. */
    private function visiblePrivilege(string $code, TenantMembership $membership): SecurityPrivilege
    {
        return SecurityPrivilege::query()
            ->where('code', $code)
            ->where(fn ($query) => $this->visibleTo($query, $this->entitledAppIds($membership), $membership->tenant_id))
            ->with('permissions:code')
            ->firstOrFail();
    }

    private function visibleDuty(string $code, TenantMembership $membership): SecurityDuty
    {
        return SecurityDuty::query()
            ->where('code', $code)
            ->where(fn ($query) => $this->visibleTo($query, $this->entitledAppIds($membership), $membership->tenant_id))
            ->with('privileges:code')
            ->firstOrFail();
    }

    private function copyName(string $name): string
    {
        return Str::limit($name.' (salinan)', 120, '');
    }
}
