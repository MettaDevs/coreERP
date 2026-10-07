<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Access\Models\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\SeedsMaintenanceFixtures;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Rencana pemeliharaan preventif dan usulan jadwalnya (padanan *Maintenance plans* dan *Maintenance
 * schedule* F&O).
 *
 * Yang dijaga: jatuh tempo dihitung benar untuk ketiga dasar hitung, perhitungan ulang tidak pernah
 * melahirkan usulan kembar, usulan basi dibersihkan, toleransi menghormati work order yang sudah ada,
 * dan usulan menjadi work order lewat jalur pembuatan work order yang sama.
 */
class MaintenanceScheduleTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase, SeedsMaintenanceFixtures;

    private const API = '/api/modules/management-aset/v1/';

    private const PLAN = [
        'management-aset.rencana-pemeliharaan.read', 'management-aset.rencana-pemeliharaan.create',
        'management-aset.rencana-pemeliharaan.update', 'management-aset.rencana-pemeliharaan.archive',
    ];

    private const SCHEDULE = [
        'management-aset.jadwal-pemeliharaan.read', 'management-aset.jadwal-pemeliharaan.run',
        'management-aset.jadwal-pemeliharaan.discard',
        'management-aset.pemeliharaan-aset.read', 'management-aset.pemeliharaan-aset.create',
    ];

    private string $tenantId;

    private string $legalEntityId;

    private string $unitId;

    private string $jenisId;

    private string $jobType;

    private string $tipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->unitId = (string) Str::ulid();
        $this->jenisId = $this->seedMaster('aset_m_jenis_aset', 'Alat kesehatan');
        $this->jobType = $this->seedMaster('aset_m_maintenance_job_type', 'Kalibrasi', ['category_code' => 'preventive']);
        $this->tipe = $this->seedMaster('aset_m_tipe_work_order', 'Preventif');
        $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
    }

    public function test_start_date_basis_proposes_the_latest_overdue_and_all_upcoming_dates_once(): void
    {
        $aset = $this->seedAsset($this->jenisId, 'AST-ECG');
        $this->seedAsset($this->jenisId, 'AST-LEPAS', ['lifecycle_state' => 'disposed']);
        $this->plan(['tanggal_mulai' => '2026-01-15'], [$this->timeLine('tanggal_mulai', 1, 'bulan')], [['jenis_aset_id' => $this->jenisId]]);

        $this->schedule('2026-12-31')->assertOk()->assertJsonPath('data.dibuat', 4);
        $this->assertSame(['2026-09-15', '2026-10-15', '2026-11-15', '2026-12-15'], $this->dueDates($aset));

        // Dijalankan ulang: tidak ada yang kembar, tidak ada yang dibersihkan.
        $this->schedule('2026-12-31')->assertOk()->assertJsonPath('data.dibuat', 0)->assertJsonPath('data.dibersihkan', 0);
        $this->assertSame(4, DB::table('aset_tr_jadwal_pemeliharaan')->whereNull('deleted_at')->count(), 'Aset yang sudah dilepas tidak diusulkan.');

        $list = $this->as(self::SCHEDULE)->getJson(self::API.'jadwal-pemeliharaan')->assertOk()->json('data');
        $this->assertSame([true, false, false, false], array_column($list, 'terlambat'));
        $this->assertSame('Kalibrasi', $list[0]['job_type_nama']);
    }

    public function test_last_work_order_basis_counts_from_the_actual_completion(): void
    {
        $dikalibrasi = $this->seedAsset($this->jenisId, 'AST-DONE');
        $belumPernah = $this->seedAsset($this->jenisId, 'AST-NEW');
        $this->seedWorkOrder($dikalibrasi, $this->jobType, 'ditutup', ['aktual_selesai' => '2025-11-20 03:00:00']);
        // Work order yang dibatalkan bukan pekerjaan yang selesai.
        $this->seedWorkOrder($dikalibrasi, $this->jobType, 'dibatalkan', ['aktual_selesai' => '2026-06-01 03:00:00']);
        $this->plan(['tanggal_mulai' => '2026-03-01'], [$this->timeLine('work_order_terakhir', 1, 'tahun')], [['jenis_aset_id' => $this->jenisId]]);

        $this->schedule('2026-12-31')->assertOk()->assertJsonPath('data.dibuat', 2);
        $this->assertSame(['2026-11-20'], $this->dueDates($dikalibrasi));
        // Belum pernah dikerjakan: jatuh tempo pertama adalah tanggal mulai, dan langsung terlambat.
        $this->assertSame(['2026-03-01'], $this->dueDates($belumPernah));
    }

    public function test_counter_basis_proposes_the_reached_multiple_and_supersedes_it_later(): void
    {
        $aset = $this->seedAsset($this->jenisId, 'AST-VNT');
        $tanpaBacaan = $this->seedAsset($this->jenisId, 'AST-BARU');
        $counter = $this->seedCounterType();
        $this->reading($aset, $counter, '2026-09-01 08:00:00', 300, 300);
        $this->reading($aset, $counter, '2026-09-10 08:00:00', 490, 490);
        $this->plan(['tanggal_mulai' => '2026-01-01'], [[
            'dasar' => 'nilai_counter', 'jenis_counter_id' => $counter, 'interval_counter' => 500, 'toleransi_counter' => 20,
            'maintenance_job_type_id' => $this->jobType, 'tipe_work_order_id' => $this->tipe,
        ]], [['aset_id' => $aset], ['aset_id' => $tanpaBacaan]]);

        // 490 + toleransi 20 sudah mencapai 500; tanggalnya tanggal pembacaan yang mencapainya.
        $this->schedule('2026-10-31')->assertOk()->assertJsonPath('data.dibuat', 1);
        $this->assertDatabaseHas('aset_tr_jadwal_pemeliharaan', ['aset_id' => $aset, 'jatuh_tempo' => '2026-09-10', 'nilai_jatuh_tempo' => '500.00', 'nilai_counter' => '490.00']);
        $this->schedule('2026-10-31')->assertOk()->assertJsonPath('data.dibuat', 0);
        $this->assertSame([], $this->dueDates($tanpaBacaan), 'Aset tanpa pembacaan counter dilewati.');

        $this->reading($aset, $counter, '2026-09-20 08:00:00', 1010, 1010);
        $this->schedule('2026-10-31')->assertOk()->assertJsonPath('data.dibuat', 1)->assertJsonPath('data.dibersihkan', 1);
        $this->assertSame(['2026-09-20'], $this->dueDates($aset));
        $this->assertDatabaseHas('aset_tr_jadwal_pemeliharaan', ['aset_id' => $aset, 'nilai_jatuh_tempo' => '1000.00', 'deleted_at' => null]);
    }

    public function test_tolerance_skips_a_due_date_already_covered_by_a_work_order(): void
    {
        $aset = $this->seedAsset($this->jenisId, 'AST-INF');
        $this->seedWorkOrder($aset, $this->jobType, 'draft', ['diharapkan_mulai' => '2026-10-12 08:00:00']);
        $this->plan(['tanggal_mulai' => '2026-10-10', 'toleransi_hari_sebelum' => 5, 'toleransi_hari_sesudah' => 5], [$this->timeLine('tanggal_mulai', 1, 'bulan')], [['aset_id' => $aset]]);

        $this->schedule('2026-11-30')->assertOk()->assertJsonPath('data.dibuat', 1);
        $this->assertSame(['2026-11-10'], $this->dueDates($aset));
    }

    public function test_proposals_become_one_work_order_per_asset_with_number_and_checklist(): void
    {
        $aset = $this->seedAsset($this->jenisId, 'AST-USG');
        $servis = $this->seedMaster('aset_m_maintenance_job_type', 'Servis berkala', ['category_code' => 'preventive']);
        $template = $this->seedMaster('aset_m_maintenance_checklist_template', 'Kalibrasi USG');
        DB::table('aset_m_maintenance_checklist_template_line')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'template_id' => $template,
            'line_number' => 1, 'type' => 'text', 'nama' => 'Cek probe', 'wajib' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aset_m_maintenance_job_type_default')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'DFLT-1', 'nama' => 'Checklist kalibrasi', 'maintenance_job_type_id' => $this->jobType,
            'checklist_template_id' => $template, 'hours' => 1, 'items_count' => 0, 'expenses_count' => 0, 'fees_count' => 0,
            'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->plan(['tanggal_mulai' => '2026-10-05'], [
            [...$this->timeLine('tanggal_mulai', 1, 'tahun'), 'selesai_dalam_hari' => 2, 'deskripsi' => 'Kalibrasi tahunan USG'],
            [...$this->timeLine('tanggal_mulai', 1, 'tahun'), 'maintenance_job_type_id' => $servis],
        ], [['aset_id' => $aset]]);
        $this->schedule('2026-10-31')->assertOk()->assertJsonPath('data.dibuat', 2);
        $lines = $this->as(self::SCHEDULE)->getJson(self::API.'jadwal-pemeliharaan')->json('data');
        $payload = ['kelompok' => 'aset', 'lines' => array_map(static fn (array $line): array => ['id' => $line['id'], 'version' => $line['version']], $lines)];

        $response = $this->as(self::SCHEDULE)->postJson(self::API.'jadwal-pemeliharaan/work-order', $payload)->assertCreated();
        $this->assertCount(1, $response->json('data.work_orders'));
        $workOrderId = (string) $response->json('data.work_orders.0.id');
        $this->assertMatchesRegularExpression('/^'.preg_quote($this->awalanNomor('management-aset.pemeliharaan-aset'), '/').'/', (string) $response->json('data.work_orders.0.kode'));
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset', [
            'id' => $workOrderId, 'status' => 'draft', 'tipe_work_order_id' => $this->tipe,
            'responsible_org_unit_id' => $this->unitId, 'diharapkan_mulai' => '2026-10-05 00:00:00', 'diharapkan_selesai' => '2026-10-07 23:59:59',
        ]);
        $this->assertSame(2, DB::table('aset_tr_pemeliharaan_aset_details')->where('pemeliharaan_aset_id', $workOrderId)->count());
        // Checklist bawaan jenis pekerjaan ikut tersalin, sama seperti work order yang dibuat dari layarnya.
        $this->assertSame(1, DB::table('aset_tr_pemeliharaan_aset_checklist')->where('nama', 'Cek probe')->count());
        $this->assertSame(2, DB::table('aset_tr_jadwal_pemeliharaan')->where(['status' => 'work_order_dibuat', 'pemeliharaan_aset_id' => $workOrderId])->count());

        // Perhitungan ulang tidak mengusulkan lagi, dan usulan yang sudah menjadi work order tidak dapat diubah lagi.
        $this->schedule('2026-10-31')->assertOk()->assertJsonPath('data.dibuat', 0);
        $this->as(self::SCHEDULE)->postJson(self::API.'jadwal-pemeliharaan/work-order', $payload)->assertUnprocessable();
        $this->assertSame(1, DB::table('aset_tr_pemeliharaan_aset')->where('creation_key', 'like', 'jadwal:%')->count());
    }

    public function test_discarded_proposal_never_comes_back(): void
    {
        $aset = $this->seedAsset($this->jenisId, 'AST-DEF');
        $this->plan(['tanggal_mulai' => '2026-10-20'], [$this->timeLine('tanggal_mulai', 1, 'tahun')], [['aset_id' => $aset]]);
        $this->schedule('2026-10-31')->assertOk()->assertJsonPath('data.dibuat', 1);
        $line = $this->as(self::SCHEDULE)->getJson(self::API.'jadwal-pemeliharaan')->json('data.0');

        $this->as(self::SCHEDULE)->postJson(self::API.'jadwal-pemeliharaan/'.$line['id'].'/abaikan')->assertStatus(428);
        $this->as(self::SCHEDULE)->postJson(self::API.'jadwal-pemeliharaan/'.$line['id'].'/abaikan', ['version' => $line['version']])
            ->assertOk()->assertJsonPath('data.status', 'diabaikan');
        $this->schedule('2026-10-31')->assertOk()->assertJsonPath('data.dibuat', 0);
        $this->as(self::SCHEDULE)->getJson(self::API.'jadwal-pemeliharaan')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_editing_a_plan_line_keeps_its_identity_and_cleans_proposals_that_no_longer_apply(): void
    {
        $aset = $this->seedAsset($this->jenisId, 'AST-EDT');
        $plan = $this->plan(['tanggal_mulai' => '2026-10-15'], [$this->timeLine('tanggal_mulai', 1, 'bulan')], [['aset_id' => $aset]]);
        $this->schedule('2026-12-31')->assertOk()->assertJsonPath('data.dibuat', 3);
        $line = $this->as(self::PLAN)->getJson(self::API.'rencana-pemeliharaan/'.$plan.'/baris')->json('data.0');

        $this->putLines($plan, [[...$this->timeLine('tanggal_mulai', 2, 'bulan'), 'id' => $line['id']]])->assertOk()->assertJsonPath('data.0.id', $line['id']);
        // Bulan ke-2 (15 November) tidak lagi jatuh tempo; usulannya dibersihkan, yang lain tetap.
        $this->schedule('2026-12-31')->assertOk()->assertJsonPath('data.dibuat', 0)->assertJsonPath('data.dibersihkan', 1);
        $this->assertSame(['2026-10-15', '2026-12-15'], $this->dueDates($aset));

        // Rencana dimatikan: seluruh usulan terbukanya ikut dibersihkan.
        $version = (int) DB::table('aset_m_rencana_pemeliharaan')->where('id', $plan)->value('version');
        $this->as(self::PLAN)->patchJson(self::API.'rencana-pemeliharaan/'.$plan, ['aktif' => false, 'version' => $version])->assertOk();
        $this->schedule('2026-12-31')->assertOk()->assertJsonPath('data.dibersihkan', 2);
        $this->assertSame([], $this->dueDates($aset));
    }

    public function test_plan_lines_are_validated_by_their_basis(): void
    {
        $plan = $this->plan(['tanggal_mulai' => '2026-10-01'], [], []);

        $this->putLines($plan, [['dasar' => 'tanggal_mulai', 'maintenance_job_type_id' => $this->jobType, 'tipe_work_order_id' => $this->tipe]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.interval');
        $this->putLines($plan, [['dasar' => 'nilai_counter', 'maintenance_job_type_id' => $this->jobType, 'tipe_work_order_id' => $this->tipe]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.jenis_counter_id');
        $this->as(self::PLAN)->putJson(self::API.'rencana-pemeliharaan/'.$plan.'/objek', ['targets' => [['aset_id' => null]], 'version' => $this->planVersion($plan)])
            ->assertUnprocessable()->assertJsonValidationErrors('targets.0.aset_id');
    }

    public function test_the_schedule_duty_alone_cannot_create_work_orders(): void
    {
        $this->assertSame(0, Artisan::call('app:register-manifest', ['module' => 'management-aset']), Artisan::output());
        $aset = $this->seedAsset($this->jenisId, 'AST-DTY');
        $this->plan(['tanggal_mulai' => '2026-10-20'], [$this->timeLine('tanggal_mulai', 1, 'tahun')], [['aset_id' => $aset]]);

        $this->withDuty(['management-aset.jadwal-pemeliharaan.manage']);
        $this->postJson(self::API.'jadwal-pemeliharaan/hitung', ['sampai' => '2026-10-31'])->assertOk()->assertJsonPath('data.dibuat', 1);
        $line = $this->getJson(self::API.'jadwal-pemeliharaan')->assertOk()->json('data.0');
        $payload = ['lines' => [['id' => $line['id'], 'version' => $line['version']]]];
        $this->postJson(self::API.'jadwal-pemeliharaan/work-order', $payload)->assertForbidden();

        $this->withDuty(['management-aset.jadwal-pemeliharaan.manage', 'management-aset.pemeliharaan-aset.manage']);
        $this->postJson(self::API.'jadwal-pemeliharaan/work-order', $payload)->assertCreated();
    }

    public function test_schedule_only_covers_assets_in_the_users_organisation_scope(): void
    {
        $sendiri = $this->seedAsset($this->jenisId, 'AST-OWN');
        $unitLain = (string) Str::ulid();
        $lain = $this->seedAsset($this->jenisId, 'AST-OTH', ['responsible_org_unit_id' => $unitLain]);
        $this->plan(['tanggal_mulai' => '2026-10-20'], [$this->timeLine('tanggal_mulai', 1, 'tahun')], [['jenis_aset_id' => $this->jenisId]]);
        $terbatas = fn () => $this->sebagaiPenggunaBernama('Penjadwal unit', $this->tenantId, self::SCHEDULE, [[
            'policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $this->unitId,
        ]]);

        $terbatas()->postJson(self::API.'jadwal-pemeliharaan/hitung', ['sampai' => '2026-10-31'])->assertOk()->assertJsonPath('data.dibuat', 1);
        $this->assertSame(['2026-10-20'], $this->dueDates($sendiri));
        $this->assertSame([], $this->dueDates($lain));

        $this->schedule('2026-10-31')->assertOk()->assertJsonPath('data.dibuat', 1);
        $terbatas()->getJson(self::API.'jadwal-pemeliharaan')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.aset_id', $sendiri);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $targets
     */
    private function plan(array $header, array $lines, array $targets): string
    {
        $id = (string) $this->as(self::PLAN)
            ->withHeader('Idempotency-Key', 'rencana-'.Str::ulid())
            ->postJson(self::API.'rencana-pemeliharaan', ['nama' => 'Rencana uji', ...$header])
            ->assertCreated()->json('data.id');
        if ($lines !== []) {
            $this->putLines($id, $lines)->assertOk();
        }
        if ($targets !== []) {
            $this->as(self::PLAN)->putJson(self::API.'rencana-pemeliharaan/'.$id.'/objek', ['targets' => $targets, 'version' => $this->planVersion($id)])->assertOk();
        }

        return $id;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return TestResponse<Response>
     */
    private function putLines(string $plan, array $lines): TestResponse
    {
        return $this->as(self::PLAN)->putJson(self::API.'rencana-pemeliharaan/'.$plan.'/baris', ['lines' => $lines, 'version' => $this->planVersion($plan)]);
    }

    /** @return array<string, mixed> */
    private function timeLine(string $basis, int $interval, string $unit): array
    {
        return [
            'dasar' => $basis, 'interval' => $interval, 'satuan_interval' => $unit,
            'maintenance_job_type_id' => $this->jobType, 'tipe_work_order_id' => $this->tipe,
        ];
    }

    private function planVersion(string $plan): int
    {
        return (int) DB::table('aset_m_rencana_pemeliharaan')->where('id', $plan)->value('version');
    }

    /** @return TestResponse<Response> */
    private function schedule(string $until): TestResponse
    {
        return $this->as(self::SCHEDULE)->postJson(self::API.'jadwal-pemeliharaan/hitung', ['sampai' => $until]);
    }

    /** @return list<string> */
    private function dueDates(string $aset): array
    {
        return array_values(DB::table('aset_tr_jadwal_pemeliharaan')->where('aset_id', $aset)->whereNull('deleted_at')
            ->orderBy('jatuh_tempo')->pluck('jatuh_tempo')->map(static fn ($date): string => substr((string) $date, 0, 10))->all());
    }

    private function reading(string $aset, string $counter, string $at, int $value, int $total): void
    {
        DB::table('aset_tr_pembacaan_counter')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'aset_id' => $aset, 'jenis_counter_id' => $counter, 'dibaca_pada' => $at, 'nilai' => $value, 'nilai_total' => $total,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function as(array $permissions): static
    {
        return $this->sebagaiPenggunaBernama('perencana', $this->tenantId, $permissions);
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
