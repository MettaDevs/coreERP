<?php

namespace Tests\Feature\Platform\Attachments;

use App\Platform\Access\Models\Role;
use App\Platform\Attachments\Models\DocumentAttachment;
use App\Platform\Attachments\Support\AttachmentContentMismatch;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\TenantMembership;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lampiran dokumen (area 6 TODO analisa gap BC fase 1, test B-6), lewat vendor sebagai record milik Core.
 * Hak atas record module diuji di test module masing-masing.
 */
class DocumentAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private User $owner;

    private string $tenantId;

    private string $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);
        Storage::fake('s3');
        $this->owner = $this->pemilik('owner@metta.test', 'PT Metta');
        $this->tenantId = $this->owner->activeMembership()->tenant_id;
        $this->vendorId = $this->vendor($this->owner, 'PT Metta Sehat', 'META');
    }

    public function test_lampiran_vendor_diunggah_didaftar_dan_diunduh_utuh(): void
    {
        $unggah = $this->unggah($this->owner, $this->vendorId, $this->pdf('Kontrak Budi Santoso.pdf'))
            ->assertCreated()
            ->assertJsonPath('data.record_type', 'vendors')
            ->assertJsonPath('data.record_id', $this->vendorId)
            ->assertJsonPath('data.line_number', null)
            ->assertJsonPath('data.file_name', 'Kontrak Budi Santoso.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.size_bytes', strlen(self::PDF))
            ->assertJsonPath('data.data_class', DataClass::EndUserIdentifiableInformation->value)
            ->assertJsonPath('data.created_by_user_id', (int) $this->owner->id)
            ->assertJsonPath('data.created_by_name', $this->owner->name);
        $id = (string) $unggah->json('data.id');

        $baris = DocumentAttachment::query()->findOrFail($id);
        $this->assertSame($this->tenantId, $baris->tenant_id);
        $this->assertSame(hash('sha256', self::PDF), $baris->content_hash);
        $this->assertSame('attachments/'.$this->tenantId.'/'.$id, $baris->storage_path);
        $this->assertSame(self::PDF, Storage::disk('s3')->get($baris->storage_path));

        $this->actingAs($this->owner)->getJson($this->daftar($this->vendorId))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('meta.can_change', true);

        $unduh = $this->actingAs($this->owner)->get('/api/v1/attachments/'.$id.'/download')->assertOk();
        $this->assertSame(self::PDF, $unduh->streamedContent());
        $this->assertSame('application/pdf', $unduh->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $unduh->headers->get('Content-Disposition'));
    }

    public function test_pengguna_tanpa_hak_atas_vendor_tidak_dapat_melihat_mengunduh_melampirkan_atau_mengarsipkan(): void
    {
        $id = $this->lampiran($this->vendorId);
        $tanpaHak = $this->anggota([]);

        $this->actingAs($tanpaHak)->getJson($this->daftar($this->vendorId))->assertNotFound();
        $this->actingAs($tanpaHak)->get('/api/v1/attachments/'.$id.'/download')->assertNotFound();
        $this->unggah($tanpaHak, $this->vendorId, $this->pdf('lain.pdf'))->assertNotFound();
        $this->actingAs($tanpaHak)->deleteJson('/api/v1/attachments/'.$id, ['version' => 1])->assertNotFound();

        $this->assertSame(1, DocumentAttachment::query()->count());
        $this->assertNull(DocumentAttachment::query()->findOrFail($id)->deleted_at);
    }

    public function test_pembaca_vendor_melihat_dan_mengunduh_tetapi_tidak_melampirkan_atau_mengarsipkan(): void
    {
        $id = $this->lampiran($this->vendorId);
        $pembaca = $this->anggota(['core.vendor.inquire']);

        $this->actingAs($pembaca)->getJson($this->daftar($this->vendorId))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.can_change', false);
        $this->actingAs($pembaca)->get('/api/v1/attachments/'.$id.'/download')->assertOk();
        $this->unggah($pembaca, $this->vendorId, $this->pdf('lain.pdf'))->assertForbidden();
        $this->actingAs($pembaca)->deleteJson('/api/v1/attachments/'.$id, ['version' => 1])->assertForbidden();

        $pengelola = $this->anggota(['core.vendor.inquire', 'core.vendor.manage']);
        $this->unggah($pengelola, $this->vendorId, $this->pdf('npwp.pdf'))->assertCreated();
    }

    public function test_lampiran_tidak_bocor_antar_tenant(): void
    {
        $id = $this->lampiran($this->vendorId);
        $pemilikLain = $this->pemilik('owner@lain.test', 'PT Lain');
        $vendorLain = $this->vendor($pemilikLain, 'PT Lain Sehat', 'LAIN');
        $this->lampiran($vendorLain, $pemilikLain);

        // Pemilik tenant lain memegang semua hak vendor di tenantnya sendiri, tetapi tidak atas vendor ini.
        $this->actingAs($pemilikLain)->getJson($this->daftar($this->vendorId))->assertNotFound();
        $this->actingAs($pemilikLain)->get('/api/v1/attachments/'.$id.'/download')->assertNotFound();
        $this->actingAs($pemilikLain)->deleteJson('/api/v1/attachments/'.$id, ['version' => 1])->assertNotFound();
        $this->unggah($pemilikLain, $this->vendorId, $this->pdf('susupan.pdf'))->assertNotFound();

        // Daftar vendor sendiri hanya memuat lampiran tenantnya.
        $this->actingAs($pemilikLain)->getJson($this->daftar($vendorLain))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['id' => $id]);
        $this->assertSame(1, DocumentAttachment::query()->where('tenant_id', $this->tenantId)->count());
        $this->assertNull(DocumentAttachment::query()->findOrFail($id)->deleted_at);
    }

    public function test_isi_berkas_yang_berubah_terdeteksi_dilaporkan_dan_tidak_dikirim(): void
    {
        Exceptions::fake();
        $id = $this->lampiran($this->vendorId);
        $baris = DocumentAttachment::query()->findOrFail($id);
        Storage::disk('s3')->put($baris->storage_path, str_replace('Catalog', 'Katalog', self::PDF));

        $jawaban = $this->actingAs($this->owner)->get('/api/v1/attachments/'.$id.'/download')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'attachment_corrupted');
        $this->assertStringNotContainsString('%PDF', (string) $jawaban->getContent());
        Exceptions::assertReported(fn (AttachmentContentMismatch $e): bool => str_contains($e->getMessage(), $id)
            && str_contains($e->getMessage(), $baris->content_hash));

        Storage::disk('s3')->delete($baris->storage_path);
        $this->actingAs($this->owner)->get('/api/v1/attachments/'.$id.'/download')
            ->assertStatus(500)->assertJsonPath('error.code', 'attachment_corrupted');
    }

    public function test_arsip_lunak_dan_memakai_versi_baris(): void
    {
        $id = $this->lampiran($this->vendorId);
        $alamat = '/api/v1/attachments/'.$id;

        $this->actingAs($this->owner)->deleteJson($alamat)->assertStatus(428)->assertJsonPath('error.code', 'version_required');
        $this->actingAs($this->owner)->deleteJson($alamat, [], ['If-Match' => '"7"'])->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        $this->actingAs($this->owner)->deleteJson($alamat, [], ['If-Match' => 'W/"1"'])->assertNoContent();

        $baris = DocumentAttachment::withTrashed()->findOrFail($id);
        $this->assertNotNull($baris->deleted_at);
        $this->assertTrue(Storage::disk('s3')->exists($baris->storage_path), 'Arsip tidak boleh menghapus berkasnya.');
        $this->actingAs($this->owner)->getJson($this->daftar($this->vendorId))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->owner)->get($alamat.'/download')->assertNotFound();
        $this->actingAs($this->owner)->deleteJson($alamat, [], ['If-Match' => 'W/"2"'])->assertNotFound();
    }

    public function test_batas_unggah_dan_baris_yang_tidak_ada_ditolak(): void
    {
        $this->unggah($this->owner, $this->vendorId, UploadedFile::fake()->createWithContent('skrip.exe', 'MZ'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        // Ekstensi yang diizinkan dengan isi yang bukan jenisnya. Berkas sungguhan, bukan `fake()`: berkas palsu
        // Laravel melaporkan jenisnya dari nama, sedangkan yang diuji di sini pemeriksaan isi.
        $palsu = tempnam(sys_get_temp_dir(), 'lampiran');
        file_put_contents($palsu, "<?php echo 'bukan pdf';
");
        $this->unggah($this->owner, $this->vendorId, new UploadedFile($palsu, 'palsu.pdf', null, null, true))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        unlink($palsu);
        $this->unggah($this->owner, $this->vendorId, UploadedFile::fake()->create('besar.pdf', 10241, 'application/pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        // Vendor tidak punya baris dokumen.
        $this->unggah($this->owner, $this->vendorId, $this->pdf('baris.pdf'), ['line_number' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('line_number');

        $this->assertSame(0, DocumentAttachment::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_jenis_record_yang_tidak_terdaftar_tidak_dapat_diberi_lampiran(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/records/tenant_memberships/'.Str::ulid().'/attachments')->assertNotFound();
        $this->unggah($this->owner, $this->vendorId, $this->pdf('x.pdf'), [], 'finance_postings')->assertNotFound();
        $this->actingAs($this->owner)->getJson($this->daftar((string) Str::ulid()))->assertNotFound();
    }

    public function test_record_fase_satu_terdaftar(): void
    {
        $terdaftar = app(AttachmentRecordTypes::class)->recordTypes();
        sort($terdaftar);

        // Fase 1 dari README gap 7, ditambah pemeriksaan fisik aset (foto bukti), permintaan
        // pemeliharaan (foto kerusakan), serta berkas polis asuransi dan kontrak servis yang datang sesudahnya.
        $this->assertSame([
            'aset_m_polis_asuransi', 'aset_tr_aset', 'aset_tr_dokumen_siklus_aset', 'aset_tr_kontrak_servis', 'aset_tr_monitoring_aset',
            'aset_tr_mutasi_aset', 'aset_tr_pemeliharaan_aset', 'aset_tr_penerimaan_aset', 'aset_tr_perencanaan_aset',
            'aset_tr_permintaan_pemeliharaan', 'aset_tr_permintaan_pengadaan_aset',
            'hr_workers', 'vendors',
        ], $terdaftar);
        $this->assertSame(DataClass::EndUserIdentifiableInformation, app(AttachmentRecordTypes::class)->for('hr_workers')?->dataClass());
        $this->assertSame(DataClass::CustomerContent, app(AttachmentRecordTypes::class)->for('aset_tr_aset')?->dataClass());
    }

    private function lampiran(string $vendorId, ?User $oleh = null): string
    {
        return (string) $this->unggah($oleh ?? $this->owner, $vendorId, $this->pdf('Kontrak.pdf'))->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $tambahan */
    private function unggah(User $oleh, string $recordId, UploadedFile $berkas, array $tambahan = [], string $jenis = 'vendors'): TestResponse
    {
        return $this->actingAs($oleh)->post('/api/v1/records/'.$jenis.'/'.$recordId.'/attachments', [
            'file' => $berkas, ...$tambahan,
        ], ['Accept' => 'application/json']);
    }

    private function daftar(string $vendorId): string
    {
        return '/api/v1/records/vendors/'.$vendorId.'/attachments';
    }

    private function pdf(string $nama): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nama, self::PDF);
    }

    /** @param list<string> $duties */
    private function anggota(array $duties): User
    {
        $user = User::factory()->create();
        $membership = TenantMembership::create(['tenant_id' => $this->tenantId, 'user_id' => $user->id, 'status' => 'active']);
        if ($duties !== []) {
            $role = Role::create(['tenant_id' => $this->tenantId, 'name' => 'Role '.Str::random(6), 'is_active' => true]);
            $role->duties()->sync($duties);
            $membership->roleAssignments()->create(['role_id' => $role->id, 'source' => 'manual', 'status' => 'active', 'valid_from' => now()->subMinute()]);
        }

        return $user;
    }

    private function pemilik(string $email, string $bisnis): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$bisnis, 'business_name' => $bisnis,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);
    }

    private function vendor(User $pemilik, string $entitas, string $kode): string
    {
        $this->actingAs($pemilik)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => $entitas, 'company_code' => $kode, 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();
        $le = (string) DB::table('organizations')->where('name', $entitas)->value('id');

        return (string) $this->actingAs($pemilik)->postJson('/api/v1/vendors', [
            'legal_entity_id' => $le, 'party_name' => 'CV Alkes '.$kode,
        ])->assertCreated()->json('data.id');
    }
}
