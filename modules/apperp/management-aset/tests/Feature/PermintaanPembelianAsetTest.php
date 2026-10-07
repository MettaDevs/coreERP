<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        return [
            'legal_entity_id' => $this->legalEntityId, 'requesting_org_unit_id' => $this->orgUnitId,
            'requested_on' => '2026-06-01', 'description' => $keterangan,
            'details' => [[
                'jenis_aset_id' => $this->jenisId, 'satuan_id' => (string) Str::ulid(),
                'quantity' => $jumlah, 'specification' => $keterangan,
            ]],
        ];
    }

    /** Permintaan draf versi 1 dengan satu baris, disusun langsung di tabel. */
    private function permintaan(): string
    {
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
            'line_number' => 1, 'jenis_aset_id' => $this->jenisId, 'satuan_id' => (string) Str::ulid(),
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
