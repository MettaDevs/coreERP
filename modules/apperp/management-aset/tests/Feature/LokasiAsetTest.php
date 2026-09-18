<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerimaAset;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class LokasiAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    private string $tenantId;

    private int $issued = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        Http::fake(fn () => Http::response(['data' => ['number' => 'LOCA-'.str_pad((string) ++$this->issued, 6, '0', STR_PAD_LEFT)]], 200));
    }

    public function test_location_keeps_hierarchy_and_rejects_cycles_or_archiving_a_parent(): void
    {
        $root = $this->create('lokasi-aset', ['nama' => 'Kantor pusat'])->assertCreated()->json('data');
        $child = $this->create('lokasi-aset', ['nama' => 'Lantai satu', 'parent_id' => $root['id']])
            ->assertCreated()
            ->assertJsonPath('data.parent.id', $root['id'])
            ->json('data');

        $this->request('lokasi-aset', 'patch', '/api/modules/management-aset/v1/lokasi-aset/'.$root['id'], ['parent_id' => $child['id']])->assertStatus(422);
        $this->request('lokasi-aset', 'delete', '/api/modules/management-aset/v1/lokasi-aset/'.$root['id'])->assertConflict();
    }

    public function test_tipe_lokasi_adalah_induk_kedua_yang_lepas_dari_induk_lokasi(): void
    {
        $tipe = $this->create('tipe-lokasi-aset', ['nama' => 'Ruangan'])->assertCreated()->json('data.id');
        $root = $this->create('lokasi-aset', ['nama' => 'Kantor pusat'])->assertCreated()->json('data.id');

        // Induk lokasi dan tipe lokasi diisi bersamaan tanpa saling menyaring.
        $lokasi = $this->create('lokasi-aset', ['nama' => 'Ruang server', 'parent_id' => $root, 'tipe_lokasi_id' => $tipe])
            ->assertCreated()
            ->assertJsonPath('data.parent.id', $root)
            ->assertJsonPath('data.tipe_lokasi.id', $tipe)
            ->json('data.id');

        // Tipe yang masih dipakai tidak boleh diarsipkan.
        $this->request('tipe-lokasi-aset', 'delete', '/api/modules/management-aset/v1/tipe-lokasi-aset/'.$tipe)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'referenced_by_children');

        // Menghapus tipe dari lokasi tidak menggeser induk lokasinya.
        $this->request('lokasi-aset', 'patch', '/api/modules/management-aset/v1/lokasi-aset/'.$lokasi, ['tipe_lokasi_id' => null])
            ->assertOk()
            ->assertJsonPath('data.tipe_lokasi', null)
            ->assertJsonPath('data.parent.id', $root);

        $this->request('tipe-lokasi-aset', 'delete', '/api/modules/management-aset/v1/tipe-lokasi-aset/'.$tipe)->assertNoContent();
    }

    public function test_daftar_lokasi_dapat_disaring_menurut_tipe(): void
    {
        $ruangan = $this->create('tipe-lokasi-aset', ['nama' => 'Ruangan'])->assertCreated()->json('data.id');
        $gedung = $this->create('tipe-lokasi-aset', ['nama' => 'Gedung'])->assertCreated()->json('data.id');
        $this->create('lokasi-aset', ['nama' => 'Ruang server', 'tipe_lokasi_id' => $ruangan])->assertCreated();
        $this->create('lokasi-aset', ['nama' => 'Gedung A', 'tipe_lokasi_id' => $gedung])->assertCreated();

        $this->request('lokasi-aset', 'get', '/api/modules/management-aset/v1/lokasi-aset?tipe_lokasi_id='.$ruangan)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nama', 'Ruang server');
    }

    /**
     * Padanan toggle "Update asset dimension" di F&O: lokasi memetakan unit organisasi,
     * dan aset yang ditempatkan di situ mewarisinya sebagai dimensi keuangan.
     */
    public function test_aset_mewarisi_dimensi_keuangan_dari_lokasi_saat_diterima_dan_dimutasi(): void
    {
        $unitGudang = (string) Str::ulid();
        $unitProduksi = (string) Str::ulid();
        $unitPengguna = (string) Str::ulid();
        $legalEntity = (string) Str::ulid();

        $gudang = $this->create('lokasi-aset', ['nama' => 'Gudang', 'org_unit_id' => $unitGudang])
            ->assertCreated()
            ->assertJsonPath('data.org_unit_id', $unitGudang)
            ->json('data.id');
        $produksi = $this->create('lokasi-aset', ['nama' => 'Lantai produksi', 'org_unit_id' => $unitProduksi])->assertCreated()->json('data.id');
        $tanpaUnit = $this->create('lokasi-aset', ['nama' => 'Koridor'])->assertCreated()->json('data.id');

        $classification = $this->classification();
        $this->configureReadyBook($classification['group_aset_id']);
        $aset = $this->terimaAset($this->tenantId, [
            'legal_entity_id' => $legalEntity, 'nama' => 'Aset lokasi uji', ...$classification,
            'lokasi_aset_id' => $gudang, 'acquired_on' => '2026-08-01',
            'acquisition_value' => 1000, 'currency_code' => 'IDR',
            'usage_org_unit_id' => $unitPengguna,
        ]);
        // Dimensi diambil dari lokasi, bukan dari unit pengguna.
        $this->assertDatabaseHas('aset_tr_aset', [
            'id' => $aset, 'financial_dimension_org_unit_id' => $unitGudang,
        ]);

        // Pindah ke lokasi yang dipetakan ke unit lain: pembebanannya ikut pindah.
        $this->mutasikan($aset, $legalEntity, $unitPengguna, $produksi, '2026-09-01', 'Mulai dipakai produksi');
        $this->assertDatabaseHas('aset_tr_aset', [
            'id' => $aset, 'financial_dimension_org_unit_id' => $unitProduksi,
        ]);

        // Lokasi tanpa pemetaan: aset jatuh kembali ke unit tujuan pada dokumennya.
        $this->mutasikan($aset, $legalEntity, $unitPengguna, $tanpaUnit, '2026-10-01', 'Dipindah ke koridor');
        $this->assertDatabaseHas('aset_tr_aset', [
            'id' => $aset, 'financial_dimension_org_unit_id' => $unitPengguna,
        ]);
    }

    /**
     * Satu dokumen mutasi, dibuat lalu langsung diselesaikan.
     *
     * Dulu pemindahan di test ini satu permintaan ke `POST /aset/{id}/penempatan`. Endpoint
     * itu dipensiunkan 17 September 2026; yang diuji tetap sama — pembebanan mengikuti unit
     * yang dipetakan pada lokasi tujuan — hanya pintunya yang berubah.
     */
    private function mutasikan(
        string $asetId,
        string $legalEntity,
        string $unitTujuan,
        string $lokasiTujuan,
        string $tanggal,
        string $alasan,
    ): void {
        $mutasi = (string) $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.create'])
            ->withHeader('Idempotency-Key', 'mutasi-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/mutasi-aset', [
                'legal_entity_id' => $legalEntity,
                'responsible_org_unit_id' => $unitTujuan,
                'tanggal' => $tanggal,
                'tujuan_lokasi_id' => $lokasiTujuan,
                'tujuan_org_unit_id' => $unitTujuan,
                'alasan' => $alasan,
                'details' => [['aset_id' => $asetId]],
            ])->assertCreated()->json('data.id');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.mutate', 'management-aset.mutasi-aset.read'])
            ->postJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutasi.'/selesaikan', ['version' => 1])
            ->assertOk();
    }

    /** @return array{group_aset_id: string, jenis_aset_id: string} */
    private function classification(): array
    {
        $now = now();
        $group = (string) Str::ulid();
        $jenis = (string) Str::ulid();
        DB::table('aset_m_group_aset')->insert(['id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'g-'.Str::ulid(), 'kode' => 'G'.Str::random(6), 'nama' => 'Group', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_m_jenis_aset')->insert(['id' => $jenis, 'tenant_id' => $this->tenantId, 'creation_key' => 'j-'.Str::ulid(), 'kode' => 'J'.Str::random(6), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return ['group_aset_id' => $group, 'jenis_aset_id' => $jenis];
    }

    private function configureReadyBook(string $groupId): void
    {
        $now = now();
        $profile = (string) Str::ulid();
        $book = (string) Str::ulid();
        DB::table('aset_m_profil_penyusutan')->insert([
            'id' => $profile, 'tenant_id' => $this->tenantId, 'creation_key' => 'profile-ready-'.Str::ulid(),
            'kode' => 'P'.Str::random(8), 'nama' => 'Profil siap', 'aktif' => true,
            'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar',
            'useful_life_periods' => 12, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $book, 'tenant_id' => $this->tenantId, 'creation_key' => 'book-ready-'.Str::ulid(),
            'kode' => 'B'.Str::random(8), 'nama' => 'Buku siap', 'aktif' => true,
            'posting_layer' => 'current', 'export_to_backoffice' => false, 'depreciation_profile_id' => $profile,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_group_buku_penyusutan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'group_aset_id' => $groupId,
            'buku_id' => $book, 'depreciate' => true, 'useful_life_periods' => 12,
            'convention' => 'full_month', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function create(string $resource, array $payload): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, $this->permissionsFor($resource))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$resource, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function request(string $resource, string $method, string $uri, array $payload = []): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, $this->permissionsFor($resource))
            ->json(strtoupper($method), $uri, $payload);
    }

    /** @return list<string> */
    private function permissionsFor(string $resource): array
    {
        return array_map(
            fn (string $action): string => 'management-aset.'.$resource.'.'.$action,
            ['read', 'create', 'update', 'archive'],
        );
    }
}
