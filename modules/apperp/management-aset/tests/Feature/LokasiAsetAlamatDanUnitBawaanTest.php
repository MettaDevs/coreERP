<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\AddressBook\Support\OrganizationAddressBook;
use App\Platform\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerimaAset;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Lokasi aset sebagai functional location D365: alamat dari buku alamat Core yang diwarisi lokasi anak,
 * dan unit kerja bawaan yang mengisi unit penanggung jawab saat aset diterima atau dimutasi.
 */
class LokasiAsetAlamatDanUnitBawaanTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    private const BASE = '/api/modules/management-aset/v1/';

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        Http::fake(fn () => Http::response(['data' => ['number' => 'LOCA-'.Str::random(6)]], 200));
    }

    public function test_child_without_address_inherits_the_nearest_parent_address(): void
    {
        $alamat = $this->alamatUnit('Gedung pusat', 'Jl. Merdeka 1');
        $gedung = $this->create(['nama' => 'Gedung A', 'alamat_id' => $alamat['id']])
            ->assertCreated()
            ->assertJsonPath('data.alamat.id', $alamat['id'])
            ->assertJsonPath('data.alamat_efektif.diwarisi_dari', null)
            ->json('data.id');
        $lantai = $this->create(['nama' => 'Lantai 2', 'parent_id' => $gedung])->assertCreated()->json('data.id');

        $ruang = $this->create(['nama' => 'Ruang 201', 'parent_id' => $lantai])
            ->assertCreated()
            ->assertJsonPath('data.alamat_id', null)
            ->assertJsonPath('data.alamat', null)
            ->assertJsonPath('data.alamat_efektif.id', $alamat['id'])
            ->assertJsonPath('data.alamat_efektif.nama', 'Gedung pusat')
            ->assertJsonPath('data.alamat_efektif.diwarisi_dari.id', $gedung);
        $this->assertStringContainsString('Jl. Merdeka 1', (string) $ruang->json('data.alamat_efektif.alamat'));

        // Daftar memakai pohon yang sama, tanpa memanjat ulang per baris.
        $daftar = array_column($this->request('get', 'lokasi-aset?per_page=100')->assertOk()->json('data'), null, 'nama');
        $this->assertSame('Gedung A', $daftar['Ruang 201']['alamat_efektif']['diwarisi_dari']['nama']);
        $this->assertSame('Gedung A', $daftar['Lantai 2']['alamat_efektif']['diwarisi_dari']['nama']);
    }

    public function test_child_with_its_own_address_keeps_it(): void
    {
        $pusat = $this->alamatUnit('Gedung pusat', 'Jl. Merdeka 1');
        $cabang = $this->alamatUnit('Gudang cabang', 'Jl. Industri 9');
        $gedung = $this->create(['nama' => 'Kompleks', 'alamat_id' => $pusat['id']])->assertCreated()->json('data.id');

        $this->create(['nama' => 'Gudang luar', 'parent_id' => $gedung, 'alamat_id' => $cabang['id']])
            ->assertCreated()
            ->assertJsonPath('data.alamat_efektif.id', $cabang['id'])
            ->assertJsonPath('data.alamat_efektif.diwarisi_dari', null);
    }

    public function test_moving_to_another_parent_changes_the_inherited_address(): void
    {
        $alamatA = $this->alamatUnit('Gedung A', 'Jl. Satu');
        $alamatB = $this->alamatUnit('Gedung B', 'Jl. Dua');
        $gedungA = $this->create(['nama' => 'Gedung A', 'alamat_id' => $alamatA['id']])->assertCreated()->json('data.id');
        $gedungB = $this->create(['nama' => 'Gedung B', 'alamat_id' => $alamatB['id']])->assertCreated()->json('data.id');
        $ruang = $this->create(['nama' => 'Ruang rapat', 'parent_id' => $gedungA])
            ->assertCreated()
            ->assertJsonPath('data.alamat_efektif.id', $alamatA['id'])
            ->json('data');

        $this->request('patch', 'lokasi-aset/'.$ruang['id'], ['parent_id' => $gedungB, 'version' => $ruang['version']])
            ->assertOk()
            ->assertJsonPath('data.alamat_efektif.id', $alamatB['id'])
            ->assertJsonPath('data.alamat_efektif.diwarisi_dari.id', $gedungB);
    }

    public function test_address_and_department_from_another_tenant_are_rejected(): void
    {
        $tenantLain = $this->buatTenantUji();
        $alamatLain = $this->alamatUnit('Kantor tenant lain', 'Jl. Asing', $tenantLain);
        $unitLain = (string) Str::ulid();
        $this->pastikanOrganisasiAda($tenantLain, $unitLain, 'operating_unit');

        $this->create(['nama' => 'Ruang', 'alamat_id' => $alamatLain['id'], 'departemen_bawaan_id' => $unitLain])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['alamat_id', 'departemen_bawaan_id']);

        // Pilihan alamat juga hanya memuat buku alamat tenant aktif.
        $this->alamatUnit('Kantor sendiri', 'Jl. Sendiri');
        $nama = array_column($this->request('get', 'reference-data/alamat')->assertOk()->json('data'), 'nama');
        $this->assertContains('Kantor sendiri', $nama);
        $this->assertNotContains('Kantor tenant lain', $nama);
    }

    public function test_department_address_is_offered_as_a_suggestion_only(): void
    {
        $alamat = $this->alamatUnit('Poli anak', 'Jl. Sehat 3');

        $this->request('get', 'reference-data/alamat?unit_kerja_id='.$alamat['unit'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alamat['id']);

        // Memilih unit kerja bawaan tidak mengisi alamat lokasi dengan sendirinya.
        $this->create(['nama' => 'Ruang poli', 'departemen_bawaan_id' => $alamat['unit']])
            ->assertCreated()
            ->assertJsonPath('data.alamat_id', null)
            ->assertJsonPath('data.alamat_efektif', null)
            ->assertJsonPath('data.departemen_bawaan_efektif.id', $alamat['unit']);
    }

    public function test_receipt_and_transfer_take_the_default_department_of_the_location(): void
    {
        $unitPoli = (string) Str::ulid();
        $unitGudang = (string) Str::ulid();
        $unitLain = (string) Str::ulid();
        foreach ([$unitPoli, $unitGudang, $unitLain] as $unit) {
            $this->pastikanOrganisasiAda($this->tenantId, $unit, 'operating_unit');
        }
        $legalEntity = (string) Str::ulid();
        $lantai = $this->create(['nama' => 'Lantai poli', 'departemen_bawaan_id' => $unitPoli])->assertCreated()->json('data.id');
        $ruang = $this->create(['nama' => 'Ruang 301', 'parent_id' => $lantai])
            ->assertCreated()
            ->assertJsonPath('data.departemen_bawaan_efektif.id', $unitPoli)
            ->assertJsonPath('data.departemen_bawaan_efektif.diwarisi_dari.id', $lantai)
            ->json('data.id');
        $gudang = $this->create(['nama' => 'Gudang', 'departemen_bawaan_id' => $unitGudang])->assertCreated()->json('data.id');
        $tanpaUnit = $this->create(['nama' => 'Koridor'])->assertCreated()->json('data.id');
        $klasifikasi = $this->klasifikasi();

        // Penempatan: unit penanggung jawab tidak dikirim, jadi diisi dari lokasi (warisan lantai).
        $aset = $this->terimaAset($this->tenantId, [
            'legal_entity_id' => $legalEntity, 'nama' => 'Kursi periksa', ...$klasifikasi,
            'lokasi_aset_id' => $ruang, 'acquired_on' => '2026-08-01',
            'acquisition_value' => 1000, 'usage_org_unit_id' => null,
        ]);
        $this->assertDatabaseHas('aset_tr_aset', ['id' => $aset, 'responsible_org_unit_id' => $unitPoli]);

        // Pengguna tetap boleh memilih unit lain.
        $asetLain = $this->terimaAset($this->tenantId, [
            'legal_entity_id' => $legalEntity, 'nama' => 'Lemari', ...$klasifikasi,
            'lokasi_aset_id' => $ruang, 'acquired_on' => '2026-08-01',
            'acquisition_value' => 1000, 'usage_org_unit_id' => $unitLain,
        ]);
        $this->assertDatabaseHas('aset_tr_aset', ['id' => $asetLain, 'responsible_org_unit_id' => $unitLain]);

        // Tanpa unit dan tanpa unit bawaan: ditolak dengan pesan yang menyebut keduanya.
        $this->drafPenerimaan($this->tenantId, [
            'legal_entity_id' => $legalEntity, 'nama' => 'Meja', ...$klasifikasi,
            'lokasi_aset_id' => $tanpaUnit, 'acquired_on' => '2026-08-01',
            'acquisition_value' => 1000, 'usage_org_unit_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['responsible_org_unit_id']);

        // Mutasi ke gudang tanpa unit tujuan: unit tujuan diisi unit bawaan gudang.
        $mutasi = $this->mutasi($aset, $legalEntity, $unitPoli, $gudang, null)
            ->assertCreated()
            ->assertJsonPath('data.tujuan_org_unit_id', $unitGudang)
            ->json('data.id');
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.mutate', 'management-aset.mutasi-aset.read'])
            ->postJson(self::BASE.'mutasi-aset/'.$mutasi.'/selesaikan', ['version' => 1])
            ->assertOk();
        $this->assertDatabaseHas('aset_tr_aset', ['id' => $aset, 'responsible_org_unit_id' => $unitGudang]);

        // Unit tujuan yang dipilih pengguna menang atas unit bawaan lokasi.
        $this->mutasi($asetLain, $legalEntity, $unitLain, $gudang, $unitLain)
            ->assertCreated()
            ->assertJsonPath('data.tujuan_org_unit_id', $unitLain);
        $this->mutasi($asetLain, $legalEntity, $unitLain, $tanpaUnit, null)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tujuan_org_unit_id']);
    }

    /** @return TestResponse<Response> */
    private function mutasi(string $aset, string $legalEntity, string $unitAsal, string $lokasi, ?string $unitTujuan): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.mutasi-aset.create', 'management-aset.mutasi-aset.read'])
            ->withHeader('Idempotency-Key', 'mutasi-'.Str::ulid())
            ->postJson(self::BASE.'mutasi-aset', [
                'legal_entity_id' => $legalEntity,
                'responsible_org_unit_id' => $unitAsal,
                'tanggal' => '2026-09-01',
                'tujuan_lokasi_id' => $lokasi,
                'tujuan_org_unit_id' => $unitTujuan,
                'alasan' => 'Pindah ruang',
                'details' => [['aset_id' => $aset]],
            ]);
    }

    /**
     * Unit kerja dengan satu alamat utama di buku alamat Core, lewat jalur yang sama dengan layar
     * Alamat organisasi.
     *
     * @return array{id: string, unit: string}
     */
    private function alamatUnit(string $nama, string $jalan, ?string $tenantId = null): array
    {
        $tenantId ??= $this->tenantId;
        $unit = (string) Str::ulid();
        $this->pastikanOrganisasiAda($tenantId, $unit, 'operating_unit');
        $alamat = app(OrganizationAddressBook::class)->saveLocation(Organization::query()->findOrFail($unit), [
            'name' => $nama, 'country_region_code' => 'ID', 'street' => $jalan, 'city' => 'Bandung', 'purposes' => [],
        ]);

        return ['id' => (string) $alamat['location_id'], 'unit' => $unit];
    }

    /** @return array{group_aset_id: string, jenis_aset_id: string} */
    private function klasifikasi(): array
    {
        $now = now();
        $group = (string) Str::ulid();
        $jenis = (string) Str::ulid();
        DB::table('aset_m_group_aset')->insert(['id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'g-'.Str::ulid(), 'kode' => 'G'.Str::random(6), 'nama' => 'Group', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_m_jenis_aset')->insert(['id' => $jenis, 'tenant_id' => $this->tenantId, 'creation_key' => 'j-'.Str::ulid(), 'kode' => 'J'.Str::random(6), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return ['group_aset_id' => $group, 'jenis_aset_id' => $jenis];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function create(array $payload): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, $this->izinLokasi())
            ->withHeader('Idempotency-Key', 'lokasi-'.Str::ulid())
            ->postJson(self::BASE.'lokasi-aset', $this->denganKodeKetik('lokasi-aset', $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function request(string $method, string $uri, array $payload = []): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, $this->izinLokasi())->json(strtoupper($method), self::BASE.$uri, $payload);
    }

    /** @return list<string> */
    private function izinLokasi(): array
    {
        return ['management-aset.lokasi-aset.read', 'management-aset.lokasi-aset.create', 'management-aset.lokasi-aset.update'];
    }
}
