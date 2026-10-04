<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Access\Models\RoleAssignment;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\TenantProvisioned;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\TenantMembership;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsCoreRoles;

/**
 * Bahan uji dasbor analitik (area 6) di atas dataset sungguhan `management-aset.asset-register`: tenant dengan
 * module aset terpasang, organisasi, master aset, aset, dan anggota dengan duty serta hibah kebijakan aset
 * lewat rantai izin sungguhan. Bentuknya sama dengan bahan `WalkingSkeletonTest`.
 *
 * Aset disisipkan langsung ke tabel module hanya sebagai data awal; Core tidak menulis tabel module di jalur
 * yang diuji.
 */
trait BuildsAssetTenants
{
    use GrantsCoreRoles;

    protected const ASSET_DATASET = 'management-aset.asset-register';

    protected const ASSET_POLICY = 'management-aset.asset-responsibility';

    protected function prepareAssetModule(): void
    {
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->artisan('app:register-manifest', ['module' => 'management-aset'])->assertSuccessful();
        Event::fake([TenantProvisioned::class]);
    }

    /** Tenant baru dengan module aset terpasang; memulangkan pemiliknya (role Owner). */
    protected function business(string $name, string $email): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => $name, 'app_ids' => ['management-aset'], 'email' => $email, 'password' => 'password',
        ]);
    }

    protected function membershipOf(User $user): TenantMembership
    {
        $membership = $user->activeMembership();
        $this->assertNotNull($membership);

        return $membership;
    }

    /**
     * Anggota tenant dengan duty ini saja, dan hibah kebijakan aset per pasangan legal entity dan unit.
     *
     * @param  list<string>  $duties
     * @param  list<array{0: ?string, 1: ?string}>  $grants
     */
    protected function member(string $tenantId, array $duties, array $grants = []): User
    {
        $user = User::factory()->create();
        $membership = TenantMembership::query()->create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'status' => 'active']);
        $this->grantDuties($membership, $duties);

        $assignment = RoleAssignment::query()->where('membership_id', $membership->id)->firstOrFail();
        foreach ($grants as [$legalEntity, $unit]) {
            $assignment->dataPolicyScopes()->create([
                'tenant_id' => $tenantId, 'policy_code' => self::ASSET_POLICY,
                'legal_entity_id' => $legalEntity, 'organization_id' => $unit,
                'hierarchy_id' => null, 'hierarchy_version_id' => null, 'include_descendants' => false,
                'valid_from' => now()->subMinute(),
            ]);
        }

        return $user;
    }

    protected function organization(string $tenantId, string $classification, string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'classification' => $classification,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($classification === 'legal_entity') {
            DB::table('legal_entities')->insert([
                'organization_id' => $id, 'company_code' => Str::upper(Str::random(4)), 'country_code' => 'ID',
                'timezone' => 'Asia/Jakarta', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** Satu aset IDR di unit ini, dengan master group dan jenis baru milik tenant itu. */
    protected function asset(string $tenantId, string $legalEntity, string $unit, string $value, string $status = 'received'): void
    {
        $master = static function (string $table) use ($tenantId): string {
            $id = (string) Str::ulid();
            $code = 'M-'.Str::upper(Str::random(6));
            DB::table($table)->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'creation_key' => 'seed-'.Str::ulid(),
                'kode' => $code, 'nama' => $code, 'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        };

        DB::table('aset_tr_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'AST-'.Str::upper(Str::random(6)), 'nama' => 'Aset uji', 'legal_entity_id' => $legalEntity,
            'responsible_org_unit_id' => $unit, 'financial_dimension_org_unit_id' => null,
            'group_aset_id' => $master('aset_m_group_aset'), 'jenis_aset_id' => $master('aset_m_jenis_aset'),
            'acquired_on' => '2026-09-01', 'acquisition_value' => $value, 'currency_code' => 'IDR',
            'lifecycle_state' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, string> header `If-Match` untuk versi ini */
    protected static function ifMatch(int $version): array
    {
        return ['If-Match' => 'W/"'.$version.'"'];
    }
}
