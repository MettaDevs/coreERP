<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Access\Models\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\SeedsMaintenanceFixtures;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Permintaan pemeliharaan (padanan *Maintenance requests* F&O).
 *
 * Yang dijaga: setiap perpindahan status adalah tindakan tersendiri dengan permission-nya sendiri,
 * satu permintaan paling banyak satu work order yang dibuat lewat jalur work order biasa, pelapor
 * tidak dapat membuat work order, dan kebijakan organisasi mengikuti unit pelapor.
 */
class MaintenanceRequestTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase, SeedsMaintenanceFixtures;

    private const URL = '/api/modules/management-aset/v1/permintaan-pemeliharaan';

    private const REQUESTER = [
        'management-aset.permintaan-pemeliharaan.read', 'management-aset.permintaan-pemeliharaan.create',
        'management-aset.permintaan-pemeliharaan.update', 'management-aset.permintaan-pemeliharaan.archive',
        'management-aset.permintaan-pemeliharaan.submit',
    ];

    private const PLANNER = [
        'management-aset.permintaan-pemeliharaan.read', 'management-aset.permintaan-pemeliharaan.review',
        'management-aset.pemeliharaan-aset.read', 'management-aset.pemeliharaan-aset.create',
    ];

    private string $tenantId;

    private string $legalEntityId;

    private string $unitId;

    private string $jenisAset;

    private string $requestType;

    private string $tipe;

    private string $jobType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->unitId = (string) Str::ulid();
        $this->jenisAset = $this->seedMaster('aset_m_jenis_aset', 'Alat kesehatan');
        $this->tipe = $this->seedMaster('aset_m_tipe_work_order', 'Korektif');
        $this->requestType = $this->seedMaster('aset_m_jenis_permintaan_pemeliharaan', 'Kerusakan alat', ['tipe_work_order_id' => $this->tipe]);
        $this->jobType = $this->seedMaster('aset_m_maintenance_job_type', 'Perbaikan', ['category_code' => 'corrective']);
    }

    public function test_request_moves_through_explicit_steps_and_becomes_one_work_order(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'AST-MON', ['lokasi_aset_id' => $this->location()]);
        $created = $this->create(['aset_id' => $aset])->assertCreated()->assertJsonPath('data.status', 'draft');
        $id = (string) $created->json('data.id');
        $this->assertStringStartsWith($this->awalanNomor('management-aset.permintaan-pemeliharaan'), (string) $created->json('data.kode'));
        // Lokasi yang tidak diisi diambil dari lokasi aset saat ini.
        $this->assertNotNull($created->json('data.lokasi_aset_id'));

        $this->as(self::REQUESTER)->patchJson(self::URL.'/'.$id, [...$this->payload(['aset_id' => $aset]), 'deskripsi' => 'Layar berkedip lalu mati', 'version' => 1])
            ->assertOk()->assertJsonPath('data.deskripsi', 'Layar berkedip lalu mati');

        // Menerima atau membuat work order sebelum diajukan ditolak: langkahnya tidak boleh dilompati.
        $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/terima', ['version' => $this->version($id)])->assertUnprocessable();
        $this->as(self::REQUESTER)->postJson(self::URL.'/'.$id.'/ajukan', ['version' => $this->version($id)])->assertOk()->assertJsonPath('data.status', 'diajukan');
        // Sesudah diajukan, pelapor tidak dapat mengubah isinya lagi.
        $this->as(self::REQUESTER)->patchJson(self::URL.'/'.$id, [...$this->payload(['aset_id' => $aset]), 'version' => $this->version($id)])->assertUnprocessable();
        $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/work-order', ['maintenance_job_type_id' => $this->jobType, 'version' => $this->version($id)])->assertUnprocessable();

        $accepted = $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/terima', ['version' => $this->version($id)])->assertOk()
            ->assertJsonPath('data.status', 'diterima');
        $this->assertNotNull($accepted->json('data.diputuskan_oleh_nama'), 'Pemutus ditampilkan dengan namanya.');

        $done = $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/work-order', ['maintenance_job_type_id' => $this->jobType, 'version' => $this->version($id)])
            ->assertOk()->assertJsonPath('data.status', 'work_order_dibuat');
        $workOrder = (string) $done->json('data.pemeliharaan_aset_id');
        $this->assertStringStartsWith($this->awalanNomor('management-aset.pemeliharaan-aset'), (string) $done->json('data.work_order_kode'));
        // Tipe work order diwarisi dari jenis permintaan, unit dari pelapor.
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset', ['id' => $workOrder, 'tipe_work_order_id' => $this->tipe, 'responsible_org_unit_id' => $this->unitId, 'status' => 'draft']);
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_details', ['pemeliharaan_aset_id' => $workOrder, 'aset_id' => $aset, 'maintenance_job_type_id' => $this->jobType]);

        $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/work-order', ['maintenance_job_type_id' => $this->jobType, 'version' => $this->version($id)])->assertUnprocessable();
        $this->assertSame(1, DB::table('aset_tr_pemeliharaan_aset')->count());
    }

    public function test_location_only_request_needs_an_asset_before_its_work_order(): void
    {
        $lokasi = $this->location();
        $id = (string) $this->create(['lokasi_aset_id' => $lokasi])->assertCreated()->json('data.id');
        $this->create([])->assertUnprocessable()->assertJsonValidationErrors('aset_id');
        $this->accept($id);

        $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/work-order', ['maintenance_job_type_id' => $this->jobType, 'version' => $this->version($id)])
            ->assertUnprocessable()->assertJsonValidationErrors('aset_id');
        $aset = $this->seedAsset($this->jenisAset, 'AST-AC');
        $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/work-order', ['aset_id' => $aset, 'maintenance_job_type_id' => $this->jobType, 'version' => $this->version($id)])
            ->assertOk()->assertJsonPath('data.aset_id', $aset);
    }

    public function test_rejection_needs_a_reason_and_only_draft_or_rejected_requests_can_be_archived(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'AST-RJ');
        $id = (string) $this->create(['aset_id' => $aset])->json('data.id');
        $this->as(self::REQUESTER)->postJson(self::URL.'/'.$id.'/ajukan', ['version' => $this->version($id)])->assertOk();

        $this->as(self::REQUESTER)->deleteJson(self::URL.'/'.$id, ['version' => $this->version($id)])->assertUnprocessable();
        $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/tolak', ['version' => $this->version($id)])->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/tolak', ['alasan' => 'Masih bergaransi, hubungi vendor', 'version' => $this->version($id)])
            ->assertOk()->assertJsonPath('data.status', 'ditolak')->assertJsonPath('data.alasan_penolakan', 'Masih bergaransi, hubungi vendor');

        $this->as(self::REQUESTER)->deleteJson(self::URL.'/'.$id)->assertStatus(428);
        $this->as(self::REQUESTER)->deleteJson(self::URL.'/'.$id, ['version' => $this->version($id) - 1])->assertConflict();
        $this->as(self::REQUESTER)->deleteJson(self::URL.'/'.$id, ['version' => $this->version($id)])->assertNoContent();
        $this->as(self::REQUESTER)->getJson(self::URL.'/'.$id)->assertNotFound();
    }

    public function test_requester_permissions_cannot_decide_or_create_work_orders(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'AST-PR');
        $id = (string) $this->create(['aset_id' => $aset])->json('data.id');
        $this->as(self::REQUESTER)->postJson(self::URL.'/'.$id.'/ajukan', ['version' => $this->version($id)])->assertOk();

        $this->as(self::REQUESTER)->postJson(self::URL.'/'.$id.'/terima', ['version' => $this->version($id)])->assertForbidden();
        $this->as([...self::REQUESTER, 'management-aset.permintaan-pemeliharaan.review'])->postJson(self::URL.'/'.$id.'/terima', ['version' => $this->version($id)])->assertOk();
        // Hak meninjau saja belum cukup: membuat work order menuntut hak membuat work order.
        $this->as(['management-aset.permintaan-pemeliharaan.read', 'management-aset.permintaan-pemeliharaan.review'])
            ->postJson(self::URL.'/'.$id.'/work-order', ['maintenance_job_type_id' => $this->jobType, 'version' => $this->version($id)])->assertForbidden();
    }

    public function test_the_real_requester_duty_from_the_manifest_cannot_create_work_orders(): void
    {
        $this->assertSame(0, Artisan::call('app:register-manifest', ['module' => 'management-aset']), Artisan::output());
        $aset = $this->seedAsset($this->jenisAset, 'AST-DTY');

        $this->withDuty(['management-aset.permintaan-pemeliharaan.request']);
        $id = (string) $this->withHeader('Idempotency-Key', 'permintaan-'.Str::ulid())->postJson(self::URL, $this->payload(['aset_id' => $aset]))->assertCreated()->json('data.id');
        $this->postJson(self::URL.'/'.$id.'/ajukan', ['version' => $this->version($id)])->assertOk();
        $this->postJson(self::URL.'/'.$id.'/terima', ['version' => $this->version($id)])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'wo-'.Str::ulid())->postJson('/api/modules/management-aset/v1/pemeliharaan-aset', [])->assertForbidden();

        $this->withDuty(['management-aset.permintaan-pemeliharaan.review-requests', 'management-aset.pemeliharaan-aset.manage']);
        $this->postJson(self::URL.'/'.$id.'/terima', ['version' => $this->version($id)])->assertOk();
        $this->postJson(self::URL.'/'.$id.'/work-order', ['maintenance_job_type_id' => $this->jobType, 'version' => $this->version($id)])->assertOk();
    }

    public function test_requests_follow_the_reporting_unit_scope_and_create_is_idempotent(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'AST-SC');
        $key = 'permintaan-'.Str::ulid();
        $id = (string) $this->create(['aset_id' => $aset], $key)->assertCreated()->json('data.id');
        $this->create(['aset_id' => $aset], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('aset_tr_permintaan_pemeliharaan')->count());

        $lain = fn () => $this->sebagaiPenggunaBernama('Unit lain', $this->tenantId, self::REQUESTER, [[
            'policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => $this->legalEntityId, 'organization_id' => (string) Str::ulid(),
        ]]);
        $lain()->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $lain()->getJson(self::URL.'/'.$id)->assertNotFound();
        $lain()->withHeader('Idempotency-Key', 'permintaan-'.Str::ulid())->postJson(self::URL, $this->payload(['aset_id' => $aset]))->assertForbidden();
    }

    public function test_requests_accept_photo_attachments(): void
    {
        $this->assertNotNull(app(AttachmentRecordTypes::class)->for('aset_tr_permintaan_pemeliharaan'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId,
            'responsible_org_unit_id' => $this->unitId,
            'jenis_permintaan_id' => $this->requestType,
            'deskripsi' => 'Monitor pasien mati sendiri',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function create(array $overrides, ?string $key = null): TestResponse
    {
        return $this->as(self::REQUESTER)
            ->withHeader('Idempotency-Key', $key ?? 'permintaan-'.Str::ulid())
            ->postJson(self::URL, $this->payload($overrides));
    }

    private function accept(string $id): void
    {
        $this->as(self::REQUESTER)->postJson(self::URL.'/'.$id.'/ajukan', ['version' => $this->version($id)])->assertOk();
        $this->as(self::PLANNER)->postJson(self::URL.'/'.$id.'/terima', ['version' => $this->version($id)])->assertOk();
    }

    private function location(): string
    {
        return $this->seedMaster('aset_m_lokasi_aset', 'Ruang ICU');
    }

    private function version(string $id): int
    {
        return (int) DB::table('aset_tr_permintaan_pemeliharaan')->where('id', $id)->value('version');
    }

    /** @param  list<string>  $permissions */
    private function as(array $permissions): static
    {
        return $this->sebagaiPenggunaBernama('pengguna', $this->tenantId, $permissions);
    }

    /**
     * Anggota tenant yang memegang duty katalog sungguhan hasil `app:register-manifest`, dengan lingkup
     * seluruh organisasi.
     *
     * @param  list<string>  $duties
     */
    private function withDuty(array $duties): void
    {
        $user = User::factory()->create();
        $membership = TenantMembership::create(['tenant_id' => $this->tenantId, 'user_id' => $user->id, 'status' => 'active']);
        $role = Role::create(['tenant_id' => $this->tenantId, 'name' => 'Role '.Str::random(6), 'is_active' => true]);
        $role->duties()->sync($duties);
        $assignment = $membership->roleAssignments()->create(['role_id' => $role->id, 'source' => 'manual', 'status' => 'active', 'valid_from' => now()->subMinute()]);
        $this->beriLingkupKebijakan($this->tenantId, (string) $assignment->id, ['policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => null, 'organization_id' => null]);
        $this->actingAs($user);
    }
}
