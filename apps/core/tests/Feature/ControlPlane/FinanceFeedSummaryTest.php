<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\FinancePosting;
use App\Models\IntegrationClient;
use App\Models\Organization;
use App\Models\User;
use App\Support\Finance\PostingFeedSummary;
use App\Support\Finance\PostingPusher;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CocokDenganKontrak;
use Tests\TestCase;

/**
 * Ringkasan feed posting finance yang dibaca agen situs lewat `finance-postings:summary` (TODO feed posting
 * finance 14.1).
 *
 * Keluaran perintah itu janji kepada agen: agen menyusun ulang `finance_feed` kunci demi kunci dari sana, dan
 * admin.erp menolak laporan yang memuat kunci lain. Yang dipaku di sini karena itu bentuk keluarannya persis — kunci
 * tambahan, misalnya nomor posting atau nilai uang, membuat test ini merah sebelum ia sempat dikirim ke mana pun —
 * dan setiap keluaran dicocokkan dengan skema `FinanceFeed` di kontrak agen, yang juga dipakai agen dan konsol.
 */
class FinanceFeedSummaryTest extends TestCase
{
    use CocokDenganKontrak, RefreshDatabase;

    /** Kontrak agen milik konsol, relatif terhadap `base_path()` Core. */
    private const KONTRAK_AGEN = '../control-plane/contracts/openapi-agent.yaml';

    private User $owner;

    private Organization $le;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);
        Http::preventStrayRequests();

        $this->owner = $this->pemilik('owner@metta.test', 'PT Metta');
        $this->le = $this->legalEntity('PT Metta Sehat', 'META', $this->owner);
    }

    public function test_tanpa_posting_dan_tanpa_tarikan_semua_jumlah_nol_dan_waktunya_null(): void
    {
        $this->assertSame([
            'counts' => ['held' => 0, 'pending' => 0, 'posted' => 0, 'rejected' => 0, 'manual' => 0],
            'oldest_pending_at' => null,
            'last_pulled_at' => null,
            'last_pushed_at' => null,
        ], $this->ringkasan());
    }

    public function test_hanya_jumlah_per_status_dan_waktu_dijumlahkan_lintas_tenant(): void
    {
        $lain = $this->pemilik('owner@lain.test', 'PT Lain');
        $leLain = $this->legalEntity('PT Lain Sehat', 'LAIN', $lain);

        // Tanggal akuntansi sengaja berlawanan urutan dengan jam terbit: umur dihitung dari jam terbit.
        $this->posting($this->le, FinancePosting::PENDING, '2026-09-21 09:00:00', '2026-09-01');
        $this->posting($this->le, FinancePosting::PENDING, '2026-09-20 03:00:00', '2026-09-18');
        // Lebih tua dari posting pending mana pun, tetapi tertahan: bukan umur pending.
        $this->posting($this->le, FinancePosting::HELD, '2026-09-01 00:00:00');
        $this->posting($this->le, FinancePosting::POSTED, '2026-09-02 00:00:00');
        $this->posting($this->le, FinancePosting::REJECTED, '2026-09-03 00:00:00');
        $this->posting($this->le, FinancePosting::REJECTED, '2026-09-04 00:00:00');
        $this->posting($leLain, FinancePosting::PENDING, '2026-09-19 22:15:00', '2026-09-25');
        $this->posting($leLain, FinancePosting::MANUAL, '2026-08-30 00:00:00');

        $this->klien($this->le->tenant_id, 'Old-finance', '2026-09-23 07:00:00');
        $this->klien($leLain->tenant_id, 'Finance lain', '2026-09-23 08:30:00');
        $this->klien($leLain->tenant_id, 'Belum pernah pull', null);

        $this->assertSame([
            'counts' => ['held' => 1, 'pending' => 3, 'posted' => 1, 'rejected' => 2, 'manual' => 1],
            'oldest_pending_at' => '2026-09-19T22:15:00Z',
            'last_pulled_at' => '2026-09-23T08:30:00Z',
            'last_pushed_at' => null,
        ], $this->ringkasan());
    }

    /**
     * Jalur sungguhan untuk kolom yang dibaca ringkasan: jam pull diisi endpoint pull, dan status diubah ack
     * pembaca. Ringkasan yang membaca kolom lain akan tetap hijau pada test sebelumnya, yang mengisi kolomnya sendiri.
     */
    public function test_tarikan_dan_ack_pembaca_tercermin_di_ringkasan(): void
    {
        $this->posting($this->le, FinancePosting::PENDING, '2026-09-22 01:00:00', postingId: 'AST-ACQ-0001');
        $this->posting($this->le, FinancePosting::PENDING, '2026-09-22 05:30:00', postingId: 'AST-ACQ-0002');
        $token = (string) $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', [
            'name' => 'Old-finance', 'delivery_mode' => 'pull', 'push_url' => null,
            'scopes' => ['finance-postings.read', 'finance-postings.ack'], 'posting_type_prefixes' => ['asset.'], 'allowed_ips' => [],
        ])->assertCreated()->json('token');

        $this->travelTo(Carbon::parse('2026-09-23 06:45:10', 'UTC'));
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/internal/v1/finance-postings')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/internal/v1/finance-postings/AST-ACQ-0001/ack', ['status' => 'posted', 'external_reference' => 'JV-2026-0001'])
            ->assertOk();

        $this->assertSame([
            'counts' => ['held' => 0, 'pending' => 1, 'posted' => 1, 'rejected' => 0, 'manual' => 0],
            'oldest_pending_at' => '2026-09-22T05:30:00Z',
            'last_pulled_at' => '2026-09-23T06:45:10Z',
            'last_pushed_at' => null,
        ], $this->ringkasan());
    }

    /**
     * Push terakhir adalah kiriman terakhir yang dijawab 2xx oleh pembaca, lewat jalur kirim yang sungguhan. Kiriman
     * yang masih dicoba lagi atau gagal sesudahnya tidak menggesernya: yang ditanyakan kapan pembaca terakhir menerima.
     */
    public function test_the_last_push_a_reader_accepted_is_reported_and_failed_ones_are_not(): void
    {
        $this->posting($this->le, FinancePosting::PENDING, '2026-09-22 01:00:00', postingId: 'AST-ACQ-0001');
        $this->posting($this->le, FinancePosting::PENDING, '2026-09-22 05:30:00', postingId: 'AST-ACQ-0002');
        $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', [
            'name' => 'Old-finance push', 'delivery_mode' => 'push', 'push_url' => 'https://finance.example.test/hook',
            'scopes' => ['finance-postings.read', 'finance-postings.ack'], 'posting_type_prefixes' => ['asset.'], 'allowed_ips' => [],
        ])->assertCreated();
        Http::fake(['finance.example.test/*' => Http::sequence()
            ->push('', 200)
            ->push('sibuk', 503)
            ->push(['message' => 'akun tidak dikenal'], 422)]);
        $pusher = app(PostingPusher::class);

        $this->travelTo(Carbon::parse('2026-09-23 06:50:00', 'UTC'));
        $this->assertSame(['sent' => 1, 'retrying' => 1], array_intersect_key($pusher->run(), ['sent' => 0, 'retrying' => 0]));
        $this->travelTo(Carbon::parse('2026-09-23 07:30:00', 'UTC'));
        $this->assertSame(1, $pusher->run()['failed']);

        $this->assertSame([
            'counts' => ['held' => 0, 'pending' => 2, 'posted' => 0, 'rejected' => 0, 'manual' => 0],
            'oldest_pending_at' => '2026-09-22T01:00:00Z',
            'last_pulled_at' => null,
            'last_pushed_at' => '2026-09-23T06:50:00Z',
        ], $this->ringkasan());
    }

    /**
     * Status yang dihitung ringkasan sama dengan status yang diizinkan tabelnya. Status baru di CHECK tanpa baris
     * di `PostingFeedSummary::STATUSES` akan hilang diam-diam dari jumlahnya — dan dari layar admin.erp.
     */
    public function test_status_yang_dihitung_sama_dengan_check_tabel(): void
    {
        $definisi = (string) DB::scalar(
            "select pg_get_constraintdef(oid) from pg_constraint where conname = 'finance_postings_status_check'",
        );
        preg_match_all("/'([a-z_]+)'/", $definisi, $cocok);

        $this->assertNotSame([], $cocok[1], 'CHECK status posting tidak terbaca: '.$definisi);
        $this->assertEqualsCanonicalizing($cocok[1], PostingFeedSummary::STATUSES);
    }

    /**
     * Keluaran perintah, dibaca seperti agen membacanya: seluruh stdout sebagai satu nilai JSON.
     *
     * @return array<string, mixed>
     */
    private function ringkasan(): array
    {
        $this->assertSame(0, Artisan::call('finance-postings:summary'));
        $keluaran = Artisan::output();

        $this->assertSame(1, substr_count(rtrim($keluaran, "\n"), "\n") + 1, 'Keluaran harus satu baris: '.$keluaran);

        $nilai = json_decode($keluaran, true, 8, JSON_THROW_ON_ERROR);
        $this->assertIsArray($nilai);
        $this->assertCocokSkema($nilai, 'FinanceFeed', self::KONTRAK_AGEN);

        return $nilai;
    }

    private function posting(Organization $le, string $status, string $terbit, string $tanggal = '2026-09-15', ?string $postingId = null): FinancePosting
    {
        $postingId ??= 'AST-ACQ-'.Str::upper(Str::random(10));

        return FinancePosting::query()->create([
            'tenant_id' => $le->tenant_id, 'legal_entity_id' => $le->id, 'posting_id' => $postingId,
            'posting_type' => 'asset.acquisition', 'source_module' => 'management-aset', 'source_type' => 'penerimaan-aset',
            'source_number' => 'PNA-2026-09-0007', 'currency_code' => 'IDR', 'currency_decimals' => 2,
            'posting_date' => $tanggal, 'document_date' => $tanggal, 'occurred_at' => $terbit, 'published_at' => $terbit,
            'status' => $status,
            'manual_reason' => $status === FinancePosting::MANUAL ? FinancePosting::MANUAL_USER : null,
            'external_reference' => $status === FinancePosting::POSTED ? 'JV-2026-0001' : null,
            'reason_code' => $status === FinancePosting::REJECTED ? 'PERIOD_CLOSED' : null,
            'total_debit' => '555000000', 'total_credit' => '555000000',
            'payload' => [
                'posting_id' => $postingId,
                'journal_lines' => [['account' => ['code' => '2-1100', 'name' => 'Hutang Usaha'], 'credit' => '555000000.00']],
            ],
            'input' => [], 'input_hash' => str_repeat('0', 64),
        ]);
    }

    private function klien(string $tenantId, string $nama, ?string $tarikanTerakhir): void
    {
        IntegrationClient::query()->create([
            'tenant_id' => $tenantId, 'name' => $nama, 'token_digest' => IntegrationClient::digest(Str::random(40)),
            'scopes' => ['finance-postings.read'], 'delivery_mode' => IntegrationClient::PULL, 'status' => IntegrationClient::ACTIVE,
            'last_pulled_at' => $tarikanTerakhir,
        ]);
    }

    private function pemilik(string $email, string $bisnis): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$bisnis, 'business_name' => $bisnis,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);
    }

    private function legalEntity(string $nama, string $kode, User $pemilik): Organization
    {
        $this->actingAs($pemilik)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => $nama, 'company_code' => $kode, 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();

        return Organization::query()->where('name', $nama)->firstOrFail();
    }
}
