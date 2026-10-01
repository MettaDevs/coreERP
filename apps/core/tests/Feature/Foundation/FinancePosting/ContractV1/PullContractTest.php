<?php

namespace Tests\Feature\Foundation\FinancePosting\ContractV1;

use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Foundation\FinancePosting\Models\FinancePostingSetting;
use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Support\ActiveEnvironment;
use App\Platform\Integration\Models\IntegrationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CocokDenganKontrak;
use Tests\TestCase;

/**
 * Mengunci `GET /api/internal/v1/finance-postings` (kontrak feed posting finance v1, mode pull).
 *
 * Yang dikunci adalah perilaku yang dipakai tim finance hari ini, bukan bentuk ideal di YAML. Perbedaan
 * yang ditemukan saat test ini ditulis:
 *
 * - Urutan kunci di setiap posting **bukan** urutan properti di skema `FinancePosting`. `payload`
 *   disimpan di kolom `jsonb`, dan PostgreSQL mengurutkan kunci objek menurut panjang lalu abjad.
 *   Snapshot mengunci urutan yang benar-benar terkirim.
 * - Komponen jawaban bersama `Unauthenticated`, `Forbidden`, dan `RateLimited` di kontrak ditulis
 *   untuk kredensial app module ("header kredensial atau konteks tenant", "per pasangan app dan
 *   tenant, default 600"). Untuk klien integrasi batasnya 120 per menit per klien, dan satu jatah
 *   dipakai bersama oleh pull dan ack. Bentuk body-nya tetap `Error` (`{message}`).
 * - Kontrak tidak menyebut header `X-RateLimit-Limit`, `X-RateLimit-Remaining`, dan `Retry-After`;
 *   Core mengirimnya dan test ini menguncinya.
 *
 * Body kesalahan dikunci dengan `app.debug` mati, seperti di produksi: dengan debug hidup Laravel
 * menambahkan `exception`, `file`, `line`, dan `trace`.
 */
#[Group('kontrak-feed-finance-v1')]
class PullContractTest extends TestCase
{
    use CocokDenganKontrak, PreparesFinanceFeed, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareFinanceFeed();
        config(['app.debug' => false]);
    }

    public function test_pull_response_matches_the_v1_snapshot_and_the_contract_schema(): void
    {
        $this->publishServedAndUnservedPostings();
        $token = $this->pullToken(prefixes: []);

        $response = $this->pull($token)->assertOk()->assertHeader('Content-Type', 'application/json');

        $this->assertMatchesSnapshot('pull-semua-jenis.json', $this->canonicalJson((string) $response->getContent()));
        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        foreach ($response->json('data') as $posting) {
            $this->assertCocokSkema($posting, 'FinancePosting');
        }
    }

    public function test_only_pending_postings_are_served_in_posting_date_then_published_order(): void
    {
        $this->publishServedAndUnservedPostings();
        $token = $this->pullToken(prefixes: []);

        $this->assertSame(
            ['KSR-0001', 'AST-ACQ-0001', 'AST-ADJ-0001', 'AST-ACQ-0002', 'AST-DEP-2026-09'],
            array_column($this->pull($token)->json('data'), 'posting_id'),
        );
        $this->assertSame(
            ['held' => 1, 'manual' => 1, 'pending' => 5, 'posted' => 1, 'rejected' => 1],
            FinancePosting::query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')
                ->pluck('n', 'status')->map(fn ($n): int => (int) $n)->sortKeys()->all(),
        );
    }

    public function test_there_is_no_cursor_postings_are_served_again_until_acknowledged(): void
    {
        $this->publish($this->acquisition('AST-ACQ-A', '2026-09-10'));
        $this->travel(1)->seconds();
        $this->publish($this->acquisition('AST-ACQ-B', '2026-09-15'));
        $this->travel(1)->seconds();
        $this->publish($this->acquisition('AST-ACQ-C', '2026-09-20'));
        $token = $this->pullToken();

        $first = $this->pull($token, ['limit' => 2])->assertOk();
        $this->assertSame(['AST-ACQ-A', 'AST-ACQ-B'], array_column($first->json('data'), 'posting_id'));
        $this->assertSame(['count' => 2, 'has_more' => true], $first->json('meta'));

        // Tanpa ack, halaman yang sama disajikan lagi. Parameter cursor apa pun diabaikan.
        $again = $this->pull($token, ['limit' => 2, 'cursor' => 'AST-ACQ-B', 'after' => 'AST-ACQ-B', 'page' => 2])->assertOk();
        $this->assertSame(['AST-ACQ-A', 'AST-ACQ-B'], array_column($again->json('data'), 'posting_id'));

        $this->ack($token, 'AST-ACQ-A', ['status' => 'posted', 'external_reference' => 'JV-1'])->assertOk();
        $this->ack($token, 'AST-ACQ-B', ['status' => 'rejected', 'reason_code' => 'PERIOD_CLOSED', 'reason' => 'Tutup'])->assertOk();
        // Terbit di tengah, bertanggal lebih awal: muncul di tempat urutannya pada pull berikutnya.
        $this->publish($this->acquisition('AST-ACQ-0', '2026-09-05'));

        $next = $this->pull($token, ['limit' => 2])->assertOk();
        $this->assertSame(['AST-ACQ-0', 'AST-ACQ-C'], array_column($next->json('data'), 'posting_id'));
        $this->assertSame(['count' => 2, 'has_more' => false], $next->json('meta'));
        $this->assertSame(2, FinancePosting::query()->where('posting_id', 'AST-ACQ-A')->value('served_count'));
    }

    public function test_limit_defaults_to_100_and_is_at_most_500(): void
    {
        $this->publish($this->acquisition('AST-ACQ-000'));
        $template = FinancePosting::query()->firstOrFail();
        for ($i = 1; $i <= 100; $i++) {
            $copy = $template->replicate();
            $copy->posting_id = sprintf('AST-ACQ-%03d', $i);
            $copy->save();
        }
        $token = $this->pullToken();

        $default = $this->pull($token)->assertOk();
        $this->assertSame(['count' => 100, 'has_more' => true], $default->json('meta'));
        $this->assertSame(['count' => 101, 'has_more' => false], $this->pull($token, ['limit' => 500])->json('meta'));
        $this->assertSame(['count' => 1, 'has_more' => true], $this->pull($token, ['limit' => 1])->json('meta'));
        $this->pull($token, ['limit' => 501])->assertStatus(422);
        $this->pull($token, ['limit' => 0])->assertStatus(422);
    }

    public function test_type_prefix_of_the_client_narrows_before_any_parameter(): void
    {
        $this->publishServedAndUnservedPostings();
        $assetOnly = $this->pullToken(prefixes: ['asset.']);
        $starSpelling = $this->pullToken(prefixes: ['asset.*']);
        $cashierOnly = $this->pullToken(prefixes: ['cashier.']);
        $all = $this->pullToken(prefixes: []);

        $ids = fn (string $token, array $query = []): array => array_column($this->pull($token, $query)->assertOk()->json('data'), 'posting_id');

        $this->assertSame(['AST-ACQ-0001', 'AST-ADJ-0001', 'AST-ACQ-0002', 'AST-DEP-2026-09'], $ids($assetOnly));
        $this->assertSame($ids($assetOnly), $ids($starSpelling));
        $this->assertSame(['KSR-0001'], $ids($cashierOnly));
        // Parameter tidak dapat memperluas prefix klien.
        $this->assertSame([], $ids($assetOnly, ['posting_type' => 'cashier.receipt']));
        $this->assertSame([], $ids($assetOnly, ['posting_type' => 'cashier.*']));
        $this->assertSame([], $ids($cashierOnly, ['posting_type' => 'asset.*']));

        $this->assertSame(['AST-DEP-2026-09'], $ids($all, ['posting_type' => 'asset.depreciation']));
        $this->assertSame(['AST-ACQ-0001', 'AST-ACQ-0002'], $ids($all, ['posting_type' => 'asset.acquisition']));
        $this->assertSame(['AST-ACQ-0001', 'AST-ADJ-0001', 'AST-ACQ-0002', 'AST-DEP-2026-09'], $ids($all, ['posting_type' => 'asset.*']));
        // Jenis lengkap dicocokkan persis, bukan sebagai awalan.
        $this->assertSame([], $ids($all, ['posting_type' => 'asset']));
        $this->assertSame(['KSR-0001', 'AST-ACQ-0001', 'AST-ADJ-0001', 'AST-ACQ-0002', 'AST-DEP-2026-09'], $ids($all, ['status' => 'pending']));

        $this->assertCount(5, $ids($all, ['legal_entity' => 'META']));
        $this->assertCount(5, $ids($all, ['legal_entity' => $this->legalEntity->id]));
        $this->assertSame([], $ids($all, ['legal_entity' => 'TIDAK-ADA']));
    }

    public function test_validation_errors_match_the_v1_snapshot(): void
    {
        $token = $this->pullToken();
        $cases = [
            'status_posted' => ['status' => 'posted'],
            'posting_type_huruf_besar' => ['posting_type' => 'Asset.*'],
            'posting_type_bintang_tanpa_titik' => ['posting_type' => 'asset*'],
            'limit_nol' => ['limit' => 0],
            'limit_501' => ['limit' => 501],
            'limit_bukan_angka' => ['limit' => 'semua'],
            'legal_entity_terlalu_panjang' => ['legal_entity' => str_repeat('A', 51)],
        ];

        $results = [];
        foreach ($cases as $case => $query) {
            $response = $this->pull($token, $query);
            $this->assertCocokSkema($response->json(), 'ValidationError');
            $results[$case] = ['status' => $response->status(), 'body' => json_decode((string) $response->getContent())];
        }

        $this->assertMatchesSnapshot('pull-422.json', $this->canonicalJson((string) json_encode($results)));
    }

    public function test_authentication_and_refusal_errors_match_the_v1_snapshot(): void
    {
        $this->publish($this->acquisition());
        $token = $this->pullToken();
        [$id] = explode('.', $token, 2);
        $ackOnly = $this->pullToken(['finance-postings.ack']);
        $elsewhere = $this->pullToken(allowedIps: ['203.0.113.0/28']);
        // Urutan pemeriksaan: IP lebih dulu dari scope.
        $elsewhereAckOnly = $this->pullToken(['finance-postings.ack'], allowedIps: ['203.0.113.0/28']);
        $revoked = $this->pullToken();
        [$revokedId] = explode('.', $revoked, 2);
        $this->actingAs($this->owner)->postJson("/api/v1/integration-clients/{$revokedId}/revoke", [
            'version' => IntegrationClient::query()->findOrFail($revokedId)->version,
        ])->assertOk();

        $results = [];
        $record = function (string $case, $response) use (&$results): void {
            $this->assertCocokSkema($response->json(), 'Error');
            $results[$case] = ['status' => $response->status(), 'body' => json_decode((string) $response->getContent())];
        };

        $record('tanpa_token', $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/internal/v1/finance-postings'));
        $record('token_bukan_bentuk_id_titik_rahasia', $this->pull('bukan-token'));
        $record('rahasia_salah', $this->pull($id.'.salah'));
        $record('klien_dicabut', $this->pull($revoked));
        $record('ip_di_luar_allowlist', $this->pull($elsewhere));
        $record('ip_diperiksa_sebelum_scope', $this->pull($elsewhereAckOnly));
        $record('scope_kurang', $this->pull($ackOnly));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $record('ip_di_dalam_allowlist_tetap_butuh_scope', $this->pull($elsewhereAckOnly));
        $this->assertSame(200, $this->pull($elsewhere)->status());
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);

        $sandbox = Environment::create([
            'tenant_id' => $this->membership->tenant_id, 'kind' => 'sandbox', 'name' => 'Uji sandbox',
            'slug' => 'uji-sandbox', 'database_name' => null, 'hosting' => Environment::HOSTING_PROVIDER,
            'status' => 'active', 'outbound_allowed' => false,
        ]);
        $this->app->instance(ActiveEnvironment::KEY, $sandbox->id);
        $record('salinan_sandbox', $this->pull($token));
        $record('salinan_sandbox_scope_kurang_lebih_dulu', $this->pull($ackOnly));

        $this->assertMatchesSnapshot('pull-401-403-503.json', $this->canonicalJson((string) json_encode($results)));
        // Feed mati bukan kesalahan: posting manual tidak disajikan, jawabannya tetap 200.
        $this->app->forgetInstance(ActiveEnvironment::KEY);
        FinancePostingSetting::query()->whereKey($this->legalEntity->id)->update(['enabled' => false]);
        $this->pull($token)->assertOk();
    }

    public function test_rate_limit_is_per_client_shared_by_pull_and_ack_and_answers_429(): void
    {
        config(['coreerp.integration_api_rate_limit' => 3]);
        $this->publish($this->acquisition());
        $token = $this->pullToken();
        $other = $this->pullToken();

        $first = $this->pull($token)->assertOk();
        $this->assertSame('3', $first->headers->get('X-RateLimit-Limit'));
        $this->assertSame('2', $first->headers->get('X-RateLimit-Remaining'));
        $this->ack($token, 'TIDAK-ADA', ['status' => 'posted', 'external_reference' => 'JV-1'])->assertNotFound();
        $this->pull($token)->assertOk();

        $limited = $this->pull($token)->assertStatus(429);
        $this->assertCocokSkema($limited->json(), 'Error');
        $this->assertSame(['message' => 'Too Many Attempts.'], $limited->json());
        $this->assertSame('60', $limited->headers->get('Retry-After'));
        $this->assertSame('3', $limited->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $limited->headers->get('X-RateLimit-Remaining'));
        $this->ack($token, 'AST-ACQ-0001', ['status' => 'posted', 'external_reference' => 'JV-1'])->assertStatus(429);

        // Klien lain dari alamat yang sama tidak ikut terbatas.
        $this->pull($other)->assertOk();
        // Jatahnya pulih sesudah satu menit.
        $this->travel(61)->seconds();
        $this->pull($token)->assertOk();
    }

    /**
     * Lima posting `pending` (empat jenis, dua modul) dan empat yang tidak pernah disajikan:
     * `held`, `manual`, `posted`, dan `rejected`.
     */
    private function publishServedAndUnservedPostings(): void
    {
        $this->publish($this->acquisition('AST-ACQ-0001', '2026-09-28'));
        $this->travel(1)->seconds();
        $this->publish($this->depreciation('AST-DEP-2026-09', '2026-09-30'));
        $this->travel(1)->seconds();
        $this->publish($this->acquisition('AST-ADJ-0001', '2026-09-29', '10000000.00', [
            'posting_type' => 'asset.acquisition_adjustment', 'adjusts_posting_id' => 'AST-ACQ-0001', 'settlement_mode' => null,
        ]));
        $this->travel(1)->seconds();
        // Tanggal sama dengan AST-ADJ-0001, terbit sesudahnya: urutan kedua adalah jam terbit.
        $this->publish($this->acquisition('AST-ACQ-0002', '2026-09-29', '75000000.00'));
        $this->travel(1)->seconds();
        $this->publish($this->acquisition('KSR-0001', '2026-09-27', '150000.00', ['posting_type' => 'cashier.receipt', 'details' => []]));
        $this->travel(1)->seconds();

        $held = $this->acquisition('AST-ACQ-HELD');
        $held['lines'][0]['account_id'] = null;
        $this->assertSame('held', $this->publish($held)['status']);
        $this->assertSame('manual', $this->publish($this->acquisition('AST-ACQ-LAMA', '2026-08-20'))['status']);
        $this->publish($this->acquisition('AST-ACQ-POSTED', '2026-09-02'));
        $this->publish($this->acquisition('AST-ACQ-REJECTED', '2026-09-03'));
        $acker = $this->pullToken(prefixes: [], name: 'Ack awal');
        $this->ack($acker, 'AST-ACQ-POSTED', ['status' => 'posted', 'external_reference' => 'JV-0001'])->assertOk();
        $this->ack($acker, 'AST-ACQ-REJECTED', ['status' => 'rejected', 'reason_code' => 'PERIOD_CLOSED', 'reason' => 'Tutup'])->assertOk();
    }
}
