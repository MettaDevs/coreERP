<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Modules;

use App\Foundation\NumberSequence\Models\NumberSequenceReference;
use App\Foundation\NumberSequence\Models\TenantNumberSequence;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\FiscalCalendarDirectory;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;
use App\Platform\Modules\Contracts\OrganizationDirectory;
use App\Platform\Modules\Support\CoreServices;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Module memanggil Core lewat satu pintu resmi, dan pintu itu menerima id.
 *
 * Yang diuji di sini bukan logika Core — itu sudah punya testnya sendiri. Yang diuji adalah
 * bahwa pembungkusnya benar-benar meneruskan, dan bahwa antarmukanya cukup untuk dipakai
 * tanpa menyentuh satu pun model Core.
 */
class CoreServicesForModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_semua_antarmuka_terpasang_di_wadah(): void
    {
        foreach (CoreServices::contracts() as $antarmuka) {
            $this->assertInstanceOf($antarmuka, $this->app->make($antarmuka), $antarmuka.' belum terpasang.');
        }
    }

    public function test_module_menerbitkan_nomor_lewat_antarmuka_tanpa_menyentuh_model_core(): void
    {
        [$konteks] = $this->sequenceContoh();

        $hasil = $this->app->make(NumberSequenceIssuer::class)
            ->issue($konteks, 'sample-app.document', (string) Str::ulid());

        $this->assertSame('issued', $hasil['status']);
        $this->assertSame('000001', $hasil['number']);
    }

    public function test_penerbitan_dengan_kunci_idempoten_yang_sama_tidak_menghasilkan_nomor_kedua(): void
    {
        [$konteks] = $this->sequenceContoh();
        $kunci = (string) Str::ulid();
        $penerbit = $this->app->make(NumberSequenceIssuer::class);

        $pertama = $penerbit->issue($konteks, 'sample-app.document', $kunci);
        $kedua = $penerbit->issue($konteks, 'sample-app.document', $kunci);

        $this->assertSame($pertama['number'], $kedua['number']);
    }

    public function test_pencadangan_nomor_juga_lewat_antarmuka_yang_sama(): void
    {
        // Pencadangan hanya berlaku untuk sequence berurutan tanpa lompatan; yang tidak
        // berurutan memang menolaknya, dan penolakan itu perilaku Core, bukan cacat kontrak.
        [$konteks] = $this->sequenceContoh(berurutan: true);

        $hasil = $this->app->make(NumberSequenceIssuer::class)
            ->reserve($konteks, 'sample-app.document', (string) Str::ulid());

        $this->assertArrayHasKey('number', $hasil);
    }

    public function test_kalender_fiskal_menolak_id_entitas_legal_yang_tidak_ada(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->app->make(FiscalCalendarDirectory::class)->period((string) Str::ulid(), '2026-09-08');
    }

    public function test_direktori_organisasi_mengembalikan_baris_biasa_bukan_model_core(): void
    {
        [$konteks] = $this->sequenceContoh();

        $unit = $this->app->make(OrganizationDirectory::class)->operatingUnits($konteks['tenant_id']);

        $this->assertSame([], $unit);

        DB::table('organizations')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $konteks['tenant_id'],
            'name' => 'Cabang Timur',
            'classification' => 'operating_unit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unit = $this->app->make(OrganizationDirectory::class)->operatingUnits($konteks['tenant_id']);

        $this->assertCount(1, $unit);
        $this->assertSame(['id', 'nama', 'klasifikasi', 'tipe', 'nomor'], array_keys($unit[0]));
        $this->assertIsString($unit[0]['id']);
    }

    public function test_membership_lookup_uses_membership_ids_and_batch_queries(): void
    {
        [$context] = $this->sequenceContoh();
        $tenant = $context['tenant_id'];
        $active = [];
        for ($i = 0; $i < 25; $i++) {
            $user = User::factory()->create(['name' => 'Member '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
            $active[] = (string) TenantMembership::create(['tenant_id' => $tenant, 'user_id' => $user->id, 'status' => 'active'])->id;
        }
        $inactiveUser = User::factory()->create();
        $inactive = (string) TenantMembership::create(['tenant_id' => $tenant, 'user_id' => $inactiveUser->id, 'status' => 'inactive'])->id;
        $directory = $this->app->make(OrganizationDirectory::class);
        DB::enableQueryLog();
        try {
            foreach ([1, 10, 25] as $size) {
                DB::flushQueryLog();
                $found = $directory->membersById($tenant, [...array_slice($active, 0, $size), $inactive]);
                $this->assertCount($size, $found);
                $this->assertSame([], array_diff(array_keys($found), $active));
                $this->assertCount(1, DB::getQueryLog());
            }
            DB::flushQueryLog();
            $this->assertSame([], $directory->membersById($tenant, []));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $batch = $directory->lookup($tenant, 'memberships', ['per_page' => 10]);
        $this->assertCount(10, $batch['data']);
        $this->assertContains((string) $batch['data'][0]->id, $active);
        $this->assertNotNull($batch['next_cursor']);
        $email = $batch['data'][0]->email;
        $matched = $directory->lookup($tenant, 'memberships', ['email' => strtoupper($email)]);
        $this->assertCount(1, $matched['data']);
        $this->assertSame($email, $matched['data'][0]->email);
        $publicLabels = $directory->lookup($tenant, 'members', ['per_page' => 10]);
        $this->assertCount(10, $publicLabels['data']);
        foreach ($publicLabels['data'] as $row) {
            $this->assertFalse(property_exists($row, 'email'));
        }
        $this->assertSame([], $directory->membersById((string) Str::ulid(), $active));
    }

    /**
     * Satu tenant dengan satu referensi nomor yang siap dipakai.
     *
     * @return array{0: array{tenant_id: string, app_id: string}}
     */
    private function sequenceContoh(bool $berurutan = false): array
    {
        $tenantId = (string) Str::ulid();
        $appId = 'sample-app';
        $clientId = (string) Str::ulid();

        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'Klien Contoh', 'slug' => 'klien-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => 'Tenant Contoh', 'slug' => 'tenant-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('apps')->insert(['id' => $appId, 'name' => 'Sample app', 'version' => '1.0.0', 'status' => 'available', 'database_name' => 'sample_app', 'created_at' => now(), 'updated_at' => now()]);

        $reference = NumberSequenceReference::query()->create([
            'app_id' => $appId,
            'code' => 'sample-app.document',
            'name' => 'Nomor dokumen',
            'allowed_scopes' => ['tenant', 'legal_entity', 'operating_unit'],
        ]);

        TenantNumberSequence::query()->create([
            'tenant_id' => $tenantId,
            'reference_id' => $reference->id,
            'profile_code' => $berurutan ? 'continuous-strict' : 'non-continuous-default',
            'scope_type' => 'tenant',
            'status' => 'active',
            'is_continuous' => $berurutan,
            'allow_manual' => false,
            'reset_period' => 'never',
            'preallocation_enabled' => true,
            'preallocation_quantity' => 20,
            'minimum_number' => 1,
            'segments' => [['type' => 'number', 'length' => 6]],
        ]);

        return [['tenant_id' => $tenantId, 'app_id' => $appId]];
    }
}
