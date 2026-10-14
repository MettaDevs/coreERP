<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Modules\Contracts\TenantRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Reporting\Definitions\PurchaseRequestDocument;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

/**
 * Pengaman edit bersamaan pada permintaan pembelian aset: ubah dan batal hanya berhasil
 * pada versi yang dibuka penggunanya.
 */
class PermintaanPembelianAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private const API = '/api/modules/management-aset/v1/permintaan-pembelian-aset/';

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    private string $jenisId;

    /** @var array<string, array{id: string, unit: string}> */
    private array $planningLines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->orgUnitId = (string) Str::ulid();
        $this->jenisId = $this->jenis();
    }

    public function test_purchase_request_numbers_can_repeat_in_another_legal_entity(): void
    {
        $permission = ['management-aset.permintaan-pembelian-aset.create'];
        $first = $this->sebagaiPengguna($this->tenantId, $permission)
            ->withHeader('Idempotency-Key', 'request-'.Str::ulid())
            ->postJson(rtrim(self::API, '/'), $this->payload('Permintaan uji', 1))->assertCreated()->json('data');
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $second = $this->sebagaiPengguna($this->tenantId, $permission)
            ->withHeader('Idempotency-Key', 'request-'.Str::ulid())
            ->postJson(rtrim(self::API, '/'), $this->payload('Permintaan uji', 1))->assertCreated()->json('data');
        $this->assertSame($first['kode'], $second['kode']);
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function test_simpan_kedua_dengan_versi_yang_sama_ditolak_dan_baris_simpan_pertama_bertahan(): void
    {
        $id = $this->permintaan();
        $pengubah = $this->sebagaiPengguna($this->tenantId, ['management-aset.permintaan-pembelian-aset.read', 'management-aset.permintaan-pembelian-aset.update']);

        $baru = $pengubah->patchJson(self::API.$id, [...$this->payload('Simpan pertama', 2), 'version' => 1])
            ->assertOk()->json('data.version');
        $this->assertGreaterThan(1, $baru);
        $pengubah->patchJson(self::API.$id, [...$this->payload('Simpan kedua', 7), 'version' => 1])
            ->assertConflict()
            ->assertJsonPath('error.code', 'stale_version')
            ->assertJsonPath('error.message', RowVersion::STALE_MESSAGE);

        $this->assertDatabaseHas('aset_tr_permintaan_pengadaan_aset', ['id' => $id, 'description' => 'Simpan pertama']);
        $baris = DB::table('aset_tr_permintaan_pengadaan_aset_details')->where('request_id', $id)->get(['specification', 'quantity']);
        $this->assertCount(1, $baris);
        $this->assertSame('Simpan pertama', $baris[0]->specification);
        $this->assertSame(2.0, (float) $baris[0]->quantity);

        // Pembatalan dengan versi basi juga ditolak; dengan versi terbaru berhasil.
        $pembatal = $this->sebagaiPengguna($this->tenantId, ['management-aset.permintaan-pembelian-aset.read', 'management-aset.permintaan-pembelian-aset.cancel']);
        $pembatal->postJson(self::API.$id.'/batal', ['version' => 1])->assertConflict()->assertJsonPath('error.code', 'stale_version');
        $this->assertDatabaseHas('aset_tr_permintaan_pengadaan_aset', ['id' => $id, 'status' => 'draft']);
        $pembatal->postJson(self::API.$id.'/batal', ['version' => $baru])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_simpan_dan_batal_tanpa_versi_ditolak_dan_rincian_memulangkan_etag(): void
    {
        $id = $this->permintaan();

        $this->sebagaiPengguna($this->tenantId, ['management-aset.permintaan-pembelian-aset.read', 'management-aset.permintaan-pembelian-aset.update'])
            ->patchJson(self::API.$id, $this->payload('Tanpa versi', 3))
            ->assertStatus(428)->assertJsonPath('error.code', 'version_required');
        $this->sebagaiPengguna($this->tenantId, ['management-aset.permintaan-pembelian-aset.read', 'management-aset.permintaan-pembelian-aset.cancel'])
            ->postJson(self::API.$id.'/batal')
            ->assertStatus(428);
        $this->assertDatabaseHas('aset_tr_permintaan_pengadaan_aset', ['id' => $id, 'status' => 'draft', 'description' => 'Permintaan awal', 'version' => 1]);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.permintaan-pembelian-aset.read'])
            ->getJson(self::API.$id)
            ->assertOk()->assertJsonPath('data.version', 1)->assertHeader('ETag', RowVersion::etag(1));
    }

    /** @return array<string, mixed> */
    private function payload(string $keterangan, int $jumlah): array
    {
        $planLine = $this->planningLine();

        return [
            'legal_entity_id' => $this->legalEntityId, 'requesting_org_unit_id' => $this->orgUnitId,
            'requested_on' => '2026-06-01', 'description' => $keterangan,
            'details' => [[
                'planning_detail_id' => $planLine['id'],
                'jenis_aset_id' => $this->jenisId, 'satuan_id' => $planLine['unit'],
                'quantity' => $jumlah, 'specification' => $keterangan,
            ]],
        ];
    }

    public function test_request_requires_a_plan_and_rejects_mismatched_type_unit_and_company(): void
    {
        $payload = $this->payload('Permintaan', 2);
        $client = $this->sebagaiPengguna($this->tenantId, ['management-aset.permintaan-pembelian-aset.create'])->withHeader('Idempotency-Key', 'request-'.Str::ulid());
        $missing = $payload;
        unset($missing['details'][0]['planning_detail_id']);
        $client->postJson(rtrim(self::API, '/'), $missing)->assertUnprocessable()->assertJsonValidationErrors('details.0.planning_detail_id');
        $wrong = $payload;
        $wrong['details'][0]['satuan_id'] = (string) Str::ulid();
        $client->postJson(rtrim(self::API, '/'), $wrong)->assertUnprocessable()->assertJsonValidationErrors('details.0.planning_detail_id');
        $wrong['details'][0]['satuan_id'] = $payload['details'][0]['satuan_id'];
        $wrong['details'][0]['jenis_aset_id'] = $this->jenis();
        $client->postJson(rtrim(self::API, '/'), $wrong)->assertUnprocessable()->assertJsonValidationErrors('details.0.planning_detail_id');
        DB::table('aset_tr_perencanaan_aset')->where('legal_entity_id', $this->legalEntityId)->update(['legal_entity_id' => (string) Str::ulid()]);
        $client->postJson(rtrim(self::API, '/'), $payload)->assertUnprocessable()->assertJsonValidationErrors('details.0.planning_detail_id');
        $this->assertDatabaseCount('aset_tr_permintaan_pengadaan_aset', 0);
    }

    public function test_request_document_preserves_planning_reference_in_print_dataset(): void
    {
        $record = $this->sebagaiPengguna($this->tenantId, ['management-aset.permintaan-pembelian-aset.create'])
            ->withHeader('Idempotency-Key', 'request-'.Str::ulid())->postJson(rtrim(self::API, '/'), $this->payload('Untuk kantor', 2))->assertCreated()->json('data');
        $this->sebagaiPengguna($this->tenantId, ['management-aset.permintaan-pembelian-aset.read'])->getJson(self::API.$record['id'])
            ->assertOk()->assertJsonPath('data.details.0.remaining_quantity', 2)->assertJsonPath('data.details.0.planning_code', 'PLAN-TEST');
        $context = new ReportContext($this->tenantId, $this->legalEntityId, $this->orgUnitId, (string) Str::ulid(), ['management-aset.permintaan-pembelian-aset.read'], ['management-aset.asset-responsibility' => ['all' => true, 'scope_grants' => []]], 'Asia/Makassar');
        $data = app(TenantRunner::class)->runFor($this->tenantId, fn () => (new PurchaseRequestDocument)->data($context, ['id' => $record['id']]));
        $this->assertSame('PLAN-TEST', $data->tables['lines'][0]['planning_code']);
        $this->assertSame($record['kode'], $data->fields['code']);
        $planId = DB::table('aset_tr_perencanaan_aset_details')->where('id', $this->planningLine()['id'])->value('planning_id');
        $this->sebagaiPengguna($this->tenantId, ['management-aset.perencanaan-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/perencanaan-aset/'.$planId, ['version' => 1])->assertUnprocessable();
        $this->assertDatabaseHas('aset_tr_perencanaan_aset', ['id' => $planId, 'deleted_at' => null]);
    }

    /** @return array{id: string, unit: string} */
    private function planningLine(): array
    {
        if (isset($this->planningLines[$this->legalEntityId])) {
            return $this->planningLines[$this->legalEntityId];
        }
        $planId = (string) Str::ulid();
        $lineId = (string) Str::ulid();
        $unitId = (string) Str::ulid();
        DB::table('aset_tr_perencanaan_aset')->insert([
            'id' => $planId, 'tenant_id' => $this->tenantId, 'creation_key' => 'plan-'.Str::ulid(), 'kode' => 'PLAN-TEST',
            'legal_entity_id' => $this->legalEntityId, 'planning_org_unit_id' => $this->orgUnitId,
            'planned_on' => '2026-06-01', 'planning_year' => 2026, 'planning_type' => 'regular', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aset_tr_perencanaan_aset_details')->insert([
            'id' => $lineId, 'tenant_id' => $this->tenantId, 'planning_id' => $planId, 'line_number' => 1,
            'jenis_aset_id' => $this->jenisId, 'satuan_id' => $unitId, 'unit' => 'Unit', 'nama_aset' => 'Kursi tunggu',
            'quantity' => 10, 'requested_specification' => 'Kursi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->planningLines[$this->legalEntityId] = ['id' => $lineId, 'unit' => $unitId];
    }

    /** Permintaan draf versi 1 dengan satu baris, disusun langsung di tabel. */
    private function permintaan(): string
    {
        $planLine = $this->planningLine();
        $now = now();
        $id = (string) Str::ulid();
        DB::table('aset_tr_permintaan_pengadaan_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'pp-'.Str::ulid(),
            'kode' => 'PP'.Str::random(8), 'legal_entity_id' => $this->legalEntityId,
            'requesting_org_unit_id' => $this->orgUnitId, 'requester_user_id' => (string) Str::ulid(),
            'requested_on' => '2026-06-01', 'status' => 'draft', 'description' => 'Permintaan awal',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_tr_permintaan_pengadaan_aset_details')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'request_id' => $id,
            'line_number' => 1, 'planning_detail_id' => $planLine['id'], 'jenis_aset_id' => $this->jenisId, 'satuan_id' => $planLine['unit'],
            'nama_aset' => 'Kursi tunggu', 'quantity' => 1, 'specification' => 'Permintaan awal',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    private function jenis(): string
    {
        $id = (string) Str::ulid();
        $now = now();
        DB::table('aset_m_jenis_aset')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.Str::ulid(), 'kode' => 'J'.Str::random(6), 'nama' => 'Kursi tunggu', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }
}
