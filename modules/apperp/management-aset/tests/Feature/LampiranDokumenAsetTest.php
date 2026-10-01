<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Models\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Models\TenantMembership;
use App\Support\Modules\Contracts\DataClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Lampiran dokumen Core pada record module aset (area 6 TODO analisa gap BC fase 1, test B-6): haknya
 * mengikuti record induk, yaitu permission resource-nya dan lingkup organisasi pemiliknya.
 */
class LampiranDokumenAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private const KEBIJAKAN = 'management-aset.asset-responsibility';

    private string $tenantId;

    private string $legalEntityId;

    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->unitId = (string) Str::ulid();
        Storage::fake('s3');
        Http::preventStrayRequests();
    }

    public function test_lampiran_aset_mengikuti_hak_baca_dan_ubah_aset(): void
    {
        $aset = $this->aset($this->legalEntityId, $this->unitId);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read', 'management-aset.aset.update']);
        $id = (string) $this->unggah('aset_tr_aset', $aset)
            ->assertCreated()
            ->assertJsonPath('data.data_class', DataClass::CustomerContent->value)
            ->json('data.id');
        $this->getJson($this->daftar('aset_tr_aset', $aset))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.can_change', true);

        // Hanya boleh membaca aset: melihat dan mengunduh, tidak melampirkan atau mengarsipkan.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read']);
        $this->getJson($this->daftar('aset_tr_aset', $aset))->assertOk()->assertJsonPath('meta.can_change', false);
        $this->get('/api/v1/attachments/'.$id.'/download')->assertOk();
        $this->unggah('aset_tr_aset', $aset)->assertForbidden();
        $this->deleteJson('/api/v1/attachments/'.$id, ['version' => 1])->assertForbidden();

        // Hak atas dokumen lain di module yang sama bukan hak atas aset.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.read', 'management-aset.mutasi-aset.update']);
        $this->getJson($this->daftar('aset_tr_aset', $aset))->assertNotFound();
        $this->get('/api/v1/attachments/'.$id.'/download')->assertNotFound();
        $this->unggah('aset_tr_aset', $aset)->assertNotFound();
        $this->deleteJson('/api/v1/attachments/'.$id, ['version' => 1])->assertNotFound();

        $this->assertNull(DB::table('document_attachments')->where('id', $id)->value('deleted_at'));
    }

    public function test_lampiran_aset_di_luar_lingkup_organisasi_tertutup(): void
    {
        $unitLain = (string) Str::ulid();
        $aset = $this->aset($this->legalEntityId, $unitLain);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read', 'management-aset.aset.update']);
        $id = (string) $this->unggah('aset_tr_aset', $aset)->assertCreated()->json('data.id');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read', 'management-aset.aset.update'], [
            ['policy_code' => self::KEBIJAKAN, 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $this->unitId],
        ]);
        $this->getJson($this->daftar('aset_tr_aset', $aset))->assertNotFound();
        $this->get('/api/v1/attachments/'.$id.'/download')->assertNotFound();
        $this->unggah('aset_tr_aset', $aset)->assertNotFound();
        $this->deleteJson('/api/v1/attachments/'.$id, ['version' => 1])->assertNotFound();
    }

    public function test_lampiran_tidak_bocor_antar_tenant(): void
    {
        $aset = $this->aset($this->legalEntityId, $this->unitId);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read', 'management-aset.aset.update']);
        $id = (string) $this->unggah('aset_tr_aset', $aset)->assertCreated()->json('data.id');

        // Pengguna tenant lain dengan hak dan lingkup penuh atas aset di tenantnya sendiri.
        $tenantLain = $this->buatTenantUji();
        $this->sebagaiPengguna($tenantLain, ['management-aset.aset.read', 'management-aset.aset.update']);
        $this->getJson($this->daftar('aset_tr_aset', $aset))->assertNotFound();
        $this->get('/api/v1/attachments/'.$id.'/download')->assertNotFound();
        $this->unggah('aset_tr_aset', $aset)->assertNotFound();
        $this->deleteJson('/api/v1/attachments/'.$id, ['version' => 1])->assertNotFound();
    }

    public function test_tanpa_izin_apa_pun_di_module_aset_ditolak_sebelum_ditanya(): void
    {
        $aset = $this->aset($this->legalEntityId, $this->unitId);
        $this->sebagaiPengguna($this->tenantId, []);

        $this->getJson($this->daftar('aset_tr_aset', $aset))->assertForbidden();
    }

    public function test_lampiran_menempel_ke_baris_dokumen_yang_ada(): void
    {
        $rencana = $this->perencanaan();
        $this->sebagaiPengguna($this->tenantId, ['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.update']);

        $this->unggah('aset_tr_perencanaan_aset', $rencana, ['line_number' => 1])
            ->assertCreated()->assertJsonPath('data.line_number', 1);
        $this->unggah('aset_tr_perencanaan_aset', $rencana)->assertCreated()->assertJsonPath('data.line_number', null);
        $this->unggah('aset_tr_perencanaan_aset', $rencana, ['line_number' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('line_number');

        // Lampiran dokumen lebih dulu, lalu lampiran baris.
        $daftar = $this->getJson($this->daftar('aset_tr_perencanaan_aset', $rencana))->assertOk()->json('data');
        $this->assertSame([null, 1], array_column($daftar, 'line_number'));
    }

    /**
     * K-21: `permintaan-pembelian-aset.update` kini ada di manifest, di duty yang mengelola permintaan. Pemegang
     * duty itu, lewat rantai katalog sungguhan hasil `app:register-manifest`, dapat mengubah permintaan dan
     * melampirinya.
     */
    public function test_pemegang_duty_kelola_permintaan_dapat_mengubah_dan_melampiri_permintaan(): void
    {
        $this->assertSame(0, Artisan::call('app:register-manifest', ['module' => 'management-aset']), Artisan::output());
        $jenis = $this->jenisAset('Kursi tunggu');
        $permintaan = $this->permintaanPengadaan($jenis);
        $this->denganDuty(['management-aset.permintaan-pembelian-aset.manage']);

        $this->patchJson('/api/modules/management-aset/v1/permintaan-pembelian-aset/'.$permintaan, [
            'legal_entity_id' => $this->legalEntityId, 'requesting_org_unit_id' => $this->unitId,
            'requested_on' => '2026-06-01', 'description' => 'Diubah pemegang duty', 'version' => 1,
            'details' => [[
                'jenis_aset_id' => $jenis, 'satuan_id' => (string) Str::ulid(), 'quantity' => 2, 'specification' => 'Kursi besi',
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('aset_tr_permintaan_pengadaan_aset', ['id' => $permintaan, 'description' => 'Diubah pemegang duty']);

        $this->getJson($this->daftar('aset_tr_permintaan_pengadaan_aset', $permintaan))->assertOk()->assertJsonPath('meta.can_change', true);
        $this->unggah('aset_tr_permintaan_pengadaan_aset', $permintaan, ['line_number' => 1])
            ->assertCreated()->assertJsonPath('data.line_number', 1);
    }

    /** K-20: dokumen siklus tidak punya permission ubah; yang boleh membuat dokumennya yang boleh melampirinya. */
    public function test_lampiran_dokumen_siklus_memakai_permission_buat_jenis_dokumennya(): void
    {
        $dokumen = $this->dokumenSiklus('penjualan-aset');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penjualan-aset.read']);
        $this->getJson($this->daftar('aset_tr_dokumen_siklus_aset', $dokumen))->assertOk()->assertJsonPath('meta.can_change', false);
        $this->unggah('aset_tr_dokumen_siklus_aset', $dokumen)->assertForbidden();

        // Hak atas jenis dokumen lain bukan hak atas dokumen penjualan.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.pemusnahan-aset.read', 'management-aset.pemusnahan-aset.create']);
        $this->getJson($this->daftar('aset_tr_dokumen_siklus_aset', $dokumen))->assertNotFound();

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penjualan-aset.read', 'management-aset.penjualan-aset.create']);
        $this->unggah('aset_tr_dokumen_siklus_aset', $dokumen)->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $tambahan
     * @return TestResponse<Response>
     */
    private function unggah(string $jenis, string $recordId, array $tambahan = []): TestResponse
    {
        return $this->post('/api/v1/records/'.$jenis.'/'.$recordId.'/attachments', [
            'file' => UploadedFile::fake()->createWithContent('Berita acara.pdf', self::PDF), ...$tambahan,
        ], ['Accept' => 'application/json']);
    }

    private function daftar(string $jenis, string $recordId): string
    {
        return '/api/v1/records/'.$jenis.'/'.$recordId.'/attachments';
    }

    private function aset(string $legalEntityId, string $unitId): string
    {
        $id = (string) Str::ulid();
        $group = (string) Str::ulid();
        $jenis = (string) Str::ulid();
        $now = now();
        DB::table('aset_m_group_aset')->insert(['id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'group-'.$group, 'kode' => 'G'.Str::random(6), 'nama' => 'Group', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_m_jenis_aset')->insert(['id' => $jenis, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.$jenis, 'kode' => 'J'.Str::random(6), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_tr_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'lampiran-'.$id, 'kode' => 'AST-'.Str::random(6),
            'nama' => 'Laptop', 'legal_entity_id' => $legalEntityId, 'responsible_org_unit_id' => $unitId,
            'group_aset_id' => $group, 'jenis_aset_id' => $jenis, 'acquired_on' => '2026-07-28', 'acquisition_value' => 1,
            'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * Masuk sebagai anggota tenant yang memegang duty katalog sungguhan, dengan lingkup seluruh organisasi.
     *
     * @param  list<string>  $duties
     */
    private function denganDuty(array $duties): void
    {
        $pengguna = User::factory()->create();
        $membership = TenantMembership::create(['tenant_id' => $this->tenantId, 'user_id' => $pengguna->id, 'status' => 'active']);
        $role = Role::create(['tenant_id' => $this->tenantId, 'name' => 'Role '.Str::random(6), 'is_active' => true]);
        $role->duties()->sync($duties);
        $penugasan = $membership->roleAssignments()->create(['role_id' => $role->id, 'source' => 'manual', 'status' => 'active', 'valid_from' => now()->subMinute()]);
        $this->beriLingkupKebijakan($this->tenantId, (string) $penugasan->id, ['policy_code' => self::KEBIJAKAN, 'legal_entity_id' => null, 'organization_id' => null]);
        $this->actingAs($pengguna);
    }

    private function jenisAset(string $nama): string
    {
        $id = (string) Str::ulid();
        $now = now();
        DB::table('aset_m_jenis_aset')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.$id, 'kode' => 'J'.Str::random(6), 'nama' => $nama, 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }

    /** Permintaan draf versi 1 dengan satu baris, disusun langsung di tabel. */
    private function permintaanPengadaan(string $jenis): string
    {
        $now = now();
        $id = (string) Str::ulid();
        DB::table('aset_tr_permintaan_pengadaan_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'pp-'.$id, 'kode' => 'PP'.Str::random(8),
            'legal_entity_id' => $this->legalEntityId, 'requesting_org_unit_id' => $this->unitId,
            'requester_user_id' => (string) Str::ulid(), 'requested_on' => '2026-06-01', 'status' => 'draft',
            'description' => 'Permintaan awal', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_tr_permintaan_pengadaan_aset_details')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'request_id' => $id, 'line_number' => 1,
            'jenis_aset_id' => $jenis, 'satuan_id' => (string) Str::ulid(), 'nama_aset' => 'Kursi tunggu', 'quantity' => 1,
            'specification' => 'Permintaan awal', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    private function dokumenSiklus(string $jenisDokumen): string
    {
        $id = (string) Str::ulid();
        $now = now();
        DB::table('aset_tr_dokumen_siklus_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'ds-'.$id, 'jenis_dokumen' => $jenisDokumen,
            'kode' => 'DS'.Str::random(8), 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->unitId,
            'tanggal' => '2026-06-01', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    private function perencanaan(): string
    {
        $jenis = (string) Str::ulid();
        $now = now();
        DB::table('aset_m_jenis_aset')->insert(['id' => $jenis, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.$jenis, 'kode' => 'J'.Str::random(6), 'nama' => 'Laptop kerja', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return (string) $this->sebagaiPengguna($this->tenantId, ['management-aset.perencanaan-aset.create'])
            ->withHeader('Idempotency-Key', 'plan-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', [
                'legal_entity_id' => $this->legalEntityId, 'planning_org_unit_id' => $this->unitId,
                'planned_on' => '2026-07-30', 'planning_year' => 2026, 'planning_type' => 'regular',
                'funding_source' => 'Anggaran operasional', 'description' => 'Perangkat tim',
                'details' => [[
                    'jenis_aset_id' => $jenis, 'satuan_id' => $this->buatSatuanUji($this->tenantId), 'quantity' => 1,
                    'requested_specification' => 'RAM 16 GB', 'estimated_unit_price' => 15000000,
                ]],
            ])->assertCreated()->json('data.id');
    }
}
