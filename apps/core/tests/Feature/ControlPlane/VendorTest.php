<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\TenantNumberSequence;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Modules\Contracts\DaftarVendor;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Vendor master milik Core (area 2, K-06).
 */
class VendorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    private string $le;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        // Lihat IntegrationClientTest: hasil test tidak boleh bergantung pada domain dasar di `.env`.
        config(['coreerp.base_domain' => null]);
        $this->owner = $this->pemilik('owner@metta.test', 'PT Metta');
        $this->membership = $this->owner->activeMembership();
        $this->le = $this->legalEntity('PT Metta Sehat', 'META');
    }

    public function test_vendor_baru_bernomor_dari_urutan_core_dan_terdaftar_sebagai_peran_vendor(): void
    {
        $jawaban = $this->buat(['party_name' => 'PT Sarana Medika', 'tax_number' => '01.234.567.8-901.000'])
            ->assertCreated()
            ->assertJsonPath('data.number', 'VND-000001')
            ->assertJsonPath('data.name', 'PT Sarana Medika')
            ->assertJsonPath('data.party_type', 'organization')
            ->assertJsonPath('data.status', 'active');
        $party = (string) $jawaban->json('data.party_id');

        $this->assertDatabaseHas('parties', ['id' => $party, 'tenant_id' => $this->membership->tenant_id, 'name' => 'PT Sarana Medika']);
        $this->assertDatabaseHas('party_role_registrations', [
            'tenant_id' => $this->membership->tenant_id, 'party_id' => $party, 'role_code' => 'vendor',
            'legal_entity_id' => $this->le, 'owning_app_id' => 'core',
        ]);
        $this->buat(['party_name' => 'CV Alkes Jaya'])->assertCreated()->assertJsonPath('data.number', 'VND-000002');

        $urutan = TenantNumberSequence::query()
            ->where('tenant_id', $this->membership->tenant_id)
            ->whereHas('reference', fn ($query) => $query->where('code', 'core.vendor'))
            ->firstOrFail();
        $this->assertSame('legal_entity', $urutan->scope_type);
        $this->assertTrue($urutan->allow_manual);
        $this->assertFalse($urutan->is_continuous);
    }

    public function test_referensi_nomor_core_dipasang_ulang_bila_barisnya_hilang(): void
    {
        // Baris app `core` dan referensinya ditulis migration, jadi ikut hilang bila tabel `apps`
        // dikosongkan — test ber-DatabaseTruncation melakukannya dengan TRUNCATE apps CASCADE,
        // dan kelas test sesudahnya di proses yang sama pernah gagal membuat vendor karenanya.
        DB::table('apps')->where('id', 'core')->delete();

        $this->buat(['party_name' => 'PT Sarana Medika'])->assertCreated()->assertJsonPath('data.number', 'VND-000001');

        $this->assertDatabaseHas('apps', ['id' => 'core', 'status' => 'internal']);
        $this->assertDatabaseHas('app_number_sequence_references', ['app_id' => 'core', 'code' => 'core.vendor', 'default_prefix' => 'VND']);
    }

    public function test_kiriman_ulang_dengan_kunci_yang_sama_tidak_membuat_vendor_kedua(): void
    {
        $pertama = $this->buat(['party_name' => 'PT Sarana Medika'], 'kunci-vendor-0001')->assertCreated();
        $kedua = $this->buat(['party_name' => 'PT Sarana Medika'], 'kunci-vendor-0001')->assertOk();

        $this->assertSame($pertama->json('data.id'), $kedua->json('data.id'));
        $this->assertSame(1, Vendor::query()->count());
        $this->assertSame(1, DB::table('parties')->where('name', 'PT Sarana Medika')->count());
        $this->assertSame(1, DB::table('number_sequence_issues')->where('formatted_value', 'VND-000001')->count());
    }

    public function test_nomor_manual_mengikuti_format_dan_unik_per_entitas_legal(): void
    {
        $leLain = $this->legalEntity('PT Metta Farma', 'MFAR');

        $this->buat(['party_name' => 'PT Sarana Medika', 'number' => 'vnd-000777'])
            ->assertCreated()->assertJsonPath('data.number', 'VND-000777');
        $this->buat(['party_name' => 'CV Alkes Jaya', 'number' => 'VND-000777'])
            ->assertStatus(422)->assertJsonValidationErrors('number');
        $this->buat(['party_name' => 'CV Alkes Jaya', 'number' => 'SUP-01'])
            ->assertStatus(422)->assertJsonValidationErrors('number');
        $this->buat(['legal_entity_id' => $leLain, 'party_name' => 'CV Alkes Jaya', 'number' => 'VND-000777'])
            ->assertCreated();

        $this->assertSame(2, Vendor::query()->where('number', 'VND-000777')->count());
        // Party dibuat di dalam transaksi yang sama: percobaan yang ditolak tidak meninggalkan party.
        $this->assertSame(1, DB::table('parties')->where('name', 'CV Alkes Jaya')->count());
    }

    public function test_format_nomor_dapat_disesuaikan_di_nomor_dokumen_sebelum_vendor_pertama(): void
    {
        // Urutannya lahir saat layar Nomor dokumen dibuka, jadi admin dapat menyesuaikan format
        // dengan nomor pemasok lama sebelum memindahkannya.
        $this->actingAs($this->owner)->get('/settings/number-sequences')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where(
                'sequences',
                fn ($urutan): bool => collect($urutan)->contains(fn (array $baris): bool => $baris['reference_code'] === 'core.vendor'
                    && $baris['app_name'] === 'CoreERP'),
            ));
        $urutan = TenantNumberSequence::query()
            ->where('tenant_id', $this->membership->tenant_id)
            ->whereHas('reference', fn ($query) => $query->where('code', 'core.vendor'))
            ->firstOrFail();

        $this->actingAs($this->owner)->patchJson("/api/v1/number-sequences/{$urutan->id}", [
            'profile_code' => 'manual-compatible', 'scope_type' => 'legal_entity', 'status' => 'active',
            'is_continuous' => false, 'allow_manual' => true, 'reset_period' => 'never',
            'preallocation_enabled' => true, 'preallocation_quantity' => 20,
            'minimum_number' => 1, 'maximum_number' => 99999,
            'segments' => [['type' => 'constant', 'value' => 'SUP'], ['type' => 'number', 'length' => 5]],
        ])->assertOk();

        $this->buat(['party_name' => 'PT Sarana Medika', 'number' => 'SUP00042'])
            ->assertCreated()->assertJsonPath('data.number', 'SUP00042');
        $this->buat(['party_name' => 'CV Alkes Jaya'])->assertCreated()->assertJsonPath('data.number', 'SUP00001');
    }

    public function test_pihak_yang_sama_sekali_per_entitas_legal_tetapi_boleh_di_entitas_lain(): void
    {
        $leLain = $this->legalEntity('PT Metta Farma', 'MFAR');
        $party = (string) $this->buat(['party_name' => 'PT Sarana Medika'])->assertCreated()->json('data.party_id');

        $this->buat(['party_id' => $party, 'party_name' => null])
            ->assertStatus(422)->assertJsonValidationErrors('party_id');
        $this->buat(['legal_entity_id' => $leLain, 'party_id' => $party, 'party_name' => null])
            ->assertCreated()
            // Urutan berlingkup entitas legal: setiap entitas mulai dari nomornya sendiri.
            ->assertJsonPath('data.number', 'VND-000001')
            ->assertJsonPath('data.name', 'PT Sarana Medika');

        $this->assertSame(1, DB::table('parties')->where('name', 'PT Sarana Medika')->count());
        $this->assertSame(2, DB::table('party_role_registrations')->where('party_id', $party)->where('role_code', 'vendor')->count());
    }

    public function test_mengubah_nama_mengubah_party_dan_nomor_tidak_ikut_berubah(): void
    {
        $vendor = $this->buat(['party_name' => 'PT Sarana Medika'])->assertCreated()->json('data');

        $this->actingAs($this->owner)->patchJson("/api/v1/vendors/{$vendor['id']}", [
            'name' => 'PT Sarana Medika Utama', 'tax_number' => '', 'status' => 'inactive', 'number' => 'VND-999999',
        ])->assertOk()
            ->assertJsonPath('data.name', 'PT Sarana Medika Utama')
            ->assertJsonPath('data.number', 'VND-000001')
            ->assertJsonPath('data.tax_number', null)
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('parties', ['id' => $vendor['party_id'], 'name' => 'PT Sarana Medika Utama']);
    }

    public function test_anggota_biasa_dapat_melihat_tetapi_tidak_dapat_membuat_atau_mengubah(): void
    {
        $vendor = (string) $this->buat(['party_name' => 'PT Sarana Medika'])->json('data.id');
        $anggota = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $anggota->id, 'system_role' => 'member', 'status' => 'active',
        ]);

        $this->actingAs($anggota)->get('/settings/vendors')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false)->where('vendors.total', 1));
        $this->actingAs($anggota)->postJson('/api/v1/vendors', [
            'legal_entity_id' => $this->le, 'party_name' => 'CV Alkes Jaya',
        ])->assertForbidden();
        $this->actingAs($anggota)->patchJson("/api/v1/vendors/{$vendor}", ['name' => 'X', 'status' => 'inactive'])->assertForbidden();
        $this->actingAs($anggota)->getJson('/api/v1/vendors/party-options')->assertForbidden();

        $this->assertSame(1, Vendor::query()->count());
    }

    public function test_vendor_dan_data_tenant_lain_tidak_terlihat_dan_tidak_dapat_dipakai(): void
    {
        $this->buat(['party_name' => 'PT Sarana Medika'])->assertCreated();
        $pemilikLain = $this->pemilik('owner@lain.test', 'PT Lain');
        $leLain = $this->legalEntity('PT Lain Sehat', 'LAIN', $pemilikLain);
        $vendorLain = $this->actingAs($pemilikLain)->postJson('/api/v1/vendors', [
            'legal_entity_id' => $leLain, 'party_name' => 'PT Pemasok Tenant Lain',
        ])->assertCreated()->json('data');

        $this->actingAs($this->owner)->get('/settings/vendors')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vendors.total', 1)
                ->where('vendors.data.0.name', 'PT Sarana Medika'));
        $this->actingAs($this->owner)->patchJson("/api/v1/vendors/{$vendorLain['id']}", ['name' => 'X', 'status' => 'inactive'])
            ->assertNotFound();
        $this->buat(['legal_entity_id' => $leLain, 'party_name' => 'CV Alkes Jaya'])
            ->assertStatus(422)->assertJsonValidationErrors('legal_entity_id');
        $this->buat(['party_id' => $vendorLain['party_id'], 'party_name' => null])
            ->assertStatus(422)->assertJsonValidationErrors('party_id');
        $this->actingAs($this->owner)->getJson('/api/v1/vendors/party-options?q=pemasok')->assertOk()
            ->assertJsonCount(0, 'data');

        $daftar = app(DaftarVendor::class);
        $this->assertNull($daftar->satu($this->membership->tenant_id, $vendorLain['id']));
        $this->assertSame([], $daftar->aktif($this->membership->tenant_id, $leLain));
    }

    public function test_kontrak_hanya_menawarkan_vendor_aktif_tetapi_tetap_membaca_yang_nonaktif(): void
    {
        $sarana = $this->buat(['party_name' => 'PT Sarana Medika'])->json('data');
        $alkes = $this->buat(['party_name' => 'CV Alkes Jaya', 'status' => 'inactive'])->json('data');
        $this->buat(['party_name' => 'PT Sarana Farma'])->assertCreated();
        $tenant = $this->membership->tenant_id;
        $daftar = app(DaftarVendor::class);

        $this->assertSame(['VND-000001', 'VND-000003'], array_column($daftar->aktif($tenant, $this->le, 'sarana'), 'number'));
        $this->assertSame(['VND-000001'], array_column($daftar->aktif($tenant, $this->le, 'VND-000001'), 'number'));
        $this->assertSame([], $daftar->aktif($tenant, $this->le, 'alkes'));
        $this->assertSame(
            ['id' => $alkes['id'], 'number' => 'VND-000002', 'name' => 'CV Alkes Jaya', 'tax_number' => null, 'status' => 'inactive', 'legal_entity_id' => $this->le],
            $daftar->satu($tenant, $alkes['id']),
        );
        $this->assertSame('PT Sarana Medika', $daftar->satu($tenant, $sarana['id'])['name'] ?? null);
    }

    public function test_sinkron_updated_since_hanya_mengembalikan_yang_berubah_termasuk_ganti_nama(): void
    {
        $token = $this->token();
        $lama = $this->buat(['party_name' => 'PT Sarana Medika'])->json('data');
        $baru = $this->buat(['party_name' => 'CV Alkes Jaya', 'status' => 'inactive'])->json('data');
        $this->mundurkan($lama, Carbon::now()->subDays(3));
        $sejak = Carbon::now()->subDay()->setTimezone('Asia/Jakarta')->toIso8601String();

        $this->vendors($token, ['updated_since' => $sejak])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'VND-000002')
            ->assertJsonPath('data.0.status', 'inactive')
            ->assertJsonPath('data.0.legal_entity.code', 'META')
            ->assertJsonPath('meta.next_cursor', null);

        // Nama milik party: mengganti nama di buku alamat, bukan lewat layar vendor, tetap
        // membuat vendornya terbawa tarikan berikutnya.
        DB::table('parties')->where('id', $lama['party_id'])->update(['name' => 'PT Sarana Medika Utama', 'updated_at' => now()]);
        $jawaban = $this->vendors($token, ['updated_since' => $sejak])->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['VND-000001', 'VND-000002'], collect($jawaban->json('data'))->pluck('number')->sort()->values()->all());
        $this->assertSame('PT Sarana Medika Utama', collect($jawaban->json('data'))->firstWhere('number', 'VND-000001')['name']);

        // Tanpa updated_since: semua, termasuk yang nonaktif.
        $this->vendors($token)->assertOk()->assertJsonCount(2, 'data');
        $this->vendors($token, ['legal_entity' => 'TIDAK-ADA'])->assertOk()->assertJsonCount(0, 'data');
        $this->vendors($token, ['legal_entity' => 'META'])->assertOk()->assertJsonCount(2, 'data');
        $this->vendors($token, ['legal_entity' => $this->le])->assertOk()->assertJsonPath('data.1.id', $baru['id']);
    }

    public function test_sinkron_per_halaman_lewat_kursor_tidak_melewatkan_vendor_yang_berubah_di_tengah(): void
    {
        $token = $this->token();
        $vendors = [];
        foreach (['PT Satu', 'PT Dua', 'PT Tiga'] as $urutan => $nama) {
            $vendors[] = $vendor = $this->buat(['party_name' => $nama])->json('data');
            $this->mundurkan($vendor, Carbon::now()->subMinutes(30 - $urutan * 10));
        }

        $pertama = $this->vendors($token, ['limit' => 2])->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['PT Satu', 'PT Dua'], array_column($pertama->json('data'), 'name'));
        $kursor = (string) $pertama->json('meta.next_cursor');
        $this->assertNotSame('', $kursor);

        // Vendor yang sudah terbaca berubah sebelum halaman kedua dibaca. Dengan nomor halaman,
        // PT Tiga akan bergeser ke halaman pertama dan tidak pernah terbaca.
        $this->actingAs($this->owner)->patchJson("/api/v1/vendors/{$vendors[0]['id']}", [
            'name' => 'PT Satu Baru', 'status' => 'active',
        ])->assertOk();

        $kedua = $this->vendors($token, ['limit' => 2, 'cursor' => $kursor])->assertOk();
        $this->assertSame(['PT Tiga', 'PT Satu Baru'], array_column($kedua->json('data'), 'name'));
        $this->assertNull($kedua->json('meta.next_cursor'));

        $this->vendors($token, ['cursor' => 'bukan-kursor'])->assertStatus(422)->assertJsonValidationErrors('cursor');
    }

    public function test_api_vendor_butuh_token_dengan_cakupan_vendors_read(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/internal/v1/vendors')->assertUnauthorized();
        $this->vendors($this->token(['operating-units.read']))->assertForbidden();
        $this->vendors($this->token())->assertOk();
    }

    public function test_halaman_menyaring_menurut_entitas_status_dan_kata(): void
    {
        $leLain = $this->legalEntity('PT Metta Farma', 'MFAR');
        $this->buat(['party_name' => 'PT Sarana Medika', 'tax_number' => '01.234.567.8-901.000'])->assertCreated();
        $this->buat(['party_name' => 'CV Alkes Jaya', 'status' => 'inactive'])->assertCreated();
        $this->buat(['legal_entity_id' => $leLain, 'party_name' => 'PT Farma Nusantara'])->assertCreated();

        $this->actingAs($this->owner)->get('/settings/vendors')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/vendors')
                ->where('canManage', true)
                ->where('manualNumbers', true)
                ->where('vendors.total', 3)
                ->has('legalEntities', 2));
        $this->actingAs($this->owner)->get("/settings/vendors?legal_entity={$this->le}&status=active")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('vendors.total', 1)->where('vendors.data.0.name', 'PT Sarana Medika'));
        $this->actingAs($this->owner)->get('/settings/vendors?q=901.000')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('vendors.total', 1));
        $this->actingAs($this->owner)->get('/settings/vendors?q=alkes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('vendors.total', 1)->where('vendors.data.0.status', 'inactive'));
    }

    public function test_database_menolak_status_tak_dikenal_dan_nomor_kembar_per_entitas(): void
    {
        $this->buat(['party_name' => 'PT Sarana Medika'])->assertCreated();
        $party = (string) str()->ulid();
        DB::table('parties')->insert([
            'id' => $party, 'tenant_id' => $this->membership->tenant_id, 'type' => 'organization',
            'name' => 'CV Alkes Jaya', 'search_name' => 'cv alkes jaya', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $gagal = 0;
        foreach ([['number' => 'VND-000001', 'status' => 'active'], ['number' => 'VND-000050', 'status' => 'blocked']] as $baris) {
            try {
                DB::transaction(fn () => DB::table('vendors')->insert([
                    'id' => (string) str()->ulid(), 'tenant_id' => $this->membership->tenant_id, 'legal_entity_id' => $this->le,
                    'party_id' => $party, 'number' => $baris['number'], 'status' => $baris['status'],
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            } catch (QueryException) {
                $gagal++;
            }
        }

        $this->assertSame(2, $gagal);
    }

    /** @param array<string, mixed> $ubah */
    private function buat(array $ubah = [], ?string $kunci = null): TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/v1/vendors', [
            'legal_entity_id' => $this->le,
            'party_name' => 'PT Sarana Medika',
            ...$ubah,
        ], $kunci === null ? [] : ['Idempotency-Key' => $kunci]);
    }

    /** @param list<string> $scopes */
    private function token(array $scopes = ['vendors.read']): string
    {
        return (string) $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', [
            'name' => 'Old-finance '.count($scopes).str()->random(4),
            'delivery_mode' => 'pull',
            'push_url' => null,
            'scopes' => $scopes,
            'posting_type_prefixes' => [],
            'allowed_ips' => [],
        ])->assertCreated()->json('token');
    }

    /** @param array<string, mixed> $query */
    private function vendors(string $token, array $query = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/internal/v1/vendors'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @param array{id: string, party_id: string} $vendor */
    private function mundurkan(array $vendor, Carbon $waktu): void
    {
        DB::table('vendors')->where('id', $vendor['id'])->update(['updated_at' => $waktu]);
        DB::table('parties')->where('id', $vendor['party_id'])->update(['updated_at' => $waktu]);
    }

    private function pemilik(string $email, string $bisnis): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$bisnis, 'business_name' => $bisnis,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);
    }

    private function legalEntity(string $nama, string $kode, ?User $pemilik = null): string
    {
        $this->actingAs($pemilik ?? $this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => $nama, 'company_code' => $kode, 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();

        return (string) DB::table('organizations')->where('name', $nama)->value('id');
    }
}
