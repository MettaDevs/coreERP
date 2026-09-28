<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `system_role` (owner/admin/user) dihapus dari keanggotaan dan kode undangan (SEC-22, TODO feed posting 7.4).
 * Keputusan pemilik produk, 25 September 2026: tidak ada lagi owner dan admin di luar rantai security role;
 * role Owner bawaan memegang semua duty, dan hak mengelola tenant datang dari duty Core seperti hak lain.
 *
 * Sebelum kolomnya dibuang, setiap anggota aktif yang dulu `owner` atau `admin` mendapat role Owner tenantnya —
 * termasuk cakupan data semua kebijakan app yang dibeli, sama seperti owner saat tenant lahir — supaya tidak ada
 * yang kehilangan akses yang dulu dipegangnya. Tenant yang punya owner/admin tetapi belum punya role Owner
 * mendapatkannya lebih dulu.
 *
 * @kontrak Keputusan pemilik produk 25 September 2026: `system_role` dibuang tanpa menunggu satu rilis. Belum
 * ada pelanggan di CoreERP; rilis yang memuat migration ini tidak dapat dibatalkan ke rilis yang masih
 * membaca `system_role` (0.8.0 dan sebelumnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $holders = DB::table('tenant_memberships')
            ->whereIn('system_role', ['owner', 'admin'])
            ->where('status', 'active')
            ->orderBy('created_at')
            ->get(['id', 'tenant_id']);

        foreach ($holders->groupBy('tenant_id') as $tenantId => $members) {
            $ownerRoleId = DB::table('roles')->where('tenant_id', $tenantId)->where('is_owner', true)->value('id');
            if ($ownerRoleId === null) {
                $ownerRoleId = (string) Str::ulid();
                DB::table('roles')->insert([
                    'id' => $ownerRoleId, 'tenant_id' => $tenantId, 'name' => 'Owner',
                    'is_active' => true, 'is_owner' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $policyCodes = DB::table('app_data_policies')->whereIn('app_id', $this->appIds((string) $tenantId))->pluck('code');

            foreach ($members as $membership) {
                $alreadyHolds = DB::table('role_assignments')
                    ->where('membership_id', $membership->id)
                    ->where('role_id', $ownerRoleId)
                    ->where('status', 'active')
                    ->exists();
                if ($alreadyHolds) {
                    continue;
                }

                $assignmentId = (string) Str::ulid();
                DB::table('role_assignments')->insert([
                    'id' => $assignmentId, 'membership_id' => $membership->id, 'role_id' => $ownerRoleId,
                    'source' => 'manual', 'status' => 'active', 'valid_from' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($policyCodes as $policyCode) {
                    DB::table('role_assignment_data_policy_scopes')->insert([
                        'id' => (string) Str::ulid(), 'role_assignment_id' => $assignmentId, 'tenant_id' => $tenantId,
                        'policy_code' => $policyCode, 'legal_entity_id' => null, 'organization_id' => null, 'hierarchy_id' => null,
                        'hierarchy_version_id' => null, 'include_descendants' => false, 'valid_from' => $now,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        $this->syncOwnerRoles();

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropColumn('system_role');
        });
        Schema::table('invitation_codes', function (Blueprint $table): void {
            $table->dropColumn('system_role');
        });
    }

    /**
     * Kolomnya kembali; pemegang role Owner menjadi `owner`, selain itu `user`. `admin` tidak dapat dipulihkan —
     * sesudah `up()`, admin dan owner sama-sama pemegang role Owner.
     */
    public function down(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->string('system_role', 20)->default('user');
        });
        Schema::table('invitation_codes', function (Blueprint $table): void {
            $table->string('system_role', 20)->default('user');
        });

        DB::table('tenant_memberships')
            ->whereIn('id', DB::table('role_assignments')
                ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
                ->where('roles.is_owner', true)
                ->where('role_assignments.status', 'active')
                ->select('role_assignments.membership_id'))
            ->update(['system_role' => 'owner']);
    }

    /**
     * App yang boleh dipakai tenant: `core` ditambah entitlement aktifnya. Aturan yang sama dengan
     * `TenantProducts::appIds()`, ditulis ulang di sini karena migration tidak memakai kelas aplikasi — admin.erp
     * menjalankannya tanpa kelas `App\`.
     *
     * @return list<string>
     */
    private function appIds(string $tenantId): array
    {
        $entitled = DB::table('tenant_app_entitlements')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('app_id')
            ->map(strval(...))
            ->all();

        return array_values(array_unique(['core', ...$entitled]));
    }

    /** Owner memegang semua duty yang sah untuk tenantnya, seperti `OwnerRoleDuties`. */
    private function syncOwnerRoles(): void
    {
        foreach (DB::table('roles')->where('is_owner', true)->get(['id', 'tenant_id']) as $role) {
            $duties = DB::table('security_duties')
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->whereNull('tenant_id')->whereIn('app_id', $this->appIds((string) $role->tenant_id)))
                    ->orWhere(fn ($query) => $query->where('tenant_id', $role->tenant_id)->where('source', 'custom')->where('status', 'active')))
                ->pluck('code');

            DB::table('security_role_duties')->where('role_id', $role->id)->whereNotIn('duty_code', $duties)->delete();
            DB::table('security_role_duties')->insertOrIgnore(
                $duties->map(fn (string $code): array => ['role_id' => $role->id, 'duty_code' => $code])->all(),
            );
        }
    }
};
