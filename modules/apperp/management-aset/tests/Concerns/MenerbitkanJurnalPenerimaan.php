<?php

namespace Modules\Apperp\ManagementAset\Tests\Concerns;

use App\Models\FinancePosting;
use App\Models\FinanceReferenceAccount;
use App\Models\Organization;
use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panggung jurnal penerimaan aset (feed posting finance, area 9 dan 10): owner, entitas legal dengan
 * klinik dan poli di hierarki manajemen, feed aktif sejak cutover 1 Januari 2026, satu vendor, dan
 * daftar akun — semuanya disusun lewat Core yang sungguhan, supaya status `pending` yang diperiksa
 * memang berarti posting itu siap ditarik.
 *
 * Kelas pemakainya wajib memakai `BerinteraksiDenganKonteksCore` dan `RefreshDatabase`, lalu memanggil
 * `siapkanJurnalPenerimaan()` dari `setUp()`.
 */
trait MenerbitkanJurnalPenerimaan
{
    private const API = '/api/modules/management-aset/v1/';

    private string $tenantId;

    private User $owner;

    private string $le;

    private string $klinik;

    private string $poli;

    private string $vendor;

    /** @var array<string, string> */
    private array $akun = [];

    protected function siapkanJurnalPenerimaan(): void
    {
        Http::preventStrayRequests();
        $this->tenantId = $this->buatTenantUji();
        $this->owner = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenantId, 'user_id' => $this->owner->id, 'system_role' => 'owner', 'status' => 'active']);

        $this->le = $this->organisasi(['classification' => 'legal_entity', 'name' => 'PT Metta Sehat', 'company_code' => 'META', 'country_code' => 'ID']);
        $this->klinik = $this->organisasi(['classification' => 'operating_unit', 'name' => 'Klinik A', 'operating_unit_type' => 'business_unit', 'operating_unit_number' => 'KLN-A']);
        $this->poli = $this->organisasi(['classification' => 'operating_unit', 'name' => 'Poli Umum', 'operating_unit_type' => 'department', 'operating_unit_number' => 'POLI-UMUM']);
        $this->hierarki([[$this->klinik, $this->le], [$this->poli, $this->klinik]]);
        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$this->le}/finance-posting", ['enabled' => true, 'cutover_date' => '2026-01-01'])->assertOk();
        $this->vendor = (string) $this->actingAs($this->owner)->postJson('/api/v1/vendors', ['legal_entity_id' => $this->le, 'party_name' => 'PT Karoseri Sehat'])
            ->assertCreated()->json('data.id');

        foreach ([
            'kendaraan' => ['1452', '1-2300', 'Aset Tetap - Kendaraan'],
            'alkes' => ['1460', '1-2400', 'Aset Tetap - Alat Kesehatan'],
            'akumulasi' => ['1459', '1-2390', 'Akumulasi Penyusutan - Kendaraan'],
            'ppn' => ['1150', '1-1500', 'PPN Masukan'],
            'hutang' => ['2110', '2-1100', 'Hutang Usaha'],
            'perantara' => ['2190', '2-1900', 'Aset Diterima Belum Difakturkan'],
            'hibah' => ['3510', '3-5100', 'Ekuitas - Hibah Aset'],
            'penyeimbang' => ['3900', '3-9000', 'Penyeimbang Saldo Awal'],
        ] as $kunci => [$eksternal, $kode, $nama]) {
            $this->akun[$kunci] = FinanceReferenceAccount::query()->create([
                'tenant_id' => $this->tenantId, 'legal_entity_id' => null, 'external_id' => $eksternal,
                'code' => $kode, 'name' => $nama, 'type' => 'balance_sheet', 'active' => true,
            ])->id;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function organisasi(array $data): string
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', $data)->assertSessionHasNoErrors();

        return (string) Organization::query()->where('tenant_id', $this->tenantId)->where('name', $data['name'])->value('id');
    }

    /** @param  list<array{0: string, 1: string}>  $penempatan */
    private function hierarki(array $penempatan): void
    {
        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => 'Struktur manajemen', 'purpose_codes' => ['management'],
            'root_organization_id' => $this->le, 'effective_from' => '2026-01-01',
        ])->assertSessionHasNoErrors();
        $versi = OrganizationHierarchyVersion::query()->whereHas('hierarchy', fn ($query) => $query->where('name', 'Struktur manajemen'))->firstOrFail();
        foreach ($penempatan as [$anak, $induk]) {
            $this->post("/settings/organization/hierarchy-versions/{$versi->id}/placements", [
                'organization_id' => $anak, 'parent_organization_id' => $induk,
            ])->assertSessionHasNoErrors();
        }
        $this->post("/settings/organization/hierarchy-versions/{$versi->id}/publish")->assertSessionHasNoErrors();
    }

    /** Group aset dengan satu buku berlapisan `$postingLayer` pada matriksnya. */
    private function group(string $kode, string $nama, string $postingLayer = 'current'): string
    {
        $group = $this->groupTanpaBuku($kode, $nama);
        $buku = (string) Str::ulid();
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $buku, 'tenant_id' => $this->tenantId, 'creation_key' => 'buku-'.Str::ulid(),
            'kode' => 'B-'.$kode, 'nama' => 'Buku '.$nama, 'aktif' => true, 'posting_layer' => $postingLayer,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aset_m_group_buku_penyusutan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'group_aset_id' => $group,
            'buku_id' => $buku, 'depreciate' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $group;
    }

    private function groupTanpaBuku(string $kode, ?string $nama = null): string
    {
        return (string) $this->sebagaiPengguna($this->tenantId, ['management-aset.group-aset.create'])
            ->withHeader('Idempotency-Key', 'group-'.Str::ulid())
            ->postJson(self::API.'group-aset', ['kode' => $kode, 'nama' => $nama ?? 'Group '.$kode])
            ->assertCreated()
            ->json('data.id');
    }

    /** @param  array<string, string>  $akun */
    private function petakan(string $group, array $akun, string $tanggal = '2026-01-01'): void
    {
        $this->sebagaiPengguna($this->tenantId, array_map(
            static fn (string $aksi): string => 'management-aset.fixed-asset-posting-profiles.'.$aksi,
            ['read', 'create', 'update'],
        ))->putJson(self::API.'posting-group-aset/'.$group.'/'.$tanggal, $akun)->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $baris
     */
    private function draf(array $header, array $baris): string
    {
        return (string) $this->kirimDraf($header, $baris)->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $baris
     * @return TestResponse<Response>
     */
    private function kirimDraf(array $header, array $baris): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.create', 'management-aset.penerimaan-aset.read'])
            ->withHeader('Idempotency-Key', 'pnr-'.Str::ulid())
            ->postJson(self::API.'penerimaan-aset', [
                'legal_entity_id' => $this->le,
                'responsible_org_unit_id' => $this->poli,
                'tanggal' => '2026-09-28',
                'tanggal_siap_pakai' => '2026-09-28',
                'currency_code' => 'IDR',
                'vendor_id' => $this->vendor,
                ...$header,
                'details' => $baris,
            ]);
    }

    /** @return array<string, mixed> */
    private function baris(string $group, int $jumlah, int|string $nilai, int|string $ppn = 0, string $nama = 'Ambulans'): array
    {
        return [
            'nama' => $nama,
            'group_aset_id' => $group,
            'jenis_aset_id' => $this->jenis(),
            'jumlah' => $jumlah,
            'nilai_per_unit' => $nilai,
            'ppn_per_unit' => $ppn,
        ];
    }

    private function jenis(): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_m_jenis_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'jenis-'.Str::ulid(),
            'kode' => 'J'.Str::random(8), 'nama' => 'Jenis uji', 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return TestResponse<Response> */
    private function selesaikan(string $id, int $version = 1): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read', 'management-aset.aset.create'])
            ->postJson(self::API.'penerimaan-aset/'.$id.'/selesaikan', ['version' => $version]);
    }

    /** @return TestResponse<Response> */
    private function pratinjau(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read'])
            ->getJson(self::API.'penerimaan-aset/'.$id.'/pratinjau-posting');
    }

    /** @return TestResponse<Response> */
    private function lihat(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read'])
            ->getJson(self::API.'penerimaan-aset/'.$id)->assertOk();
    }

    /** Posting jurnal penerimaan: `AST-ACQ-` untuk perolehan, `AST-OPB-` untuk saldo awal. */
    private function posting(string $receiptId, string $awalan = 'AST-ACQ-'): FinancePosting
    {
        return FinancePosting::query()->where('posting_id', $awalan.$receiptId)->firstOrFail();
    }
}
