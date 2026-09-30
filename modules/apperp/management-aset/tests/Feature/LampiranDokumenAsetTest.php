<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Support\Modules\Contracts\DataClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
