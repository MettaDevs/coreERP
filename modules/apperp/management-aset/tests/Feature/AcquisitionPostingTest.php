<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Models\FinancePosting;
use App\Models\FinanceReferenceAccount;
use App\Models\Organization;
use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Modules\Contracts\PenerbitPosting;
use App\Support\Modules\Contracts\PostingTidakSah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Jurnal perolehan dari penerimaan aset (feed posting finance, TODO 9.5): `asset.acquisition`
 * terbit di transaksi yang sama dengan asetnya, dengan vendor, PPN, dan dimensi, untuk kedua mode
 * penyelesaian — serta keputusan area 9: lawan hibah, dan penolakan group tanpa buku yang di-post
 * seperti D365.
 *
 * Organisasi, hierarki manajemen, setelan feed, vendor, dan daftar akun disusun lewat Core yang
 * sungguhan, supaya status `pending` yang diperiksa di sini memang berarti posting itu siap ditarik.
 */
class AcquisitionPostingTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private const API = '/api/modules/management-aset/v1/';

    private string $tenantId;

    private User $owner;

    private string $le;

    private string $klinik;

    private string $poli;

    private string $vendor;

    /** @var array<string, string> */
    private array $akun = [];

    protected function setUp(): void
    {
        parent::setUp();
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
            'ppn' => ['1150', '1-1500', 'PPN Masukan'],
            'hutang' => ['2110', '2-1100', 'Hutang Usaha'],
            'perantara' => ['2190', '2-1900', 'Aset Diterima Belum Difakturkan'],
            'hibah' => ['3510', '3-5100', 'Ekuitas - Hibah Aset'],
        ] as $kunci => [$eksternal, $kode, $nama]) {
            $this->akun[$kunci] = FinanceReferenceAccount::query()->create([
                'tenant_id' => $this->tenantId, 'legal_entity_id' => null, 'external_id' => $eksternal,
                'code' => $kode, 'name' => $nama, 'type' => 'balance_sheet', 'active' => true,
            ])->id;
        }
    }

    public function test_a_purchase_publishes_one_balanced_posting_with_vendor_tax_and_dimensions(): void
    {
        $kendaraan = $this->group('KENDARAAN', 'Kendaraan');
        $alkes = $this->group('ALKES', 'Alat kesehatan');
        $this->petakan($kendaraan, ['acquisition_account_id' => $this->akun['kendaraan'], 'input_vat_account_id' => $this->akun['ppn'], 'payable_account_id' => $this->akun['hutang']]);
        $this->petakan($alkes, ['acquisition_account_id' => $this->akun['alkes'], 'input_vat_account_id' => $this->akun['ppn'], 'payable_account_id' => $this->akun['hutang']]);

        $id = $this->draf(['vendor_invoice_reference' => 'INV-77', 'vendor_invoice_date' => '2026-09-25'], [
            $this->baris($kendaraan, 2, 250_000_000, 27_500_000, 'Ambulans'),
            $this->baris($alkes, 1, 100_000_000, 0, 'Monitor pasien'),
        ]);
        $this->selesaikan($id)->assertOk()->assertJsonPath('data.status', 'selesai');

        $posting = $this->posting($id);
        $payload = $posting->payload;
        $kode = (string) DB::table('aset_tr_penerimaan_aset')->where('id', $id)->value('kode');
        $this->assertSame('pending', $posting->status);
        $this->assertSame('asset.acquisition', $payload['posting_type']);
        $this->assertSame('direct_payable', $payload['settlement_mode']);
        // Tanggal akuntansi dari penerimaan, tanggal dokumen dari faktur vendor (TODO 9.4.3).
        $this->assertSame('2026-09-28', $payload['posting_date']);
        $this->assertSame('2026-09-25', $payload['document_date']);
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $payload['occurred_at']);
        $this->assertSame('PT Karoseri Sehat', $payload['vendor']['name']);
        $this->assertSame('INV-77', $payload['vendor_invoice_reference']);
        // assertEquals: payload disimpan sebagai jsonb, yang tidak menjaga urutan kunci objek.
        $this->assertEquals(['module' => 'management-aset', 'type' => 'penerimaan-aset', 'number' => $kode, 'description' => 'Penerimaan aset '.$kode], $payload['source_document']);

        // Per group dan unit: harga perolehan, PPN, lalu lawan hutang sebesar keduanya.
        $this->assertSame([
            ['1-2300', '500000000.00', '0.00', $kode.' · Kendaraan'],
            ['1-2400', '100000000.00', '0.00', $kode.' · Alat kesehatan'],
            ['1-1500', '55000000.00', '0.00', 'PPN Masukan · '.$kode.' · Kendaraan'],
            ['2-1100', '0.00', '555000000.00', 'PT Karoseri Sehat · '.$kode],
            ['2-1100', '0.00', '100000000.00', 'PT Karoseri Sehat · '.$kode],
        ], array_map(static fn (array $baris): array => [$baris['account']['code'], $baris['debit'], $baris['credit'], $baris['description']], $payload['journal_lines']));
        $this->assertSame(['debit' => '655000000.00', 'credit' => '655000000.00'], $payload['totals']);
        // Akun neraca membawa business unit, diturunkan dari poli pengguna aset (K-07, K-09).
        $this->assertSame([['BUSINESS_UNIT', 'KLN-A']], array_map(
            static fn (array $dimensi): array => [$dimensi['code'], $dimensi['value_code']],
            $payload['journal_lines'][0]['financial_dimensions'],
        ));

        $aset = $payload['details']['assets'];
        $this->assertCount(3, $aset);
        $this->assertSame(['KENDARAAN', 'B-KENDARAAN', '250000000.00', '27500000.00'], [$aset[0]['asset_group'], $aset[0]['book'], $aset[0]['acquisition_value'], $aset[0]['tax_amount']]);
        $this->assertSame(
            DB::table('aset_tr_aset')->where('penerimaan_aset_id', $id)->orderBy('kode')->pluck('kode')->all(),
            array_column($aset, 'asset_code'),
        );
        $this->assertSame('/management-aset/inventarisasi-aset/penerimaan/'.$id, $posting->input['source_document']['url']);

        // Rincian dokumen menampilkan keadaan jurnalnya.
        $this->lihat($id)->assertJsonPath('data.posting.status', 'pending')->assertJsonPath('data.vendor.name', 'PT Karoseri Sehat');
    }

    public function test_clearing_mode_credits_the_clearing_account_and_needs_no_vendor(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$this->le}/finance-posting/settlement-modes", ['mode' => 'clearing', 'effective_from' => '2026-09-01'])
            ->assertCreated();
        $group = $this->group('KENDARAAN', 'Kendaraan');
        $this->petakan($group, ['acquisition_account_id' => $this->akun['kendaraan'], 'clearing_account_id' => $this->akun['perantara'], 'payable_account_id' => $this->akun['hutang']]);

        $id = $this->draf(['vendor_id' => null], [$this->baris($group, 1, 500_000_000)]);
        $this->selesaikan($id)->assertOk();

        $payload = $this->posting($id)->payload;
        $this->assertSame('clearing', $payload['settlement_mode']);
        $this->assertNull($payload['vendor']);
        $this->assertSame(['1-2300', '2-1900'], array_map(static fn (array $baris): string => $baris['account']['code'], $payload['journal_lines']));
    }

    public function test_an_unmapped_group_still_completes_and_the_posting_is_released_once_mapped(): void
    {
        $group = $this->group('RUANG', 'Perabot ruang');

        $id = $this->draf([], [$this->baris($group, 1, 5_000_000)]);
        $this->selesaikan($id)->assertOk()->assertJsonPath('data.status', 'selesai');

        $posting = $this->posting($id);
        $this->assertSame('held', $posting->status);
        $this->assertSame(1, DB::table('aset_tr_aset')->where('penerimaan_aset_id', $id)->count());
        $masalah = collect($posting->hold_reasons)->keyBy('line_no');
        $this->assertSame('ACCOUNT_NOT_MAPPED', $masalah[1]['code']);
        $this->assertSame('Group RUANG · harga perolehan belum dipetakan ke akun.', $masalah[1]['message']);
        $this->assertSame(['label' => 'Buka pemetaan akun', 'url' => '/management-aset/fixed-aset-posting-profiles'], $masalah[1]['fix']);
        $this->assertSame('Group RUANG · lawan hutang belum dipetakan ke akun.', $masalah[2]['message']);

        // Konsultan mengisi posting group, lalu Validasi ulang di layar pantau Core melepasnya.
        $this->petakan($group, ['acquisition_account_id' => $this->akun['kendaraan'], 'payable_account_id' => $this->akun['hutang']]);
        $this->actingAs($this->owner)->postJson('/api/v1/finance-postings/'.$posting->id.'/revalidate')->assertOk();

        $posting->refresh();
        $this->assertSame('pending', $posting->status);
        $this->assertSame(['1-2300', '2-1100'], array_map(static fn (array $baris): string => $baris['account']['code'], $posting->payload['journal_lines']));
    }

    public function test_a_publisher_failure_rolls_back_the_receipt_and_is_reported(): void
    {
        $group = $this->group('KENDARAAN', 'Kendaraan');
        $id = $this->draf([], [$this->baris($group, 2, 1_000_000)]);
        Exceptions::fake();
        $this->app->instance(PenerbitPosting::class, new class implements PenerbitPosting
        {
            public function terbitkan(array $posting): array
            {
                throw new PostingTidakSah('Jurnal tidak seimbang: debit 1, kredit 2.');
            }

            public function pratinjau(array $posting): array
            {
                throw new PostingTidakSah('Jurnal tidak seimbang: debit 1, kredit 2.');
            }

            public function status(string $tenantId, string $postingId): ?array
            {
                return null;
            }
        });

        $this->selesaikan($id)->assertStatus(500)->assertJsonPath('error.code', 'posting_failed');

        Exceptions::assertReported(PostingTidakSah::class);
        $this->assertSame('draft', DB::table('aset_tr_penerimaan_aset')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('aset_tr_aset')->where('penerimaan_aset_id', $id)->count());
        $this->assertSame(0, FinancePosting::query()->count());
    }

    public function test_completing_twice_keeps_one_posting(): void
    {
        $group = $this->group('KENDARAAN', 'Kendaraan');
        $id = $this->draf([], [$this->baris($group, 2, 1_000_000)]);

        $this->selesaikan($id)->assertOk();
        $this->selesaikan($id)->assertStatus(422);

        $this->assertSame(1, FinancePosting::query()->count());
        $this->assertSame(2, DB::table('aset_tr_aset')->where('penerimaan_aset_id', $id)->count());
    }

    public function test_a_fractional_unit_price_is_rounded_once_per_line_and_split_across_the_assets(): void
    {
        $group = $this->group('KENDARAAN', 'Kendaraan');
        $this->petakan($group, ['acquisition_account_id' => $this->akun['kendaraan'], 'payable_account_id' => $this->akun['hutang']]);

        $id = $this->draf([], [$this->baris($group, 3, '333333.333')]);
        $this->selesaikan($id)->assertOk();

        $payload = $this->posting($id)->payload;
        $this->assertSame(['debit' => '1000000.00', 'credit' => '1000000.00'], $payload['totals']);
        $nilai = DB::table('aset_tr_aset')->where('penerimaan_aset_id', $id)->orderBy('kode')->pluck('acquisition_value')->map(static fn ($v): string => (string) $v)->all();
        $this->assertSame(['333333.34', '333333.33', '333333.33'], $nilai);
        $this->assertSame(['333333.34', '333333.33', '333333.33'], array_column($payload['details']['assets'], 'acquisition_value'));
        $this->assertSame('333333.333000', (string) DB::table('aset_tr_penerimaan_aset_details')->where('penerimaan_aset_id', $id)->value('nilai_per_unit'));
    }

    public function test_a_grant_credits_the_grant_offset_and_is_held_while_it_is_unmapped(): void
    {
        $dipetakan = $this->group('HIBAH', 'Hibah pemda');
        $this->petakan($dipetakan, ['acquisition_account_id' => $this->akun['kendaraan'], 'grant_offset_account_id' => $this->akun['hibah']]);
        $belum = $this->group('SUMBANGAN', 'Sumbangan donatur');
        $this->petakan($belum, ['acquisition_account_id' => $this->akun['kendaraan'], 'payable_account_id' => $this->akun['hutang']]);

        $id = $this->draf(['cara_perolehan' => 'hibah', 'vendor_id' => null], [$this->baris($dipetakan, 1, 300_000_000)]);
        $this->selesaikan($id)->assertOk();
        $posting = $this->posting($id);
        $this->assertSame('pending', $posting->status);
        $this->assertSame(['1-2300', '3-5100'], array_map(static fn (array $baris): string => $baris['account']['code'], $posting->payload['journal_lines']));
        $this->assertNull($posting->payload['vendor']);

        // Lawan hibah kosong: hibah tidak jatuh ke hutang, postingnya tertahan (K-18).
        $kedua = $this->draf(['cara_perolehan' => 'hibah', 'vendor_id' => null], [$this->baris($belum, 1, 10_000_000)]);
        $this->selesaikan($kedua)->assertOk();
        $tertahan = $this->posting($kedua);
        $this->assertSame('held', $tertahan->status);
        $this->assertSame('Group SUMBANGAN · lawan hibah belum dipetakan ke akun.', $tertahan->hold_reasons[0]['message']);
    }

    public function test_a_group_without_a_posted_book_blocks_completion_like_dynamics(): void
    {
        $memorandum = $this->group('FISKAL-SAJA', 'Hanya buku fiskal', 'none');
        $tanpaBuku = $this->groupTanpaBuku('TANPA-BUKU');
        $id = $this->draf([], [$this->baris($memorandum, 1, 1_000_000), $this->baris($tanpaBuku, 1, 1_000_000)]);

        $pesan = 'Group %s belum punya buku yang di-post ke finance. Tambahkan buku yang lapisan posting-nya bukan "none" di matriks group x buku (Master data › Group aset), lalu selesaikan lagi.';
        $this->pratinjau($id)->assertOk()
            ->assertJsonPath('data.blockers.0', ['field' => 'details.0.group_aset_id', 'message' => sprintf($pesan, 'FISKAL-SAJA')])
            ->assertJsonPath('data.blockers.1', ['field' => 'details.1.group_aset_id', 'message' => sprintf($pesan, 'TANPA-BUKU')]);
        $this->selesaikan($id)->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.group_aset_id' => sprintf($pesan, 'FISKAL-SAJA'), 'details.1.group_aset_id' => sprintf($pesan, 'TANPA-BUKU')]);

        $this->assertSame(0, DB::table('aset_tr_aset')->where('penerimaan_aset_id', $id)->count());
        $this->assertSame(0, FinancePosting::query()->count());
    }

    public function test_a_purchase_needs_a_vendor_of_its_own_legal_entity_in_direct_payable_mode(): void
    {
        $group = $this->group('KENDARAAN', 'Kendaraan');
        $id = $this->draf(['vendor_id' => null], [$this->baris($group, 1, 1_000_000)]);
        $this->selesaikan($id)->assertStatus(422)->assertJsonValidationErrors(['vendor_id' => 'Pilih vendornya.']);

        $lain = $this->organisasi(['classification' => 'legal_entity', 'name' => 'PT Metta Lain', 'company_code' => 'LAIN', 'country_code' => 'ID']);
        $vendorLain = (string) $this->actingAs($this->owner)->postJson('/api/v1/vendors', ['legal_entity_id' => $lain, 'party_name' => 'CV Lain'])
            ->assertCreated()->json('data.id');
        $this->kirimDraf(['vendor_id' => $vendorLain], [$this->baris($group, 1, 1_000_000)])
            ->assertStatus(422)->assertJsonValidationErrors(['vendor_id' => 'Pilih vendor milik entitas legal penerimaan ini.']);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read'])
            ->getJson(self::API.'penerimaan-aset/vendor?legal_entity_id='.$this->le)
            ->assertOk()->assertJsonPath('data.0.name', 'PT Karoseri Sehat')->assertJsonCount(1, 'data');
    }

    public function test_the_preview_shows_the_journal_that_completion_publishes(): void
    {
        $group = $this->group('KENDARAAN', 'Kendaraan');
        $this->petakan($group, ['acquisition_account_id' => $this->akun['kendaraan'], 'input_vat_account_id' => $this->akun['ppn']]);
        $id = $this->draf([], [$this->baris($group, 2, 150_000_000, 16_500_000)]);

        $pratinjau = $this->pratinjau($id)->assertOk()
            ->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.blockers', [])
            ->assertJsonPath('data.currency', ['code' => 'IDR', 'decimals' => 2])
            ->assertJsonPath('data.problems.0.message', 'Group KENDARAAN · lawan hutang belum dipetakan ke akun.')
            ->json('data.lines');
        $this->selesaikan($id)->assertOk();

        $terbit = $this->posting($id)->payload['journal_lines'];
        $this->assertSame(
            array_map(static fn (array $baris): array => [$baris['account_code'], $baris['debit'], $baris['credit'], $baris['description']], $pratinjau),
            array_map(static fn (array $baris): array => [$baris['account']['code'] ?? null, $baris['debit'], $baris['credit'], $baris['description']], $terbit),
        );
        $this->assertSame('KLN-A', $pratinjau[0]['dimensions'][0]['value_code']);
    }

    public function test_a_zero_value_receipt_publishes_nothing_and_a_disabled_feed_keeps_the_posting_manual(): void
    {
        $group = $this->group('KENDARAAN', 'Kendaraan');
        $nol = $this->draf([], [$this->baris($group, 1, 0)]);
        $this->selesaikan($nol)->assertOk();
        $this->assertSame(0, FinancePosting::query()->count());

        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$this->le}/finance-posting", ['enabled' => false, 'cutover_date' => '2026-01-01'])->assertOk();
        $id = $this->draf([], [$this->baris($group, 1, 1_000_000)]);
        $this->selesaikan($id)->assertOk();
        $this->assertSame(['manual', 'feed_disabled'], [$this->posting($id)->status, $this->posting($id)->manual_reason]);
    }

    public function test_a_unit_price_finer_than_the_currency_unit_precision_is_refused(): void
    {
        $group = $this->group('KENDARAAN', 'Kendaraan');

        $this->kirimDraf([], [$this->baris($group, 1, '1000.1234', '10.12345')])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'details.0.nilai_per_unit' => 'Paling banyak 3 angka di belakang koma untuk IDR.',
                'details.0.ppn_per_unit' => 'Paling banyak 3 angka di belakang koma untuk IDR.',
            ]);
    }

    // ---- penyusun skenario -------------------------------------------------

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

    private function posting(string $receiptId): FinancePosting
    {
        return FinancePosting::query()->where('posting_id', 'AST-ACQ-'.$receiptId)->firstOrFail();
    }
}
