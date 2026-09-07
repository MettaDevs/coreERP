<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

/**
 * Endpoint laporan yang dipanggil Core: definisi, layout bawaan, dan dataset. Core
 * memanggil dengan token konteks pengguna, jadi yang diuji di sini adalah bahwa app
 * menegakkan permission dan scope organisasi pada jalur itu persis seperti pada layar.
 */
class LaporanInternalTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        $this->configureCoreErpContext();
        Http::fake(fn () => Http::response(['data' => ['number' => 'PMHA-000001']], 200));
    }

    public function test_definition_lists_placeholders_and_builtin_layouts(): void
    {
        $this->withHeaders($this->headers(['management-aset.pemeliharaan-aset.read']))
            ->getJson('/api/internal/v1/laporan/work-order')
            ->assertOk()
            ->assertJsonPath('data.kode', 'work-order')
            ->assertJsonPath('data.parameters', ['id'])
            ->assertJsonPath('data.builtin_layouts.0.key', 'standar')
            ->assertJsonPath('data.builtin_layouts.0.format', 'docx')
            ->assertJsonFragment(['key' => 'baris.asset_kode', 'table' => 'baris']);

        $this->withHeaders($this->headers(['management-aset.pemeliharaan-aset.read']))
            ->get('/api/internal/v1/laporan/work-order/layouts/standar')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=work-order-standar.docx');

        $this->withHeaders($this->headers(['management-aset.pemeliharaan-aset.read']))
            ->getJson('/api/internal/v1/laporan/tidak-ada')
            ->assertNotFound();
    }

    public function test_dataset_needs_the_data_permission_and_respects_organization_scope(): void
    {
        $workOrder = $this->workOrder();

        $this->withHeaders($this->headers(['management-aset.aset.read']))
            ->postJson('/api/internal/v1/laporan/work-order/dataset', ['parameter' => ['id' => $workOrder]])
            ->assertForbidden();

        $this->withHeaders($this->headers(['management-aset.pemeliharaan-aset.read']))
            ->postJson('/api/internal/v1/laporan/work-order/dataset', ['parameter' => ['id' => $workOrder]])
            ->assertOk()
            ->assertJsonPath('data.fields.kode', 'PMHA-000001')
            ->assertJsonPath('data.fields.tipe_work_order', 'Korektif')
            ->assertJsonPath('data.tables.baris.0.asset_kode', 'AST-WO-1')
            ->assertJsonPath('data.tables.baris.0.jenis_pekerjaan', 'Ganti ban')
            ->assertJsonPath('data.file_name', 'PMHA-000001');

        // Di luar scope organisasi pengguna: pesan yang sama dengan layar, status 422
        // supaya Core menampilkannya pada baris ekspor, bukan sebagai kesalahan server.
        $this->withHeaders($this->headers(['management-aset.pemeliharaan-aset.read'], dataPolicies: [
            'management-aset.asset-responsibility' => ['all' => false, 'scope_grants' => [
                ['legal_entity_id' => $this->legalEntityId, 'operating_unit_ids' => [(string) Str::ulid()]],
            ]],
        ]))
            ->postJson('/api/internal/v1/laporan/work-order/dataset', ['parameter' => ['id' => $workOrder]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Work order tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.');

        $this->withHeaders($this->headers(['management-aset.pemeliharaan-aset.read']))
            ->postJson('/api/internal/v1/laporan/work-order/dataset', ['parameter' => ['id' => 'bukan-ulid']])
            ->assertStatus(422);
    }

    public function test_list_dataset_filters_by_status_and_returns_one_row_per_work_order(): void
    {
        $this->workOrder();

        $this->withHeaders($this->headers(['management-aset.pemeliharaan-aset.read']))
            ->postJson('/api/internal/v1/laporan/daftar-work-order/dataset', ['parameter' => ['status' => 'draft']])
            ->assertOk()
            ->assertJsonPath('data.fields.jumlah_work_order', 1)
            ->assertJsonPath('data.tables.baris.0.kode', 'PMHA-000001')
            ->assertJsonPath('data.tables.baris.0.jumlah_baris', 1);

        $this->withHeaders($this->headers(['management-aset.pemeliharaan-aset.read']))
            ->postJson('/api/internal/v1/laporan/daftar-work-order/dataset', ['parameter' => ['status' => 'ditutup']])
            ->assertOk()
            ->assertJsonPath('data.fields.jumlah_work_order', 0)
            ->assertJsonPath('data.tables.baris', []);
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, mixed>|null  $dataPolicies
     * @return array<string, string>
     */
    private function headers(array $permissions, ?array $dataPolicies = null): array
    {
        $claims = ['sub' => 'planner-1', 'legal_entity_id' => $this->legalEntityId, 'org_unit_id' => $this->orgUnitId];
        if ($dataPolicies !== null) {
            $claims['data_policies'] = $dataPolicies;
        }

        return $this->contextHeaders($this->tenantId, $permissions, $claims);
    }

    /** Work order draf dengan satu baris pekerjaan, dibuat lewat API seperti pengguna. */
    private function workOrder(): string
    {
        $seed = [
            'tipe' => $this->master('m_tipe_work_order', 'Korektif', 'TPWO-1'),
            'layanan' => $this->master('m_tingkat_layanan', 'Mendesak', 'TGLY-1', ['urutan' => 1]),
            'trade' => $this->master('m_trade', 'Mekanik', 'TRDE-1'),
            'jobType' => $this->master('m_maintenance_job_type', 'Ganti ban', 'JOB-1', ['category_code' => 'corrective']),
            'group' => $this->master('m_group_aset', 'Kendaraan', 'GRPA-1'),
            'jenis' => $this->master('m_jenis_aset', 'Kendaraan roda 4', 'JNSA-1'),
            'tipeLokasi' => $this->master('m_tipe_lokasi_aset', 'Gudang', 'TLKA-1'),
        ];
        $locationId = (string) Str::ulid();
        DB::table('m_lokasi_aset')->insert([
            'id' => $locationId, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'LOCA-1', 'nama' => 'Gudang Cakung', 'tipe_lokasi_id' => $seed['tipeLokasi'], 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $assetId = (string) Str::ulid();
        DB::table('tr_penerimaan_aset')->insert([
            'id' => $assetId, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => 'AST-WO-1',
            'nama' => 'Forklift 1', 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
            'group_aset_id' => $seed['group'], 'jenis_aset_id' => $seed['jenis'], 'asset_location_id' => $locationId,
            'acquired_on' => '2026-08-01', 'acquisition_value' => 250000000, 'currency_code' => 'IDR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->withHeaders($this->headers(['management-aset.pemeliharaan-aset.create']))
            ->withHeader('Idempotency-Key', 'wo-'.Str::ulid())
            ->postJson('/api/v1/pemeliharaan-aset', [
                'legal_entity_id' => $this->legalEntityId,
                'responsible_org_unit_id' => $this->orgUnitId,
                'tipe_work_order_id' => $seed['tipe'],
                'tingkat_layanan_id' => $seed['layanan'],
                'keterangan' => 'Ban depan kanan bocor',
                'diharapkan_mulai' => '2026-08-15 08:00:00',
                'diharapkan_selesai' => '2026-08-15 12:00:00',
                'details' => [[
                    'asset_id' => $assetId, 'maintenance_job_type_id' => $seed['jobType'], 'trade_id' => $seed['trade'],
                    'ditugaskan_ke_user_id' => 'montir-1', 'estimasi_jam' => 1.5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /** @param array<string, mixed> $extra */
    private function master(string $table, string $nama, string $kode, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $kode, 'nama' => $nama, 'aktif' => true, ...$extra,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
