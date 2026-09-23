<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\FinancePosting;
use App\Models\FinancePostingSetting;
use App\Models\FinanceReferenceAccount;
use App\Models\Organization;
use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Finance\PostingPublisher;
use App\Support\Finance\StatusPostingBerubah;
use App\Support\Modules\Contracts\PenerbitPosting;
use App\Support\Modules\Contracts\PostingTidakSah;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Layar pantau posting finance (TODO area 7): daftar dan saringannya, detail, validasi ulang, dan
 * tandai manual beserta jejak pelakunya (7.5).
 */
class FinancePostingMonitorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    private Organization $le;

    private Organization $poli;

    /** @var array<string, string> */
    private array $akun = [];

    private string $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);
        Http::preventStrayRequests();

        $this->owner = $this->pemilik('owner@metta.test', 'PT Metta');
        $this->membership = $this->owner->activeMembership();
        $this->le = $this->legalEntity('PT Metta Sehat', 'META');
        $klinik = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $this->poli = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $this->hierarkiTerbit('Struktur manajemen', $this->le, [[$klinik, $this->le], [$this->poli, $klinik]]);

        foreach ([
            'aset' => ['1452', '1-2300', 'Aset Tetap - Kendaraan', 'balance_sheet'],
            'hutang' => ['2110', '2-1100', 'Hutang Usaha', 'balance_sheet'],
            'akumulasi' => ['1453', '1-2390', 'Akumulasi Penyusutan - Kendaraan', 'balance_sheet'],
            'beban' => ['6510', '6-5100', 'Beban Penyusutan Kendaraan', 'profit_loss'],
        ] as $kunci => [$eksternal, $kode, $nama, $jenis]) {
            $this->akun[$kunci] = FinanceReferenceAccount::query()->create([
                'tenant_id' => $this->membership->tenant_id, 'legal_entity_id' => null, 'external_id' => $eksternal,
                'code' => $kode, 'name' => $nama, 'type' => $jenis, 'active' => true,
            ])->id;
        }

        FinancePostingSetting::query()->create([
            'legal_entity_id' => $this->le->id, 'tenant_id' => $this->membership->tenant_id,
            'enabled' => true, 'cutover_date' => '2026-09-01',
        ]);
        $this->vendor = (string) $this->actingAs($this->owner)->postJson('/api/v1/vendors', [
            'legal_entity_id' => $this->le->id, 'party_name' => 'PT Karoseri Sehat',
        ])->assertCreated()->json('data.id');
    }

    public function test_hanya_owner_dan_admin_yang_dapat_melihat_dan_menindak(): void
    {
        $this->terbitkan($this->perolehan());
        $id = (string) FinancePosting::query()->value('id');

        $this->actingAs($this->owner)->get('/settings/finance-postings')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/finance-postings')
                ->where('canManage', true)
                ->where('postings.total', 1)
                ->where('postings.data.0.id', $id)
                ->where('postings.data.0.status', 'pending')
                ->where('postings.data.0.legal_entity.name', 'PT Metta Sehat')
                ->where('postings.data.0.legal_entity.code', 'META')
                ->where('postings.data.0.total_debit', '500000000.00')
                ->where('counts.pending', 1)
                ->where('counts.held', 0)
                ->where('postingTypes', ['asset.acquisition']));

        $anggota = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $anggota->id,
            'system_role' => 'member', 'status' => 'active',
        ]);
        // Jurnal keuangan tenant, jadi anggota biasa bahkan tidak boleh melihatnya.
        $this->actingAs($anggota)->get('/settings/finance-postings')->assertForbidden();
        $this->actingAs($anggota)->getJson("/api/v1/finance-postings/{$id}")->assertForbidden();
        $this->actingAs($anggota)->postJson("/api/v1/finance-postings/{$id}/mark-manual", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($anggota)->postJson("/api/v1/finance-postings/{$id}/revalidate")->assertForbidden();
        $this->assertSame('pending', FinancePosting::query()->value('status'));
    }

    public function test_saringan_status_jenis_entitas_tanggal_dan_pencarian(): void
    {
        $this->terbitkan($this->perolehan());
        $this->terbitkan($this->penyusutan());
        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => false]);
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-0002', 'tanggal' => '2026-09-29']));

        $jumlah = function (array $saringan): int {
            $total = null;
            $this->actingAs($this->owner)->get('/settings/finance-postings?'.http_build_query($saringan))->assertOk()
                ->assertInertia(function (AssertableInertia $page) use (&$total): void {
                    $total = $page->toArray()['props']['postings']['total'];
                });

            return (int) $total;
        };

        $this->assertSame(3, $jumlah([]));
        $this->assertSame(1, $jumlah(['status' => 'held']));
        $this->assertSame(1, $jumlah(['posting_type' => 'asset.depreciation']));
        $this->assertSame(1, $jumlah(['from' => '2026-09-29', 'to' => '2026-09-29']));
        $this->assertSame(2, $jumlah(['q' => 'pna-2026']));
        $this->assertSame(1, $jumlah(['q' => 'AST-DEP']));
        $this->assertSame(3, $jumlah(['legal_entity_id' => $this->le->id]));
        $this->assertSame(0, $jumlah(['legal_entity_id' => (string) Str::ulid()]));

        $this->actingAs($this->owner)->get('/settings/finance-postings?status=entah')->assertSessionHasErrors('status');
        $this->actingAs($this->owner)->get('/settings/finance-postings?from=2026-09-30&to=2026-09-01')->assertSessionHasErrors('to');
    }

    public function test_detail_memuat_baris_jurnal_masalah_per_baris_dan_riwayat_dengan_nama_pelaku(): void
    {
        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => false]);
        $this->terbitkan($this->perolehan());
        $id = (string) FinancePosting::query()->value('id');

        $detail = $this->actingAs($this->owner)->getJson("/api/v1/finance-postings/{$id}")->assertOk();
        $detail->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.source_document.number', 'PNA-2026-09-0007')
            ->assertJsonPath('data.source_document.description', 'Penerimaan ambulans')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.account_code', '1-2300')
            ->assertJsonPath('data.lines.0.account_name', 'Aset Tetap - Kendaraan')
            ->assertJsonPath('data.lines.0.debit', '500000000.00')
            ->assertJsonPath('data.lines.0.dimensions.0.code', 'BUSINESS_UNIT')
            ->assertJsonPath('data.lines.0.dimensions.0.value_code', 'KLN-A')
            ->assertJsonPath('data.problems.0.line_no', 2)
            ->assertJsonPath('data.problems.0.code', 'ACCOUNT_INACTIVE')
            ->assertJsonPath('data.problems.0.fix.url', '/settings/finance-accounts?q=2-1100')
            ->assertJsonPath('data.events.0.event', 'published')
            ->assertJsonPath('data.events.0.actor', null);

        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => true]);
        $this->postJson("/api/v1/finance-postings/{$id}/revalidate")->assertOk();

        $this->getJson("/api/v1/finance-postings/{$id}")->assertOk()
            ->assertJsonPath('data.problems', [])
            ->assertJsonPath('data.events.1.event', 'revalidated')
            ->assertJsonPath('data.events.1.actor', 'Owner PT Metta');
    }

    public function test_tautan_dokumen_sumber_hanya_jalur_relatif_dan_tidak_ikut_isi_untuk_pembaca(): void
    {
        $this->terbitkan($this->perolehan(['url' => '/app-uji/penerimaan/01J9Z3', 'module' => 'app-uji']));
        $posting = FinancePosting::query()->firstOrFail();

        $this->actingAs($this->owner)->getJson("/api/v1/finance-postings/{$posting->id}")->assertOk()
            ->assertJsonPath('data.source_document.url', '/app-uji/penerimaan/01J9Z3')
            // Nama dari katalog app, bukan kodenya.
            ->assertJsonPath('data.source_document.module', 'app-uji')
            ->assertJsonPath('data.source_document.app_name', 'App Uji');
        $this->assertArrayNotHasKey('url', $posting->payload['source_document']);

        foreach (['https://contoh.test/x', '//contoh.test/x', 'management-aset/x', '/a\\b'] as $salah) {
            try {
                $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-'.Str::random(6), 'url' => $salah]));
                $this->fail("Tautan {$salah} seharusnya ditolak.");
            } catch (PostingTidakSah $kesalahan) {
                $this->assertStringContainsString('source_document.url', $kesalahan->getMessage());
            }
        }
    }

    public function test_validasi_ulang_hanya_untuk_posting_tertahan(): void
    {
        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => false]);
        $this->terbitkan($this->perolehan());
        $id = (string) FinancePosting::query()->value('id');

        // Masih tertahan karena akunnya belum diperbaiki: validasi ulang berhasil, statusnya tetap.
        $this->actingAs($this->owner)->postJson("/api/v1/finance-postings/{$id}/revalidate")->assertOk()
            ->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.problem_count', 1);

        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => true]);
        $this->postJson("/api/v1/finance-postings/{$id}/revalidate")->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.problem_count', 0);
        $this->assertDatabaseHas('finance_posting_events', [
            'finance_posting_id' => $id, 'event' => 'revalidated', 'from_status' => 'held', 'to_status' => 'pending', 'user_id' => $this->owner->id,
        ]);

        $this->postJson("/api/v1/finance-postings/{$id}/revalidate")->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_tandai_manual_wajib_beralasan_dan_tercatat_dengan_pelaku_dan_alasannya(): void
    {
        $this->terbitkan($this->perolehan());
        $id = (string) FinancePosting::query()->value('id');

        $this->actingAs($this->owner)->postJson("/api/v1/finance-postings/{$id}/mark-manual", ['reason' => '  '])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('pending', FinancePosting::query()->value('status'));

        $this->postJson("/api/v1/finance-postings/{$id}/mark-manual", ['reason' => 'Sudah dijurnal manual di old-finance JV-0099'])
            ->assertOk()
            ->assertJsonPath('data.status', 'manual')
            ->assertJsonPath('data.manual_reason', 'user');

        $event = DB::table('finance_posting_events')->where('finance_posting_id', $id)->where('event', 'marked_manual')->first();
        $this->assertNotNull($event);
        $this->assertSame('pending', $event->from_status);
        $this->assertSame('manual', $event->to_status);
        $this->assertSame($this->owner->id, (int) $event->user_id);
        $this->assertSame(['reason' => 'Sudah dijurnal manual di old-finance JV-0099'], json_decode((string) $event->data, true));

        $this->postJson("/api/v1/finance-postings/{$id}/mark-manual", ['reason' => 'lagi'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        // Penilaian ulang cutover hanya menyentuh manual karena cutover atau posting dimatikan;
        // keputusan pengguna tidak boleh dibatalkannya diam-diam.
        app(PostingPublisher::class)->reevaluateCutover($this->membership->tenant_id, $this->le->id, $this->owner->id);
        $this->assertSame('user', FinancePosting::query()->value('manual_reason'));
    }

    public function test_posting_yang_sudah_dibukukan_tidak_dapat_ditandai_manual(): void
    {
        $this->terbitkan($this->perolehan());
        $posting = FinancePosting::query()->firstOrFail();
        $token = (string) $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', [
            'name' => 'Old-finance', 'delivery_mode' => 'pull', 'push_url' => null,
            'scopes' => ['finance-postings.read', 'finance-postings.ack'], 'posting_type_prefixes' => ['asset.'], 'allowed_ips' => [],
        ])->assertCreated()->json('token');
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson("/api/internal/v1/finance-postings/{$posting->posting_id}/ack", ['status' => 'posted', 'external_reference' => 'JV-2026-0001'])
            ->assertOk();

        $this->flushHeaders();
        $this->actingAs($this->owner)->postJson("/api/v1/finance-postings/{$posting->id}/mark-manual", ['reason' => 'Dobel'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('posted', $posting->fresh()?->status);
        $this->assertDatabaseMissing('finance_posting_events', ['finance_posting_id' => $posting->id, 'event' => 'marked_manual']);
    }

    public function test_status_diperiksa_ulang_di_dalam_kunci_baris(): void
    {
        $this->terbitkan($this->perolehan());
        $diLayar = FinancePosting::query()->firstOrFail();
        // Ack pembaca tiba setelah layar dibuka: salinan di memori masih `pending`.
        FinancePosting::query()->whereKey($diLayar->id)->update([
            'status' => 'posted', 'external_reference' => 'JV-2026-0002', 'acknowledged_at' => now(),
        ]);

        $this->expectException(StatusPostingBerubah::class);
        app(PostingPublisher::class)->markManual($diLayar, 'Terlambat', $this->owner->id);
    }

    public function test_posting_tenant_lain_menjawab_404(): void
    {
        $this->terbitkan($this->perolehan());
        $id = (string) FinancePosting::query()->value('id');
        $lain = $this->pemilik('owner@lain.test', 'PT Lain');

        $this->actingAs($lain)->get('/settings/finance-postings')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('postings.total', 0)->where('counts.pending', 0));
        $this->actingAs($lain)->getJson("/api/v1/finance-postings/{$id}")->assertNotFound();
        $this->actingAs($lain)->postJson("/api/v1/finance-postings/{$id}/mark-manual", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($lain)->postJson("/api/v1/finance-postings/{$id}/revalidate")->assertNotFound();
        $this->assertSame('pending', FinancePosting::query()->value('status'));
    }

    /**
     * @param  array{posting_id?: string, tanggal?: string, url?: string, module?: string}  $ubah
     * @return array<string, mixed>
     */
    private function perolehan(array $ubah = []): array
    {
        $tanggal = $ubah['tanggal'] ?? '2026-09-28';

        return [
            'tenant_id' => $this->membership->tenant_id,
            'posting_id' => $ubah['posting_id'] ?? 'AST-ACQ-0001',
            'posting_type' => 'asset.acquisition',
            'legal_entity_id' => $this->le->id,
            'currency_code' => 'IDR',
            'posting_date' => $tanggal,
            'document_date' => $tanggal,
            'occurred_at' => $tanggal.'T23:50:00+07:00',
            'settlement_mode' => 'direct_payable',
            'requires_vendor' => true,
            'vendor_id' => $this->vendor,
            'source_document' => array_filter([
                'module' => $ubah['module'] ?? 'management-aset', 'type' => 'penerimaan-aset', 'number' => 'PNA-2026-09-0007',
                'description' => 'Penerimaan ambulans', 'url' => $ubah['url'] ?? null,
            ], static fn (?string $nilai): bool => $nilai !== null),
            'lines' => [
                ['account_id' => $this->akun['aset'], 'debit' => '500000000.00', 'credit' => '0', 'description' => 'KEND-0012 Ambulans', 'org_unit_id' => $this->poli->id],
                ['account_id' => $this->akun['hutang'], 'debit' => '0', 'credit' => '500000000.00', 'description' => 'PT Karoseri Sehat', 'org_unit_id' => $this->poli->id],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function penyusutan(): array
    {
        return [
            'tenant_id' => $this->membership->tenant_id,
            'posting_id' => 'AST-DEP-2026-09',
            'posting_type' => 'asset.depreciation',
            'legal_entity_id' => $this->le->id,
            'currency_code' => 'IDR',
            'posting_date' => '2026-09-30',
            'document_date' => '2026-09-30',
            'occurred_at' => '2026-10-01T08:00:00+07:00',
            'source_document' => ['module' => 'management-aset', 'type' => 'post-penyusutan', 'number' => 'DEP-2026-09'],
            'lines' => [
                ['account_id' => $this->akun['beban'], 'debit' => '1250000.00', 'credit' => '0', 'org_unit_id' => $this->poli->id],
                ['account_id' => $this->akun['akumulasi'], 'debit' => '0', 'credit' => '1250000.00', 'org_unit_id' => $this->poli->id],
            ],
        ];
    }

    /** @param  array<string, mixed>  $masukan */
    private function terbitkan(array $masukan): void
    {
        DB::transaction(fn (): array => app(PenerbitPosting::class)->terbitkan($masukan));
    }

    private function pemilik(string $email, string $bisnis): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$bisnis, 'business_name' => $bisnis,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);
    }

    private function legalEntity(string $nama, string $kode): Organization
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => $nama, 'company_code' => $kode, 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();

        return Organization::query()->where('name', $nama)->firstOrFail();
    }

    private function operatingUnit(string $nama, string $tipe, string $nomor): Organization
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'operating_unit', 'name' => $nama,
            'operating_unit_type' => $tipe, 'operating_unit_number' => $nomor,
        ])->assertSessionHasNoErrors();

        return Organization::query()->where('name', $nama)->latest('created_at')->firstOrFail();
    }

    /** @param  list<array{0: Organization, 1: Organization}>  $penempatan */
    private function hierarkiTerbit(string $nama, Organization $akar, array $penempatan): void
    {
        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => $nama, 'purpose_codes' => ['management'],
            'root_organization_id' => $akar->id, 'effective_from' => '2026-01-01',
        ])->assertSessionHasNoErrors();
        $versi = OrganizationHierarchyVersion::query()
            ->whereHas('hierarchy', fn ($query) => $query->where('name', $nama))
            ->firstOrFail();
        foreach ($penempatan as [$anak, $induk]) {
            $this->post("/settings/organization/hierarchy-versions/{$versi->id}/placements", [
                'organization_id' => $anak->id, 'parent_organization_id' => $induk->id,
            ])->assertSessionHasNoErrors();
        }
        $this->post("/settings/organization/hierarchy-versions/{$versi->id}/publish")->assertSessionHasNoErrors();
    }
}
