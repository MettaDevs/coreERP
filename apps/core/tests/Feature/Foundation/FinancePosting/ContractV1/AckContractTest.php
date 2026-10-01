<?php

namespace Tests\Feature\Foundation\FinancePosting\ContractV1;

use App\Foundation\FinancePosting\Models\FinancePosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CocokDenganKontrak;
use Tests\TestCase;

/**
 * Mengunci `POST /api/internal/v1/finance-postings/{posting_id}/ack` (kontrak feed posting finance v1).
 *
 * Seluruh percakapan ack — permintaan, kode HTTP, dan body jawaban — dikunci sebagai satu snapshot
 * berurutan, karena idempotensi dan konflik hanya bermakna sebagai urutan.
 *
 * Perbedaan dengan YAML yang ditemukan saat test ini ditulis:
 *
 * - Posting dicari **sebelum** body divalidasi. Posting yang tidak dikenal atau di luar prefix klien
 *   dijawab 404 walaupun body-nya juga salah; kontrak tidak menyebut urutan ini.
 * - Ack `rejected` ulang dengan kode sama tetapi teks `reason` berbeda dijawab 200 dan teks yang
 *   tersimpan tidak diganti. Kontrak hanya menyebut "kode alasan sama".
 * - Jawaban 409 membawa `message` berbahasa Indonesia yang menyebut `posting_id` dan statusnya;
 *   kontrak hanya mewajibkan `message` bertipe string.
 */
#[Group('kontrak-feed-finance-v1')]
class AckContractTest extends TestCase
{
    use CocokDenganKontrak, PreparesFinanceFeed, RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $transcript = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareFinanceFeed();
        config(['app.debug' => false]);
    }

    public function test_ack_conversation_matches_the_v1_snapshot(): void
    {
        $this->publish($this->acquisition('AST-ACQ-P'));
        $this->publish($this->acquisition('AST-ACQ-R'));
        $held = $this->acquisition('AST-ACQ-HELD');
        $held['lines'][0]['account_id'] = null;
        $this->assertSame('held', $this->publish($held)['status']);
        $this->assertSame('manual', $this->publish($this->acquisition('AST-ACQ-LAMA', '2026-08-20'))['status']);
        $this->publish($this->acquisition('KSR-0001', overrides: ['posting_type' => 'cashier.receipt']));
        $token = $this->pullToken(prefixes: ['asset.']);

        // posted
        $this->step('posted_pertama', $token, 'AST-ACQ-P', ['status' => 'posted', 'external_reference' => 'JV-2026-0001'], 200);
        $this->travel(5)->minutes();
        $this->step('posted_diulang_sama', $token, 'AST-ACQ-P', ['status' => 'posted', 'external_reference' => 'JV-2026-0001'], 200);
        $this->step('posted_diulang_referensi_dirapikan', $token, 'AST-ACQ-P', ['status' => 'posted', 'external_reference' => '  JV-2026-0001  '], 200);
        $this->step('posted_referensi_lain', $token, 'AST-ACQ-P', ['status' => 'posted', 'external_reference' => 'JV-2026-0002'], 409);
        $this->step('rejected_atas_posted', $token, 'AST-ACQ-P', ['status' => 'rejected', 'reason_code' => 'PERIOD_CLOSED', 'reason' => 'Tutup'], 409);

        // rejected
        $this->step('rejected_pertama', $token, 'AST-ACQ-R', ['status' => 'rejected', 'reason_code' => 'UNKNOWN_ACCOUNT', 'reason' => 'Akun 1452 tidak dikenal', 'external_reference' => 'diabaikan'], 200);
        $this->step('rejected_kode_sama_teks_lain', $token, 'AST-ACQ-R', ['status' => 'rejected', 'reason_code' => 'UNKNOWN_ACCOUNT', 'reason' => 'Teks lain'], 200);
        $this->step('rejected_kode_lain', $token, 'AST-ACQ-R', ['status' => 'rejected', 'reason_code' => 'INVALID', 'reason' => 'Lain'], 409);
        $this->step('posted_atas_rejected', $token, 'AST-ACQ-R', ['status' => 'posted', 'external_reference' => 'JV-9'], 409);

        // Posting yang tidak pernah disajikan.
        $this->step('ack_atas_held', $token, 'AST-ACQ-HELD', ['status' => 'posted', 'external_reference' => 'JV-1'], 409);
        $this->step('ack_atas_manual', $token, 'AST-ACQ-LAMA', ['status' => 'rejected', 'reason_code' => 'INVALID', 'reason' => 'x'], 409);

        // Tidak ditemukan: tidak ada, di luar prefix klien, dan dicari sebelum body divalidasi.
        $this->step('tidak_dikenal', $token, 'TIDAK-ADA', ['status' => 'posted', 'external_reference' => 'JV-1'], 404);
        $this->step('di_luar_prefix', $token, 'KSR-0001', ['status' => 'posted', 'external_reference' => 'JV-1'], 404);
        $this->step('tidak_dikenal_body_salah', $token, 'TIDAK-ADA', [], 404);

        // Body tidak sah.
        $this->step('tanpa_status', $token, 'AST-ACQ-P', [], 422);
        $this->step('status_lain', $token, 'AST-ACQ-P', ['status' => 'pending'], 422);
        $this->step('posted_tanpa_referensi', $token, 'AST-ACQ-P', ['status' => 'posted'], 422);
        $this->step('posted_referensi_121', $token, 'AST-ACQ-P', ['status' => 'posted', 'external_reference' => str_repeat('J', 121)], 422);
        $this->step('rejected_tanpa_kode_dan_alasan', $token, 'AST-ACQ-P', ['status' => 'rejected'], 422);
        $this->step('rejected_kode_tidak_dikenal', $token, 'AST-ACQ-P', ['status' => 'rejected', 'reason_code' => 'LUPA', 'reason' => 'x'], 422);
        $this->step('rejected_alasan_1001', $token, 'AST-ACQ-P', ['status' => 'rejected', 'reason_code' => 'INVALID', 'reason' => str_repeat('x', 1001)], 422);

        $this->assertMatchesSnapshot('ack.json', $this->canonicalJson((string) json_encode($this->transcript)));

        $this->assertSame(
            [
                'AST-ACQ-HELD' => ['held', null, null, null],
                'AST-ACQ-LAMA' => ['manual', null, null, null],
                'AST-ACQ-P' => ['posted', 'JV-2026-0001', null, null],
                'AST-ACQ-R' => ['rejected', null, 'UNKNOWN_ACCOUNT', 'Akun 1452 tidak dikenal'],
                'KSR-0001' => ['pending', null, null, null],
            ],
            FinancePosting::query()->orderBy('posting_id')->get()->mapWithKeys(fn (FinancePosting $posting): array => [
                $posting->posting_id => [$posting->status, $posting->external_reference, $posting->reason_code, $posting->reason],
            ])->all(),
        );
        // Ack yang diulang tidak mencatat peristiwa kedua.
        $this->assertSame(
            ['acknowledged_posted' => 1, 'acknowledged_rejected' => 1],
            DB::table('finance_posting_events')->whereIn('event', ['acknowledged_posted', 'acknowledged_rejected'])
                ->selectRaw('event, count(*) as n')->groupBy('event')->orderBy('event')->pluck('n', 'event')->map(fn ($n): int => (int) $n)->all(),
        );
    }

    public function test_every_rejection_code_of_the_contract_is_accepted(): void
    {
        $token = $this->pullToken();
        $codes = ['PERIOD_CLOSED', 'UNKNOWN_ACCOUNT', 'UNKNOWN_DIMENSION', 'UNKNOWN_VENDOR', 'UNKNOWN_LEGAL_ENTITY', 'INVALID'];
        foreach ($codes as $i => $code) {
            $this->publish($this->acquisition('AST-ACQ-'.$i));
            $this->ack($token, 'AST-ACQ-'.$i, ['status' => 'rejected', 'reason_code' => $code, 'reason' => 'Uji'])
                ->assertOk()->assertJsonPath('data.reason_code', $code);
        }
    }

    public function test_ack_needs_the_ack_scope_and_another_client_may_acknowledge(): void
    {
        $this->publish($this->acquisition());
        $readOnly = $this->pullToken(['finance-postings.read']);
        $ackOnly = $this->pullToken(['finance-postings.ack']);

        $this->ack($readOnly, 'AST-ACQ-0001', ['status' => 'posted', 'external_reference' => 'JV-1'])
            ->assertStatus(403)->assertExactJson(['message' => 'Klien integrasi ini tidak punya izin finance-postings.ack.']);
        // Klien yang meng-ack tidak harus klien yang melakukan pull.
        $this->ack($ackOnly, 'AST-ACQ-0001', ['status' => 'posted', 'external_reference' => 'JV-1'])->assertOk();
    }

    /** @param  array<string, mixed>  $body */
    private function step(string $name, string $token, string $postingId, array $body, int $expectedStatus): void
    {
        $response = $this->ack($token, $postingId, $body);
        $this->assertSame($expectedStatus, $response->status(), $name.': '.$response->getContent());
        $this->assertResponseMatchesContract($response);
        $this->transcript[] = [
            'langkah' => $name,
            'posting_id' => $postingId,
            'request' => (object) $body,
            'status' => $response->status(),
            'body' => json_decode((string) $response->getContent()),
        ];
    }

    private function assertResponseMatchesContract(TestResponse $response): void
    {
        match ($response->status()) {
            200 => $this->assertCocokSkema($response->json('data'), 'FinancePostingAckResult'),
            409 => [$this->assertIsString($response->json('message')), $this->assertCocokSkema($response->json('data'), 'FinancePostingAckResult')],
            422 => $this->assertCocokSkema($response->json(), 'ValidationError'),
            default => $this->assertCocokSkema($response->json(), 'Error'),
        };
    }
}
