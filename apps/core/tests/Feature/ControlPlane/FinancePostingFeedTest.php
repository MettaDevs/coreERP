<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\Environment;
use App\Models\FinancePosting;
use App\Models\FinancePostingDelivery;
use App\Models\FinancePostingSetting;
use App\Models\FinanceReferenceAccount;
use App\Models\Organization;
use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\Finance\PostingPublisher;
use App\Support\Finance\PostingPusher;
use App\Support\Modules\Contracts\PenerbitPosting;
use App\Support\Modules\Contracts\PostingAccountResolver;
use App\Support\Modules\Contracts\PostingAccountResolvers;
use App\Support\Modules\Contracts\PostingTidakSah;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\CocokDenganKontrak;
use Tests\TestCase;

/**
 * Feed posting finance: penerbitan, tarikan, ack, dan dorongan (area 6).
 */
class FinancePostingFeedTest extends TestCase
{
    use CocokDenganKontrak, RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    private Organization $le;

    private Organization $klinik;

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
        $this->klinik = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $this->poli = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $this->hierarkiTerbit('Struktur manajemen', $this->le, [[$this->klinik, $this->le], [$this->poli, $this->klinik]]);

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

    public function test_posting_terbit_pending_dengan_salinan_akun_dimensi_dan_vendor(): void
    {
        $hasil = $this->terbitkan($this->perolehan());

        $this->assertSame('pending', $hasil['status']);
        $this->assertTrue($hasil['created']);
        $this->assertSame([], $hasil['problems']);
        $payload = $hasil['payload'];
        $this->assertSame(1, $payload['contract_version']);
        $this->assertSame(['id' => $this->le->id, 'code' => 'META'], $payload['legal_entity']);
        $this->assertSame(['code' => 'IDR', 'decimals' => 2], $payload['currency']);
        $this->assertSame('2026-09-28T23:50:00+07:00', $payload['occurred_at']);
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $payload['published_at']);
        $this->assertSame(['id' => $this->vendor, 'number' => 'VND-000001', 'name' => 'PT Karoseri Sehat'], $payload['vendor']);
        $this->assertSame(['external_id' => '1452', 'code' => '1-2300', 'name' => 'Aset Tetap - Kendaraan'], $payload['journal_lines'][0]['account']);
        $this->assertSame('500000000.00', $payload['journal_lines'][0]['debit']);
        $this->assertSame('0.00', $payload['journal_lines'][0]['credit']);
        // Akun neraca hanya membawa business unit, diturunkan dari poli lewat hierarki (K-07, K-09).
        $this->assertSame([[
            'code' => 'BUSINESS_UNIT', 'display_name' => 'Business unit', 'value_code' => 'KLN-A',
            'value_display_name' => 'Klinik A', 'value_id' => $this->klinik->id,
        ]], $payload['journal_lines'][0]['financial_dimensions']);
        $this->assertSame(['debit' => '500000000.00', 'credit' => '500000000.00'], $payload['totals']);

        $this->assertDatabaseHas('finance_posting_lines', [
            'line_no' => 1, 'account_code' => '1-2300', 'business_unit_code' => 'KLN-A', 'department_code' => null,
        ]);
        $this->assertDatabaseHas('finance_posting_events', ['event' => 'published', 'to_status' => 'pending']);
    }

    public function test_akun_laba_rugi_membawa_business_unit_dan_department(): void
    {
        $hasil = $this->terbitkan($this->penyusutan());

        $baris = $hasil['payload']['journal_lines'];
        $this->assertSame(['BUSINESS_UNIT', 'DEPARTMENT'], array_column($baris[0]['financial_dimensions'], 'code'));
        $this->assertSame('POLI-UMUM', $baris[0]['financial_dimensions'][1]['value_code']);
        $this->assertSame(['BUSINESS_UNIT'], array_column($baris[1]['financial_dimensions'], 'code'));
        $this->assertDatabaseHas('finance_posting_lines', ['line_no' => 1, 'business_unit_code' => 'KLN-A', 'department_code' => 'POLI-UMUM']);

        // `details` kosong tetap objek di kontrak, bukan larik kosong.
        $tarikan = $this->tarik($this->token())->assertOk();
        $this->assertSame('{}', json_encode(json_decode((string) $tarikan->getContent())->data[0]->details));
        $this->assertCocokSkema($tarikan->json('data.0'), 'FinancePosting');
    }

    public function test_penerbitan_di_dalam_transaksi_yang_dibatalkan_tidak_meninggalkan_posting(): void
    {
        try {
            DB::transaction(function (): void {
                app(PenerbitPosting::class)->terbitkan($this->perolehan());
                throw new RuntimeException('Dokumen sumber gagal disimpan.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, FinancePosting::query()->count());
        $this->assertSame(0, DB::table('finance_posting_lines')->count());
    }

    public function test_posting_id_sama_mengembalikan_yang_ada_dan_isi_berbeda_ditolak(): void
    {
        $pertama = $this->terbitkan($this->perolehan());
        $kedua = $this->terbitkan($this->perolehan(['description_ignored' => true]));

        $this->assertFalse($kedua['created']);
        $this->assertSame($pertama['payload']['published_at'], $kedua['payload']['published_at']);
        $this->assertSame(1, FinancePosting::query()->count());

        $this->expectException(PostingTidakSah::class);
        $this->terbitkan($this->perolehan(['nilai' => '400000000.00']));
    }

    public function test_jurnal_tidak_seimbang_atau_baris_dua_sisi_adalah_bug_penerbit(): void
    {
        $tidakSeimbang = $this->perolehan();
        $tidakSeimbang['lines'][1]['credit'] = '499999999.99';
        $duaSisi = $this->perolehan();
        $duaSisi['lines'][0]['credit'] = '1.00';
        $duaSisi['lines'][1]['credit'] = '500000001.00';

        foreach ([$tidakSeimbang, $duaSisi] as $masukan) {
            try {
                $this->terbitkan($masukan);
                $this->fail('Posting yang tidak mungkin benar harus dilempar.');
            } catch (PostingTidakSah) {
            }
        }

        $this->assertSame(0, FinancePosting::query()->count());
    }

    public function test_nilai_lebih_halus_dari_presisi_mata_uang_ditolak_dan_nilai_bulat_dilengkapi(): void
    {
        foreach (['100.005', '1.000,50', '-5.00', 12.5] as $nilai) {
            $masukan = $this->perolehan();
            $masukan['lines'][0]['debit'] = $nilai;
            $masukan['lines'][1]['credit'] = $nilai;
            try {
                $this->terbitkan($masukan);
                $this->fail(sprintf('Nilai %s harus ditolak.', var_export($nilai, true)));
            } catch (PostingTidakSah) {
            }
        }

        $bulat = $this->perolehan(['nilai' => '500000000']);
        $this->assertSame('500000000.00', $this->terbitkan($bulat)['payload']['journal_lines'][1]['credit']);
    }

    public function test_pemetaan_kosong_akun_nonaktif_dan_unit_tanpa_nomor_menahan_posting(): void
    {
        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => false]);
        $poliTanpaNomor = $this->operatingUnit('Poli Gigi', 'department', null);
        $this->tempatkan($poliTanpaNomor, $this->klinik);
        $masukan = $this->penyusutan();
        $masukan['lines'][0]['org_unit_id'] = $poliTanpaNomor->id;
        $masukan['lines'][1]['account_id'] = null;
        $masukan['lines'][1]['mapping'] = ['label' => 'Group KENDARAAN · akun akumulasi', 'fix_url' => '/m/management-aset/posting-groups/KENDARAAN'];
        $masukan['lines'][] = ['account_id' => $this->akun['hutang'], 'debit' => '0', 'credit' => '1.00', 'org_unit_id' => $this->poli->id];
        $masukan['lines'][0]['debit'] = '1250001.00';

        $hasil = $this->terbitkan($masukan);

        $this->assertSame('held', $hasil['status']);
        $this->assertSame(
            [[1, 'DEPARTMENT_NUMBER_MISSING'], [2, 'ACCOUNT_NOT_MAPPED'], [3, 'ACCOUNT_INACTIVE']],
            array_map(static fn (array $masalah): array => [$masalah['line_no'], $masalah['code']], $hasil['problems']),
        );
        $this->assertSame('Group KENDARAAN · akun akumulasi belum dipetakan ke akun.', $hasil['problems'][1]['message']);
        $this->assertSame('/m/management-aset/posting-groups/KENDARAAN', $hasil['problems'][1]['fix']['url']);
        $this->assertSame('/settings/organization?section=operating-units', $hasil['problems'][0]['fix']['url']);
        $this->assertSame('/settings/finance-accounts?q=2-1100', $hasil['problems'][2]['fix']['url']);

        $token = $this->token();
        $this->tarik($token)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_validasi_ulang_setelah_pemetaan_diperbaiki_memindahkan_held_ke_pending(): void
    {
        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => false]);
        $held = $this->terbitkan($this->perolehan());
        $this->assertSame('held', $held['status']);

        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => true, 'name' => 'Hutang Usaha Pihak Ketiga']);
        $posting = app(PostingPublisher::class)->revalidate(FinancePosting::query()->firstOrFail(), $this->owner->id);

        $this->assertSame('pending', $posting->status);
        $this->assertSame('AST-ACQ-0001', $posting->posting_id);
        $this->assertNull($posting->hold_reasons);
        $this->assertSame('Hutang Usaha Pihak Ketiga', $posting->payload['journal_lines'][1]['account']['name']);
        $this->assertSame($held['payload']['published_at'], $posting->payload['published_at']);
        $this->assertDatabaseHas('finance_posting_events', ['event' => 'revalidated', 'from_status' => 'held', 'to_status' => 'pending', 'user_id' => $this->owner->id]);
    }

    public function test_revalidation_reads_the_account_from_the_module_mapping_in_force_now(): void
    {
        $resolver = new class implements PostingAccountResolver
        {
            /** @var array<string, string> */
            public array $mapping = [];

            public bool $broken = false;

            public function moduleId(): string
            {
                return 'management-aset';
            }

            public function account(string $tenantId, string $reference, string $postingDate): ?string
            {
                if ($this->broken) {
                    throw new RuntimeException('Pemeta akun rusak.');
                }

                return $this->mapping[$reference] ?? null;
            }
        };
        app(PostingAccountResolvers::class)->register($resolver);

        // Group belum dipetakan saat penerimaan diselesaikan: posting tertahan, dokumennya tetap sah.
        $masukan = $this->perolehan();
        $masukan['lines'][0]['account_id'] = null;
        $masukan['lines'][0]['mapping'] = ['label' => 'Group KENDARAAN · harga perolehan', 'reference' => 'posting-group:KENDARAAN:acquisition_account_id'];
        $held = $this->terbitkan($masukan);
        $this->assertSame('held', $held['status']);
        $this->assertSame('ACCOUNT_NOT_MAPPED', $held['problems'][0]['code']);

        // Pemeta yang gagal dilaporkan, dan posting dibentuk ulang dari akun yang tersimpan.
        Exceptions::fake();
        $resolver->broken = true;
        $posting = app(PostingPublisher::class)->revalidate(FinancePosting::query()->firstOrFail(), $this->owner->id);
        $this->assertSame('held', $posting->status);
        Exceptions::assertReported(RuntimeException::class);

        $resolver->broken = false;
        $resolver->mapping['posting-group:KENDARAAN:acquisition_account_id'] = $this->akun['aset'];
        $posting = app(PostingPublisher::class)->revalidate($posting->refresh(), $this->owner->id);

        $this->assertSame('pending', $posting->status);
        $this->assertSame('1-2300', $posting->payload['journal_lines'][0]['account']['code']);
        $this->assertSame($this->akun['aset'], $posting->input['lines'][0]['account_id']);
        // Nilai, unit, dan baris lain tidak disusun ulang; hanya akunnya.
        $this->assertSame($held['payload']['totals'], $posting->payload['totals']);
        $this->assertSame($held['payload']['journal_lines'][1], $posting->payload['journal_lines'][1]);

        // Module yang menerbitkan ulang dokumen yang sama dengan pemetaan terbaru mendapat posting
        // yang sudah ada, bukan penolakan "isi jurnal berbeda".
        $masukan['lines'][0]['account_id'] = $this->akun['aset'];
        $ulang = $this->terbitkan($masukan);
        $this->assertFalse($ulang['created']);
        $this->assertSame('pending', $ulang['status']);
    }

    public function test_sebelum_cutover_dan_feed_mati_menjadi_manual_lalu_dinilai_ulang_saat_setelan_berubah(): void
    {
        $sebelum = $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-LAMA', 'tanggal' => '2026-08-20']));
        $this->assertSame('manual', $sebelum['status']);
        $this->assertSame('before_cutover', FinancePosting::query()->where('posting_id', 'AST-ACQ-LAMA')->value('manual_reason'));

        FinancePostingSetting::query()->whereKey($this->le->id)->update(['enabled' => false]);
        $mati = $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-MATI']));
        $this->assertSame('manual', $mati['status']);
        $this->assertSame('feed_disabled', FinancePosting::query()->where('posting_id', 'AST-ACQ-MATI')->value('manual_reason'));

        // Feed diaktifkan lagi dengan cutover lebih awal: keduanya kini sesudah cutover.
        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$this->le->id}/finance-posting", [
            'enabled' => true, 'cutover_date' => '2026-08-01',
        ])->assertOk()->assertJsonPath('meta.reevaluated_postings', 2);

        $this->assertSame(['pending', 'pending'], FinancePosting::query()->orderBy('posting_id')->pluck('status')->all());
    }

    public function test_lowering_currency_precision_keeps_published_postings_at_their_own_precision(): void
    {
        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => false]);
        $this->assertSame('held', $this->terbitkan($this->perolehan())['status']);
        $this->assertSame('manual', $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-LAMA', 'tanggal' => '2026-08-20']))['status']);

        // Konsultan memutuskan IDR tanpa desimal (TODO 0.7) sesudah kedua posting terbit.
        $this->actingAs($this->owner)->put('/settings/currencies/IDR', ['amount_decimals' => 0, 'unit_amount_decimals' => 3])
            ->assertSessionHasNoErrors();

        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => true]);
        $diperbaiki = app(PostingPublisher::class)->revalidate(FinancePosting::query()->where('posting_id', 'AST-ACQ-0001')->firstOrFail(), $this->owner->id);
        $this->assertSame('pending', $diperbaiki->status);
        $this->assertSame(2, $diperbaiki->payload['currency']['decimals']);
        $this->assertSame('500000000.00', $diperbaiki->payload['journal_lines'][0]['debit']);

        // Menyimpan setelan entitas menilai ulang posting lama sampai selesai, tidak berhenti dengan 500.
        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$this->le->id}/finance-posting", [
            'enabled' => true, 'cutover_date' => '2026-08-01',
        ])->assertOk()->assertJsonPath('meta.reevaluated_postings', 1);
        $lama = FinancePosting::query()->where('posting_id', 'AST-ACQ-LAMA')->firstOrFail();
        $this->assertSame(['pending', 2], [$lama->status, $lama->currency_decimals]);

        // Module yang menerbitkan ulang dokumen yang sama, kini dibulatkan ke presisi baru, mendapat
        // posting yang sudah ada, bukan penolakan "isi jurnal berbeda".
        $ulang = $this->terbitkan($this->perolehan(['nilai' => '500000000']));
        $this->assertFalse($ulang['created']);
        $this->assertSame('500000000.00', $ulang['payload']['journal_lines'][0]['debit']);

        // Posting yang terbit sesudah perubahan memakai presisi baru.
        $baru = $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-BARU', 'nilai' => '750000000']));
        $this->assertSame(0, $baru['payload']['currency']['decimals']);
        $this->assertSame('750000000', $baru['payload']['journal_lines'][0]['debit']);
    }

    public function test_revalidation_and_cutover_reevaluation_keep_a_manual_mark_made_meanwhile(): void
    {
        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => false]);
        foreach (['AST-ACQ-0001', 'AST-ACQ-0002', 'AST-ACQ-0003'] as $postingId) {
            $this->assertSame('held', $this->terbitkan($this->perolehan(['posting_id' => $postingId]))['status']);
        }
        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => true]);
        $penerbit = app(PostingPublisher::class);

        // Validasi ulang membawa model yang dibaca sebelum pengguna lain menandainya manual.
        $basi = FinancePosting::query()->where('posting_id', 'AST-ACQ-0001')->firstOrFail();
        $penerbit->markManual(FinancePosting::query()->where('posting_id', 'AST-ACQ-0001')->firstOrFail(), 'Sudah dijurnal manual', $this->owner->id);
        $this->assertSame('manual', $penerbit->revalidate($basi, $this->owner->id)->status);

        // Penilaian ulang cutover memilih postingnya, lalu pengguna lain menandainya manual sebelum
        // barisnya dikunci: sekali lewat jalur pembentukan ulang, sekali lewat jalur feed dimatikan.
        $sasaran = null;
        FinancePosting::retrieved(function (FinancePosting $posting) use (&$sasaran): void {
            if ($posting->posting_id === $sasaran) {
                $sasaran = null;
                DB::table('finance_postings')->where('id', $posting->id)
                    ->update(['status' => 'manual', 'manual_reason' => 'user', 'hold_reasons' => null]);
            }
        });
        $sasaran = 'AST-ACQ-0002';
        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$this->le->id}/finance-posting", [
            'enabled' => true, 'cutover_date' => '2026-08-15',
        ])->assertOk();
        $sasaran = 'AST-ACQ-0003';
        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$this->le->id}/finance-posting", [
            'enabled' => false, 'cutover_date' => '2026-08-15',
        ])->assertOk();

        $this->assertSame(
            [['AST-ACQ-0001', 'manual', 'user'], ['AST-ACQ-0002', 'manual', 'user'], ['AST-ACQ-0003', 'manual', 'user']],
            FinancePosting::query()->orderBy('posting_id')->get()
                ->map(fn (FinancePosting $posting): array => [$posting->posting_id, $posting->status, $posting->manual_reason])->all(),
        );
    }

    public function test_mapping_fix_url_must_be_a_path_inside_the_app(): void
    {
        foreach (['https://contoh.invalid/pemetaan', '//contoh.invalid/pemetaan', 'javascript:alert(1)', 'm/management-aset'] as $url) {
            $masukan = $this->penyusutan();
            $masukan['lines'][1]['account_id'] = null;
            $masukan['lines'][1]['mapping'] = ['label' => 'Group KENDARAAN · akun akumulasi', 'fix_url' => $url];
            try {
                $this->terbitkan($masukan);
                $this->fail(sprintf('fix_url %s harus ditolak.', $url));
            } catch (PostingTidakSah $kegagalan) {
                $this->assertStringContainsString('Baris 2 mapping.fix_url', $kegagalan->getMessage());
            }
        }
        $this->assertSame(0, FinancePosting::query()->count());
    }

    public function test_tarikan_hanya_pending_urut_tanggal_dan_disajikan_ulang_sampai_di_ack(): void
    {
        $token = $this->token();
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-C', 'tanggal' => '2026-09-20']));
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-A', 'tanggal' => '2026-09-10']));
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-B', 'tanggal' => '2026-09-15']));

        $pertama = $this->tarik($token, ['limit' => 2])->assertOk()->assertJsonPath('meta.has_more', true);
        $this->assertSame(['AST-ACQ-A', 'AST-ACQ-B'], array_column($pertama->json('data'), 'posting_id'));
        $this->assertCocokSkema($pertama->json('data.0'), 'FinancePosting');

        // Terbit di antara dua tarikan, bertanggal lebih awal: tampil di tempat urutannya.
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-0', 'tanggal' => '2026-09-05']));
        $kedua = $this->tarik($token)->assertOk()->assertJsonPath('meta.has_more', false);
        $this->assertSame(['AST-ACQ-0', 'AST-ACQ-A', 'AST-ACQ-B', 'AST-ACQ-C'], array_column($kedua->json('data'), 'posting_id'));

        $this->assertSame(2, FinancePosting::query()->where('posting_id', 'AST-ACQ-A')->value('served_count'));
        $this->assertNotNull(DB::table('integration_clients')->value('last_pulled_at'));
        $this->assertIsObject(json_decode((string) $kedua->getContent())->data[0]->details);
    }

    public function test_ack_idempoten_dan_ack_yang_bertentangan_409(): void
    {
        $token = $this->token();
        $this->terbitkan($this->perolehan());

        $this->ack($token, 'AST-ACQ-0001', ['status' => 'posted'])->assertStatus(422)->assertJsonValidationErrors('external_reference');
        $this->ack($token, 'AST-ACQ-0001', ['status' => 'rejected', 'reason_code' => 'LUPA', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('reason_code');

        $jawaban = $this->ack($token, 'AST-ACQ-0001', ['status' => 'posted', 'external_reference' => 'JV-2026-0001'])
            ->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertCocokSkema($jawaban->json('data'), 'FinancePostingAckResult');
        $this->ack($token, 'AST-ACQ-0001', ['status' => 'posted', 'external_reference' => 'JV-2026-0001'])->assertOk();
        $this->ack($token, 'AST-ACQ-0001', ['status' => 'posted', 'external_reference' => 'JV-2026-0002'])
            ->assertStatus(409)->assertJsonPath('data.external_reference', 'JV-2026-0001');
        $this->ack($token, 'AST-ACQ-0001', ['status' => 'rejected', 'reason_code' => 'PERIOD_CLOSED', 'reason' => 'Periode tutup'])
            ->assertStatus(409);

        $this->assertSame(1, DB::table('finance_posting_events')->where('event', 'acknowledged_posted')->count());
        $this->tarik($token)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ack_posting_tidak_dikenal_milik_tenant_lain_atau_yang_ditahan(): void
    {
        $token = $this->token();
        $lain = $this->pemilik('owner@lain.test', 'PT Lain');
        $leLain = $this->legalEntity('PT Lain Sehat', 'LAIN', $lain);
        $postingLain = FinancePosting::query()->create([
            'tenant_id' => $lain->activeMembership()->tenant_id, 'legal_entity_id' => $leLain->id, 'posting_id' => 'AST-ACQ-0001',
            'posting_type' => 'asset.acquisition', 'source_module' => 'management-aset', 'source_type' => 'penerimaan-aset',
            'currency_code' => 'IDR', 'currency_decimals' => 2, 'posting_date' => '2026-09-28', 'document_date' => '2026-09-28',
            'occurred_at' => now(), 'published_at' => now(), 'status' => 'pending', 'total_debit' => '1', 'total_credit' => '1',
            'payload' => ['posting_id' => 'AST-ACQ-0001'], 'input' => [], 'input_hash' => str_repeat('0', 64),
        ]);

        $this->ack($token, 'TIDAK-ADA', ['status' => 'posted', 'external_reference' => 'JV-1'])->assertNotFound();
        $this->ack($token, 'AST-ACQ-0001', ['status' => 'posted', 'external_reference' => 'JV-1'])->assertNotFound();
        $this->assertSame('pending', $postingLain->refresh()->status);

        FinanceReferenceAccount::query()->whereKey($this->akun['hutang'])->update(['active' => false]);
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-HELD']));
        $this->ack($token, 'AST-ACQ-HELD', ['status' => 'posted', 'external_reference' => 'JV-1'])->assertStatus(409);
    }

    public function test_klien_berawalan_asset_tidak_pernah_menerima_jenis_lain(): void
    {
        $token = $this->token(prefixes: ['asset.']);
        $semua = $this->token(prefixes: []);
        $this->terbitkan($this->perolehan());
        $kasir = $this->perolehan(['posting_id' => 'KSR-0001']);
        $kasir['posting_type'] = 'cashier.receipt';
        $this->terbitkan($kasir);

        $this->assertSame(['AST-ACQ-0001'], array_column($this->tarik($token)->json('data'), 'posting_id'));
        $this->assertSame(['AST-ACQ-0001'], array_column($this->tarik($semua, ['posting_type' => 'asset.*'])->json('data'), 'posting_id'));
        $this->assertSame(['KSR-0001'], array_column($this->tarik($semua, ['posting_type' => 'cashier.receipt'])->json('data'), 'posting_id'));
        $this->assertCount(0, $this->tarik($token, ['posting_type' => 'cashier.*'])->json('data'));
        $this->ack($token, 'KSR-0001', ['status' => 'posted', 'external_reference' => 'JV-1'])->assertNotFound();
        $this->tarik($semua, ['legal_entity' => 'META'])->assertJsonCount(2, 'data');
        $this->tarik($semua, ['legal_entity' => 'TIDAK-ADA'])->assertJsonCount(0, 'data');
    }

    public function test_tenant_terisolasi_dan_posting_id_boleh_sama_di_tenant_lain(): void
    {
        $this->terbitkan($this->perolehan());
        $lain = $this->pemilik('owner@lain.test', 'PT Lain');
        $leLain = $this->legalEntity('PT Lain Sehat', 'LAIN', $lain);
        $masukan = $this->perolehan(['tenant' => $lain->activeMembership()->tenant_id]);
        $masukan['legal_entity_id'] = $leLain->id;
        unset($masukan['vendor_id'], $masukan['requires_vendor']);

        $hasil = $this->terbitkan($masukan);

        // Entitas legal tenant lain belum mengaktifkan feed, jadi posting-nya manual.
        $this->assertSame('manual', $hasil['status']);
        $this->assertSame(2, FinancePosting::query()->where('posting_id', 'AST-ACQ-0001')->count());
        $tarikan = $this->tarik($this->token())->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($this->le->id, $tarikan->json('data.0.legal_entity.id'));
    }

    public function test_cakupan_tarik_dan_ack_terpisah(): void
    {
        $this->terbitkan($this->perolehan());

        // Lebih dulu: header yang dipasang `withHeaders` bertahan ke permintaan berikutnya.
        $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/internal/v1/finance-postings')->assertUnauthorized();
        $this->ack($this->token(['finance-postings.read']), 'AST-ACQ-0001', ['status' => 'posted', 'external_reference' => 'JV-1'])->assertForbidden();
        $this->tarik($this->token(['finance-postings.ack']))->assertForbidden();
    }

    public function test_koreksi_mewarisi_mode_penyelesaian_posting_asal(): void
    {
        $this->terbitkan($this->perolehan());
        $koreksi = $this->perolehan(['posting_id' => 'AST-ADJ-0001', 'nilai' => '10000000.00']);
        $koreksi['posting_type'] = 'asset.acquisition_adjustment';
        $koreksi['adjusts_posting_id'] = 'AST-ACQ-0001';
        unset($koreksi['settlement_mode']);

        $this->assertSame('direct_payable', $this->terbitkan($koreksi)['payload']['settlement_mode']);

        $salahMode = [...$koreksi, 'posting_id' => 'AST-ADJ-0002', 'settlement_mode' => 'clearing'];
        $tanpaAsal = [...$koreksi, 'posting_id' => 'AST-ADJ-0003', 'adjusts_posting_id' => 'TIDAK-ADA'];
        foreach ([$salahMode, $tanpaAsal] as $masukan) {
            try {
                $this->terbitkan($masukan);
                $this->fail('Koreksi yang tidak mewarisi posting asalnya harus ditolak.');
            } catch (PostingTidakSah) {
            }
        }
    }

    public function test_vendor_wajib_untuk_pembelian_dan_harus_milik_entitas_legal_itu(): void
    {
        $tanpaVendor = $this->perolehan();
        unset($tanpaVendor['vendor_id']);
        $leLain = $this->legalEntity('PT Metta Farma', 'MFAR');
        $vendorLain = (string) $this->actingAs($this->owner)->postJson('/api/v1/vendors', [
            'legal_entity_id' => $leLain->id, 'party_name' => 'PT Farma Nusantara',
        ])->json('data.id');

        foreach ([$tanpaVendor, [...$this->perolehan(), 'vendor_id' => $vendorLain]] as $masukan) {
            try {
                $this->terbitkan($masukan);
                $this->fail('Vendor yang salah harus ditolak.');
            } catch (PostingTidakSah) {
            }
        }
        $this->assertSame(0, FinancePosting::query()->count());
    }

    public function test_pratinjau_memeriksa_sama_tanpa_menyimpan(): void
    {
        FinanceReferenceAccount::query()->whereKey($this->akun['aset'])->update(['active' => false]);

        $hasil = app(PenerbitPosting::class)->pratinjau($this->perolehan());

        $this->assertSame('held', $hasil['status']);
        $this->assertSame('ACCOUNT_INACTIVE', $hasil['problems'][0]['code']);
        $this->assertFalse($hasil['created']);
        $this->assertSame(0, FinancePosting::query()->count());
        $this->assertNull(app(PenerbitPosting::class)->status($this->membership->tenant_id, 'AST-ACQ-0001'));
    }

    public function test_push_bertanda_tangan_dan_ack_di_jawaban_menutup_posting(): void
    {
        $rahasia = $this->klienPush();
        Http::fake(['finance.example.test/*' => Http::response(['status' => 'posted', 'external_reference' => 'JV-PUSH-1'])]);
        $this->terbitkan($this->perolehan());

        $hasil = app(PostingPusher::class)->run();

        $this->assertSame(1, $hasil['sent']);
        Http::assertSent(function (HttpRequest $request) use ($rahasia): bool {
            $stempel = $request->header('X-CoreERP-Event-Timestamp')[0] ?? '';

            return $request->url() === 'https://finance.example.test/hook'
                && hash_equals(hash_hmac('sha256', $stempel.'.'.$request->body(), $rahasia), $request->header('X-CoreERP-Event-Signature')[0] ?? '')
                && ($request->data()['posting_id'] ?? null) === 'AST-ACQ-0001';
        });
        $posting = FinancePosting::query()->firstOrFail();
        $this->assertSame('posted', $posting->status);
        $this->assertSame('JV-PUSH-1', $posting->external_reference);
        $this->assertSame('delivered', FinancePostingDelivery::query()->value('status'));
    }

    public function test_push_5xx_dicoba_lagi_dengan_jeda_4xx_berhenti_dan_urutan_per_klien_dijaga(): void
    {
        $this->klienPush();
        Http::fake(['finance.example.test/*' => Http::sequence()
            ->push('sibuk', 503)
            ->push('sibuk', 503)
            ->push(['message' => 'akun tidak dikenal'], 422)
            ->push('', 200)]);
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-A', 'tanggal' => '2026-09-10']));
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-B', 'tanggal' => '2026-09-11']));

        $pusher = app(PostingPusher::class);
        $this->assertSame(1, $pusher->run()['retrying']);
        // Posting B tidak dikirim selama A menunggu jeda.
        Http::assertSentCount(1);
        $pusher->run();
        Http::assertSentCount(1);

        $this->travel(2)->minutes();
        $pusher->run();
        Http::assertSentCount(2);
        $this->assertSame(2, FinancePostingDelivery::query()->value('attempts'));

        $this->travel(3)->minutes();
        $hasil = $pusher->run();
        // A ditolak permanen (422), lalu B langsung terkirim pada putaran yang sama.
        $this->assertSame(['failed' => 1, 'sent' => 1], ['failed' => $hasil['failed'], 'sent' => $hasil['sent']]);
        $this->assertSame(
            ['AST-ACQ-A' => 'failed', 'AST-ACQ-B' => 'delivered'],
            FinancePostingDelivery::query()->with('posting')->get()->mapWithKeys(fn (FinancePostingDelivery $d): array => [$d->posting->posting_id => $d->status])->sortKeys()->all(),
        );
        // 200 tanpa badan ack: terkirim, tetapi tetap pending sampai di-ack lewat API.
        $this->assertSame('pending', FinancePosting::query()->where('posting_id', 'AST-ACQ-B')->value('status'));

        $pusher->run();
        Http::assertSentCount(4);
    }

    public function test_push_batas_waktu_habis_dan_redirect_menjadi_gagal(): void
    {
        $this->klienPush();
        Http::fake(['finance.example.test/*' => Http::sequence()->push('pindah', 302, ['Location' => 'https://10.0.0.5/'])->push('sibuk', 503)]);
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-A']));
        $this->terbitkan($this->perolehan(['posting_id' => 'AST-ACQ-B', 'tanggal' => '2026-09-29']));
        DB::table('finance_posting_deliveries')->insert([
            'id' => strtolower((string) str()->ulid()), 'tenant_id' => $this->membership->tenant_id,
            'finance_posting_id' => FinancePosting::query()->where('posting_id', 'AST-ACQ-B')->value('id'),
            'integration_client_id' => DB::table('integration_clients')->value('id'), 'status' => 'retrying', 'attempts' => 20,
            'first_attempt_at' => now()->subHours(25), 'last_attempt_at' => now()->subHour(), 'next_attempt_at' => now()->subMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(PostingPusher::class)->run();

        $status = FinancePostingDelivery::query()->with('posting')->get()->mapWithKeys(fn (FinancePostingDelivery $d): array => [$d->posting->posting_id => [$d->status, $d->last_status_code]])->all();
        $this->assertSame(['failed', 302], $status['AST-ACQ-A']);
        $this->assertSame(['failed', 503], $status['AST-ACQ-B']);
        Http::assertSentCount(2);
    }

    public function test_salinan_sandbox_tidak_mengirim_apa_pun(): void
    {
        $this->klienPush();
        Http::fake();
        $this->terbitkan($this->perolehan());
        $sandbox = Environment::create([
            'tenant_id' => $this->membership->tenant_id, 'kind' => 'sandbox', 'name' => 'Uji sandbox',
            'slug' => 'uji-sandbox', 'database_name' => null, 'hosting' => Environment::HOSTING_PROVIDER,
            'status' => 'active', 'outbound_allowed' => false,
        ]);
        $this->app->instance(ActiveEnvironment::KEY, $sandbox->id);

        $hasil = app(PostingPusher::class)->run();

        $this->assertNotNull($hasil['skipped']);
        Http::assertNothingSent();
        $this->assertSame(0, FinancePostingDelivery::query()->count());
        $this->assertSame('pending', FinancePosting::query()->value('status'));
    }

    /**
     * @param  array{posting_id?: string, tanggal?: string, nilai?: string, tenant?: string, description_ignored?: bool}  $ubah
     * @return array<string, mixed>
     */
    private function perolehan(array $ubah = []): array
    {
        $nilai = $ubah['nilai'] ?? '500000000.00';
        $tanggal = $ubah['tanggal'] ?? '2026-09-28';

        return [
            'tenant_id' => $ubah['tenant'] ?? $this->membership->tenant_id,
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
            'source_document' => [
                'module' => 'management-aset', 'type' => 'penerimaan-aset', 'number' => 'PNA-2026-09-0007',
                // Deskripsi tidak ikut menentukan isi jurnal: menerbitkan ulang dengan deskripsi lain tetap sama.
                'description' => ($ubah['description_ignored'] ?? false) ? 'Penerimaan ambulans (ulang)' : 'Penerimaan ambulans',
            ],
            'lines' => [
                ['account_id' => $this->akun['aset'], 'debit' => $nilai, 'credit' => '0', 'description' => 'KEND-0012 Ambulans', 'org_unit_id' => $this->poli->id],
                ['account_id' => $this->akun['hutang'], 'debit' => '0', 'credit' => $nilai, 'description' => 'PT Karoseri Sehat', 'org_unit_id' => $this->poli->id],
            ],
            'details' => ['assets' => [['asset_code' => 'KEND-0012', 'acquisition_value' => $nilai]]],
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

    /**
     * @param  array<string, mixed>  $masukan
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    private function terbitkan(array $masukan): array
    {
        return DB::transaction(fn (): array => app(PenerbitPosting::class)->terbitkan($masukan));
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $prefixes
     */
    private function token(array $scopes = ['finance-postings.read', 'finance-postings.ack'], array $prefixes = ['asset.']): string
    {
        return (string) $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', [
            'name' => 'Old-finance '.str()->random(6), 'delivery_mode' => 'pull', 'push_url' => null,
            'scopes' => $scopes, 'posting_type_prefixes' => $prefixes, 'allowed_ips' => [],
        ])->assertCreated()->json('token');
    }

    private function klienPush(): string
    {
        return (string) $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', [
            'name' => 'Old-finance push', 'delivery_mode' => 'push', 'push_url' => 'https://finance.example.test/hook',
            'scopes' => ['finance-postings.read', 'finance-postings.ack'], 'posting_type_prefixes' => ['asset.'], 'allowed_ips' => [],
        ])->assertCreated()->json('signing_secret');
    }

    /** @param array<string, mixed> $query */
    private function tarik(string $token, array $query = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/internal/v1/finance-postings'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @param array<string, mixed> $body */
    private function ack(string $token, string $postingId, array $body): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/internal/v1/finance-postings/'.$postingId.'/ack', $body);
    }

    private function pemilik(string $email, string $bisnis): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$bisnis, 'business_name' => $bisnis,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);
    }

    private function legalEntity(string $nama, string $kode, ?User $pemilik = null): Organization
    {
        $this->actingAs($pemilik ?? $this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => $nama, 'company_code' => $kode, 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();

        return Organization::query()->where('name', $nama)->firstOrFail();
    }

    private function operatingUnit(string $nama, string $tipe, ?string $nomor): Organization
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', array_filter([
            'classification' => 'operating_unit',
            'name' => $nama,
            'operating_unit_type' => $tipe,
            'operating_unit_number' => $nomor,
        ], static fn (?string $nilai): bool => $nilai !== null))->assertSessionHasNoErrors();

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

    /** Menempatkan unit baru di versi baru hierarki manajemen yang sudah terbit. */
    private function tempatkan(Organization $unit, Organization $induk): void
    {
        $terbit = OrganizationHierarchyVersion::query()
            ->whereHas('hierarchy', fn ($query) => $query->where('name', 'Struktur manajemen'))
            ->where('status', 'published')
            ->firstOrFail();
        $this->actingAs($this->owner)->post("/settings/organization/hierarchy-versions/{$terbit->id}/drafts", [
            'effective_from' => '2026-02-01',
        ])->assertSessionHasNoErrors();
        $draf = OrganizationHierarchyVersion::query()
            ->whereHas('hierarchy', fn ($query) => $query->where('name', 'Struktur manajemen'))
            ->where('status', 'draft')
            ->firstOrFail();
        $this->post("/settings/organization/hierarchy-versions/{$draf->id}/placements", [
            'organization_id' => $unit->id, 'parent_organization_id' => $induk->id,
        ])->assertSessionHasNoErrors();
        $this->post("/settings/organization/hierarchy-versions/{$draf->id}/publish")->assertSessionHasNoErrors();
    }
}
