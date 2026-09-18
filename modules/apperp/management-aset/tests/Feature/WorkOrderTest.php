<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class WorkOrderTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        Http::fake(fn () => Http::response(['data' => ['number' => 'PMHA-000001']], 200));
    }

    public function test_work_order_disimpan_dengan_baris_pekerjaan_dan_nomor_dari_core(): void
    {
        $seed = $this->seedMasters();
        $workOrder = $this->create($seed)->assertCreated()->assertJsonPath('data.kode', 'PMHA-000001')->json('data');

        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset', [
            'id' => $workOrder['id'], 'tenant_id' => $this->tenantId,
            'responsible_org_unit_id' => $this->orgUnitId, 'status' => 'draft', 'version' => 1,
        ]);
        // Lokasi aset disalin ke baris, bukan dibaca ulang lewat aset saat ditampilkan.
        $this->assertDatabaseHas('aset_tr_pemeliharaan_aset_details', [
            'pemeliharaan_aset_id' => $workOrder['id'], 'line_number' => 1,
            'aset_id' => $seed['aset'], 'lokasi_aset_id' => $seed['location'],
            'maintenance_job_type_id' => $seed['jobType'], 'trade_id' => $seed['trade'],
        ]);
        $this->assertSame(1, $this->jumlahNomorTerbit(), 'Penerbitan nomor tidak terjadi.');

        $this->headers(['management-aset.pemeliharaan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$workOrder['id'])
            ->assertOk()
            ->assertJsonPath('data.tipe_work_order_nama', 'Korektif')
            ->assertJsonPath('data.details.0.job_type_nama', 'Ganti ban')
            ->assertJsonPath('data.details.0.aset_kode', 'AST-WO-1');
    }

    public function test_menolak_aset_milik_tenant_lain_sebelum_menerbitkan_nomor(): void
    {
        $seed = $this->seedMasters();
        // Aset tenant lain wajib menunjuk klasifikasi milik tenant itu sendiri; foreign key
        // komposit menolak induk lintas tenant sebelum validasi aplikasi sempat berbicara.
        $asing = (string) Str::ulid();
        $seed['aset'] = $this->aset($asing, [
            'group' => $this->master('aset_m_group_aset', 'Kendaraan', 'GRPA-X', tenant: $asing),
            'jenis' => $this->master('aset_m_jenis_aset', 'Roda 4', 'JNSA-X', tenant: $asing),
        ], 'AST-LAIN');

        $this->create($seed)->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertDatabaseCount('aset_tr_pemeliharaan_aset', 0);
        $this->assertSame(0, $this->jumlahNomorTerbit(), 'Ada nomor yang terbit padahal seharusnya tidak.');
    }

    public function test_menolak_varian_dari_jenis_pekerjaan_lain(): void
    {
        $seed = $this->seedMasters();
        $lain = $this->jobType('Servis rem', 'JOB-LAIN');
        $payload = $this->payload($seed);
        $payload['details'][0]['variant_id'] = $this->variant($lain, 'VAR-LAIN');

        $this->submit($payload)->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertSame(0, $this->jumlahNomorTerbit(), 'Ada nomor yang terbit padahal seharusnya tidak.');
    }

    public function test_update_dan_archive_menuntut_izinnya_sendiri_dan_versi_terkini(): void
    {
        $seed = $this->seedMasters();
        $workOrder = $this->create($seed)->assertCreated()->json('data');
        $payload = $this->payload($seed);
        $payload['keterangan'] = 'Ban depan kiri juga bocor';
        $payload['version'] = 1;

        $this->headers(['management-aset.pemeliharaan-aset.read'])
            ->patchJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$workOrder['id'], $payload)->assertForbidden();
        $this->headers(['management-aset.pemeliharaan-aset.read', 'management-aset.pemeliharaan-aset.update'])
            ->patchJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$workOrder['id'], $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->headers(['management-aset.pemeliharaan-aset.read', 'management-aset.pemeliharaan-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$workOrder['id'], ['version' => 1])->assertConflict();
        $this->headers(['management-aset.pemeliharaan-aset.read', 'management-aset.pemeliharaan-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/pemeliharaan-aset/'.$workOrder['id'], ['version' => 2])->assertNoContent();
        $this->assertSoftDeleted('aset_tr_pemeliharaan_aset', ['id' => $workOrder['id']]);
    }

    public function test_permintaan_yang_diulang_mengembalikan_record_yang_sama_tanpa_nomor_baru(): void
    {
        $seed = $this->seedMasters();
        $key = 'wo-'.Str::ulid();
        $first = $this->submit($this->payload($seed), $key)->assertCreated()->json('data.id');

        $this->submit($this->payload($seed), $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('aset_tr_pemeliharaan_aset', 1);
        $this->assertSame(1, $this->jumlahNomorTerbit(), 'Jumlah nomor yang benar-benar diterbitkan Core tidak sesuai.');
    }

    public function test_daftar_hanya_menampilkan_work_order_di_dalam_jangkauan_organisasi(): void
    {
        $seed = $this->seedMasters();
        $this->create($seed)->assertCreated();

        $asing = [[
            'policy_code' => 'management-aset.asset-responsibility',
            'legal_entity_id' => (string) Str::ulid(),
            'organization_id' => (string) Str::ulid(),
        ]];
        $data = $this->sebagaiPengguna(
            $this->tenantId,
            ['management-aset.pemeliharaan-aset.read'],
            $asing,
        )->getJson('/api/modules/management-aset/v1/pemeliharaan-aset')->assertOk()->json('data');

        $this->assertSame([], $data);
    }

    /**
     * Route `pemeliharaan-aset` tidak boleh lagi jatuh ke dokumen siklus generik. Payload
     * lama yang hanya membawa tanggal dan nilai kini ditolak, dan pembuatan yang sah
     * menulis ke tabel work order, bukan ke `aset_tr_dokumen_siklus_aset`.
     */
    public function test_route_pemeliharaan_tidak_lagi_dilayani_dokumen_siklus_generik(): void
    {
        $this->headers(['management-aset.pemeliharaan-aset.create'])
            ->withHeader('Idempotency-Key', 'wo-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/pemeliharaan-aset', [
                'legal_entity_id' => $this->legalEntityId,
                'responsible_org_unit_id' => $this->orgUnitId,
                'tanggal' => '2026-08-15',
                'nilai' => 500000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tipe_work_order_id', 'details']);

        $this->create($this->seedMasters())->assertCreated();
        $this->assertDatabaseCount('aset_tr_dokumen_siklus_aset', 0);
        $this->assertDatabaseCount('aset_tr_pemeliharaan_aset', 1);
    }

    /**
     * Work order tidak boleh dibuat untuk aset yang sudah berhenti dipakai.
     *
     * Padanan penanda **Active** pada `Asset lifecycle state` di Dynamics 365 Aset
     * Management. Sampai 18 September 2026 pemeriksaan ini tidak ada: work order dapat
     * dijadwalkan untuk aset yang sudah dijual atau dimusnahkan.
     */
    public function test_work_order_ditolak_untuk_aset_yang_dihentikan_atau_dilepas(): void
    {
        // Master disemai sekali: kodenya tetap, jadi memanggil `seedMasters()` dua kali
        // menabrak `unique (tenant_id, kode)` dan menggagalkan test karena sebab yang
        // tidak ada hubungannya dengan yang diuji.
        $seed = $this->seedMasters();

        foreach ([StatusAset::DIHENTIKAN, StatusAset::DILEPAS] as $status) {
            DB::table('aset_tr_aset')
                ->where('id', $seed['aset'])
                ->update(['lifecycle_state' => $status]);

            $this->create($seed)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('details');
        }

        $this->assertDatabaseCount('aset_tr_pemeliharaan_aset', 0);
    }

    public function test_work_order_tetap_boleh_untuk_aset_yang_masih_beredar(): void
    {
        $seed = $this->seedMasters();
        DB::table('aset_tr_aset')
            ->where('id', $seed['aset'])
            ->update(['lifecycle_state' => StatusAset::DITERIMA]);

        $this->create($seed)->assertCreated();
    }

    /**
     * @param  array<string, string>  $seed
     * @return TestResponse<Response>
     */
    private function create(array $seed): TestResponse
    {
        return $this->submit($this->payload($seed));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function submit(array $payload, ?string $key = null): TestResponse
    {
        return $this->headers(['management-aset.pemeliharaan-aset.create'])
            ->withHeader('Idempotency-Key', $key ?? 'wo-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/pemeliharaan-aset', $payload);
    }

    /** @param  list<string>  $permissions */
    private function headers(array $permissions): static
    {
        return $this->sebagaiPengguna($this->tenantId, $permissions);
    }

    /**
     * @param  array<string, string>  $seed
     * @return array<string, mixed>
     */
    private function payload(array $seed): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId,
            'responsible_org_unit_id' => $this->orgUnitId,
            'tipe_work_order_id' => $seed['tipe'],
            'tingkat_layanan_id' => $seed['layanan'],
            'keterangan' => 'Ban depan kanan bocor',
            'diharapkan_mulai' => '2026-08-15 08:00:00',
            'diharapkan_selesai' => '2026-08-15 12:00:00',
            'details' => [[
                'aset_id' => $seed['aset'],
                'maintenance_job_type_id' => $seed['jobType'],
                'trade_id' => $seed['trade'],
                'ditugaskan_ke_user_id' => 'montir-1',
                'estimasi_jam' => 1.5,
            ]],
        ];
    }

    /** @return array<string, string> */
    private function seedMasters(): array
    {
        $seed = [
            'tipe' => $this->master('aset_m_tipe_work_order', 'Korektif', 'TPWO-1'),
            'layanan' => $this->master('aset_m_tingkat_layanan', 'Mendesak', 'TGLY-1', ['urutan' => 1]),
            'trade' => $this->master('aset_m_trade', 'Mekanik', 'TRDE-1'),
            'jobType' => $this->jobType('Ganti ban', 'JOB-1'),
            'group' => $this->master('aset_m_group_aset', 'Kendaraan', 'GRPA-1'),
            'jenis' => $this->master('aset_m_jenis_aset', 'Kendaraan roda 4', 'JNSA-1'),
            'tipeLokasi' => $this->master('aset_m_tipe_lokasi_aset', 'Gudang', 'TLKA-1'),
        ];
        $seed['location'] = $this->location($seed['tipeLokasi']);
        $seed['aset'] = $this->aset($this->tenantId, $seed, 'AST-WO-1');

        return $seed;
    }

    private function jobType(string $nama, string $kode): string
    {
        return $this->master('aset_m_maintenance_job_type', $nama, $kode, ['category_code' => 'corrective']);
    }

    private function variant(string $jobType, string $kode): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_m_maintenance_job_type_variant')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'maintenance_job_type_id' => $jobType, 'kode' => $kode, 'nama' => 'Varian', 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function location(string $tipeLokasi): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_m_lokasi_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'LOCA-1', 'nama' => 'Gudang Cakung', 'tipe_lokasi_id' => $tipeLokasi, 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, string> $seed */
    private function aset(string $tenant, array $seed, string $kode): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_aset')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
            'nama' => 'Aset work order '.$kode,
            'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
            'group_aset_id' => $seed['group'], 'jenis_aset_id' => $seed['jenis'],
            'lokasi_aset_id' => $tenant === $this->tenantId ? $seed['location'] : null,
            'acquired_on' => '2026-08-01', 'acquisition_value' => 250000000, 'currency_code' => 'IDR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $extra */
    private function master(string $table, string $nama, string $kode, array $extra = [], ?string $tenant = null): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $tenant ?? $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $kode, 'nama' => $nama, 'aktif' => true,
            ...$extra,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
