<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\TenantRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\MaintenanceKpi;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\SeedsMaintenanceFixtures;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Downtime aset dan KPI pemeliharaan (padanan *Maintenance downtime* dan *Asset KPIs* F&O).
 *
 * Yang dijaga: catatan downtime satu aset tidak tumpang tindih; work order dengan pekerjaan yang
 * menuntut aset berhenti membuka dan menutup downtime-nya sendiri; dan rumus KPI menghasilkan angka
 * yang dihitung tangan di test ini.
 */
class AssetDowntimeTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase, SeedsMaintenanceFixtures;

    private const URL = '/api/modules/management-aset/v1/downtime-aset';

    private const RECORDER = [
        'management-aset.downtime-aset.read', 'management-aset.downtime-aset.create',
        'management-aset.downtime-aset.update', 'management-aset.downtime-aset.archive',
    ];

    private const WORK_ORDER = [
        'management-aset.pemeliharaan-aset.read', 'management-aset.pemeliharaan-aset.schedule',
        'management-aset.pemeliharaan-aset.execute', 'management-aset.pemeliharaan-aset.close',
    ];

    private string $tenantId;

    private string $legalEntityId;

    private string $unitId;

    private string $jenisAset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->unitId = (string) Str::ulid();
        $this->jenisAset = $this->seedMaster('aset_m_jenis_aset', 'Mesin produksi');
    }

    public function test_manual_downtime_cannot_overlap_or_lie_in_the_future(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'MSN-01');
        $created = $this->record(['aset_id' => $aset, 'mulai' => '2026-09-01 08:00', 'selesai' => '2026-09-01 12:30'])->assertCreated();
        $this->assertSame(4.5, $created->json('data.durasi_jam'));
        $id = (string) $created->json('data.id');

        $this->record(['aset_id' => $aset, 'mulai' => '2026-09-01 12:00', 'selesai' => '2026-09-01 13:00'])->assertUnprocessable()->assertJsonValidationErrors('mulai');
        $this->record(['aset_id' => $aset, 'mulai' => '2026-09-01 13:00', 'selesai' => '2026-09-01 12:00'])->assertUnprocessable()->assertJsonValidationErrors('selesai');
        $this->record(['aset_id' => $aset, 'mulai' => now()->addDay()->toDateTimeString()])->assertUnprocessable()->assertJsonValidationErrors('mulai');

        // Satu catatan terbuka per aset.
        $open = (string) $this->record(['aset_id' => $aset, 'mulai' => '2026-09-02 08:00'])->assertCreated()->assertJsonPath('data.terbuka', true)->json('data.id');
        $this->record(['aset_id' => $aset, 'mulai' => '2026-09-03 08:00'])->assertUnprocessable()->assertJsonValidationErrors('mulai');

        $this->as(self::RECORDER)->patchJson(self::URL.'/'.$open, ['mulai' => '2026-09-02 08:00', 'selesai' => '2026-09-02 10:00'])->assertStatus(428);
        $this->as(self::RECORDER)->patchJson(self::URL.'/'.$open, ['mulai' => '2026-09-02 08:00', 'selesai' => '2026-09-02 10:00', 'version' => $this->version($open)])
            ->assertOk()->assertJsonPath('data.terbuka', false)->assertJsonPath('data.durasi_jam', 2);
        $this->as(self::RECORDER)->deleteJson(self::URL.'/'.$id, ['version' => $this->version($id)])->assertNoContent();
        $this->as(self::RECORDER)->getJson(self::URL.'?aset_id='.$aset)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_work_order_with_a_stopping_job_opens_and_closes_downtime(): void
    {
        $stop = $this->seedMaster('aset_m_maintenance_job_type', 'Overhaul', ['category_code' => 'corrective', 'maintenance_downtime_activities' => true]);
        $inspect = $this->seedMaster('aset_m_maintenance_job_type', 'Inspeksi', ['category_code' => 'preventive']);
        $stopped = $this->seedAsset($this->jenisAset, 'MSN-02');
        $running = $this->seedAsset($this->jenisAset, 'MSN-03');
        $workOrder = $this->seedWorkOrder($stopped, $stop, 'dijadwalkan', ['dijadwalkan_mulai' => now()->subHour()]);
        DB::table('aset_tr_pemeliharaan_aset_details')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'pemeliharaan_aset_id' => $workOrder,
            'line_number' => 2, 'aset_id' => $running, 'maintenance_job_type_id' => $inspect, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->move($workOrder, 'dikerjakan')->assertOk();
        $this->assertSame(1, DB::table('aset_tr_downtime_aset')->count(), 'Hanya aset dengan pekerjaan yang menuntut berhenti.');
        $this->assertDatabaseHas('aset_tr_downtime_aset', ['aset_id' => $stopped, 'pemeliharaan_aset_id' => $workOrder, 'sumber' => 'work_order', 'selesai' => null]);

        $this->move($workOrder, 'selesai')->assertOk();
        $this->assertSame(0, DB::table('aset_tr_downtime_aset')->whereNull('selesai')->count());
        $this->as(self::RECORDER)->getJson(self::URL.'?pemeliharaan_aset_id='.$workOrder)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.pemeliharaan_aset_kode', DB::table('aset_tr_pemeliharaan_aset')->where('id', $workOrder)->value('kode'));
    }

    public function test_open_manual_downtime_is_taken_over_by_the_work_order(): void
    {
        $stop = $this->seedMaster('aset_m_maintenance_job_type', 'Perbaikan', ['category_code' => 'corrective', 'maintenance_downtime_activities' => true]);
        $aset = $this->seedAsset($this->jenisAset, 'MSN-04');
        $reported = (string) $this->record(['aset_id' => $aset, 'mulai' => now()->subHours(5)->toDateTimeString()])->assertCreated()->json('data.id');
        $workOrder = $this->seedWorkOrder($aset, $stop, 'dijadwalkan', ['dijadwalkan_mulai' => now()->subHour()]);

        $this->move($workOrder, 'dikerjakan')->assertOk();
        $this->assertSame(1, DB::table('aset_tr_downtime_aset')->count(), 'Catatan operator dipakai, bukan dibuat ganda.');
        $this->assertDatabaseHas('aset_tr_downtime_aset', ['id' => $reported, 'pemeliharaan_aset_id' => $workOrder, 'sumber' => 'manual']);

        $this->move($workOrder, 'dibatalkan', 'Suku cadang tidak tersedia')->assertOk();
        $this->assertNotNull(DB::table('aset_tr_downtime_aset')->where('id', $reported)->value('selesai'));
    }

    public function test_kpi_formulas_match_a_hand_calculation(): void
    {
        $repair = $this->seedMaster('aset_m_maintenance_job_type', 'Perbaikan', ['category_code' => 'corrective']);
        $fault = $this->seedMaster('aset_m_sebab_kerusakan', 'Bantalan aus');
        $planned = $this->seedMaster('aset_m_alasan_downtime', 'Henti terencana', ['masuk_kpi' => false]);
        $a = $this->seedAsset($this->jenisAset, 'MSN-A', ['acquired_on' => '2026-01-01']);
        // Aset B baru dipakai 16 September: rentangnya 15 hari, bukan 30.
        $b = $this->seedAsset($this->jenisAset, 'MSN-B', ['acquired_on' => '2026-01-01', 'placed_in_service_on' => '2026-09-16']);

        // Aset A, September 2026 (720 jam): 10 + 2 jam masuk KPI, 5 jam henti terencana tidak.
        $this->seedDowntime($a, '2026-09-03 00:00:00', '2026-09-03 10:00:00');
        $this->seedDowntime($a, '2026-09-10 06:00:00', '2026-09-10 08:00:00');
        $this->seedDowntime($a, '2026-09-20 00:00:00', '2026-09-20 05:00:00', $planned);
        // Downtime yang mulai Agustus hanya terhitung bagian Septembernya: 4 jam.
        $this->seedDowntime($a, '2026-08-31 20:00:00', '2026-09-01 04:00:00');
        // Dua kerusakan (3 + 5 jam kerja) dan satu pekerjaan tanpa kerusakan pada dua work order selesai.
        $this->seedFinishedWorkOrder($a, $repair, '2026-09-04 10:00:00', [[$fault, 3], [null, 1]]);
        $this->seedFinishedWorkOrder($a, $repair, '2026-09-11 10:00:00', [[$fault, 5]]);
        // Work order selesai Oktober tidak ikut periode September.
        $this->seedFinishedWorkOrder($a, $repair, '2026-10-01 00:30:00', [[$fault, 7]]);

        // Dipanggil langsung, seperti dashboard kelak memanggilnya, di dalam tenant yang aktif.
        $kpi = fn (string $groupBy): array => app(TenantRunner::class)->runFor($this->tenantId, fn (): array => app(MaintenanceKpi::class)
            ->calculate(Aset::query(), Carbon::parse('2026-09-01', 'UTC'), Carbon::parse('2026-10-01', 'UTC'), $groupBy));
        $result = $kpi(MaintenanceKpi::BY_ASSET);
        $rows = collect($result['baris'])->keyBy('kode');

        $this->assertSame(720.0, $rows['MSN-A']['total_jam']);
        $this->assertSame(16.0, $rows['MSN-A']['downtime_jam']);
        $this->assertSame(704.0, $rows['MSN-A']['uptime_jam']);
        $this->assertSame(97.78, $rows['MSN-A']['availability_persen']);
        $this->assertSame(3, $rows['MSN-A']['jumlah_henti']);
        $this->assertSame(2, $rows['MSN-A']['jumlah_kerusakan']);
        $this->assertSame(360.0, $rows['MSN-A']['mtbf_jam']);
        $this->assertSame(8.0, $rows['MSN-A']['jam_perbaikan']);
        $this->assertSame(4.0, $rows['MSN-A']['mttr_jam']);
        $this->assertSame(2, $rows['MSN-A']['wo_selesai']);

        // Tanpa kerusakan, MTBF sama dengan total waktu dan MTTR dengan jam perbaikan (nol).
        $this->assertSame(360.0, $rows['MSN-B']['total_jam']);
        $this->assertSame(100.0, $rows['MSN-B']['availability_persen']);
        $this->assertSame(360.0, $rows['MSN-B']['mtbf_jam']);
        $this->assertSame(0.0, $rows['MSN-B']['mttr_jam']);

        // Kelompok menjumlahkan lalu menghitung ulang rasionya.
        $byType = $kpi(MaintenanceKpi::BY_TYPE);
        $this->assertCount(1, $byType['baris']);
        $this->assertSame(1080.0, $byType['baris'][0]['total_jam']);
        $this->assertSame(98.52, $byType['baris'][0]['availability_persen']);
        $this->assertSame(540.0, $byType['baris'][0]['mtbf_jam']);
        $this->assertSame($byType['total']['total_jam'], $byType['baris'][0]['total_jam']);
    }

    public function test_kpi_screen_requires_its_permission_and_reads_the_period_in_user_days(): void
    {
        $this->seedAsset($this->jenisAset, 'MSN-K', ['acquired_on' => '2026-01-01']);
        $this->as(self::RECORDER)->getJson('/api/modules/management-aset/v1/kpi-pemeliharaan')->assertForbidden();
        $this->as(['management-aset.kpi-pemeliharaan.read'])
            ->getJson('/api/modules/management-aset/v1/kpi-pemeliharaan?dari=2026-09-01&sampai=2026-09-30&kelompok=lokasi')
            ->assertOk()->assertJsonPath('meta.dari', '2026-09-01')->assertJsonPath('meta.sampai', '2026-09-30')
            ->assertJsonPath('meta.total.total_jam', 720)->assertJsonPath('meta.total.jumlah_aset', 1);
        $this->as(['management-aset.kpi-pemeliharaan.read'])->getJson('/api/modules/management-aset/v1/kpi-pemeliharaan?dari=2024-01-01&sampai=2026-09-30')->assertUnprocessable();
    }

    public function test_downtime_follows_the_asset_scope(): void
    {
        $aset = $this->seedAsset($this->jenisAset, 'MSN-S');
        $this->record(['aset_id' => $aset, 'mulai' => '2026-09-05 08:00', 'selesai' => '2026-09-05 09:00'])->assertCreated();

        $unit = fn (string $name, string $unitId) => $this->sebagaiPenggunaBernama($name, $this->tenantId, self::RECORDER, [[
            'policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => $this->legalEntityId, 'organization_id' => $unitId,
        ]]);
        $unit('Unit sendiri', $this->unitId)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $unit('Unit lain', (string) Str::ulid())->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $unit('Unit lain', (string) Str::ulid())->withHeader('Idempotency-Key', 'downtime-'.Str::ulid())
            ->postJson(self::URL, ['aset_id' => $aset, 'mulai' => '2026-09-06 08:00'])->assertUnprocessable()->assertJsonValidationErrors('aset_id');
    }

    private function seedDowntime(string $aset, string $from, string $until, ?string $reason = null): void
    {
        DB::table('aset_tr_downtime_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'aset_id' => $aset,
            'mulai' => $from, 'selesai' => $until, 'alasan_downtime_id' => $reason, 'sumber' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<array{0: ?string, 1: float}>  $lines  sebab kerusakan dan jam aktual per baris */
    private function seedFinishedWorkOrder(string $aset, string $jobType, string $finishedAt, array $lines): void
    {
        $workOrder = $this->seedWorkOrder($aset, $jobType, 'ditutup', ['aktual_mulai' => $finishedAt, 'aktual_selesai' => $finishedAt]);
        DB::table('aset_tr_pemeliharaan_aset_details')->where('pemeliharaan_aset_id', $workOrder)->delete();
        foreach ($lines as $index => [$fault, $hours]) {
            DB::table('aset_tr_pemeliharaan_aset_details')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'pemeliharaan_aset_id' => $workOrder,
                'line_number' => $index + 1, 'aset_id' => $aset, 'maintenance_job_type_id' => $jobType,
                'sebab_kerusakan_id' => $fault, 'aktual_jam' => $hours, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function record(array $payload): TestResponse
    {
        return $this->as(self::RECORDER)->withHeader('Idempotency-Key', 'downtime-'.Str::ulid())->postJson(self::URL, $payload);
    }

    /** @return TestResponse<Response> */
    private function move(string $workOrder, string $target, ?string $reason = null): TestResponse
    {
        return $this->as(self::WORK_ORDER)->postJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$workOrder.'/status', array_filter([
            'ke_status' => $target, 'alasan' => $reason,
            'version' => (int) DB::table('aset_tr_pemeliharaan_aset')->where('id', $workOrder)->value('version'),
        ], static fn ($value) => $value !== null));
    }

    private function version(string $id): int
    {
        return (int) DB::table('aset_tr_downtime_aset')->where('id', $id)->value('version');
    }

    /** @param  list<string>  $permissions */
    private function as(array $permissions): static
    {
        return $this->sebagaiPenggunaBernama('pengguna', $this->tenantId, $permissions);
    }
}
