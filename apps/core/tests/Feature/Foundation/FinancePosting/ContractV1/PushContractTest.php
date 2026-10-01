<?php

namespace Tests\Feature\Foundation\FinancePosting\ContractV1;

use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Foundation\FinancePosting\Models\FinancePostingDelivery;
use App\Foundation\FinancePosting\Support\PostingPusher;
use App\Platform\Integration\Support\PushDestination;
use App\Platform\Integration\Support\SignedPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CocokDenganKontrak;
use Tests\TestCase;

/**
 * Mengunci kiriman push (webhook `financePosting`, kontrak feed posting finance v1).
 *
 * Kiriman ditangkap `Http::fake` dengan penangan yang **mencatat sendiri** setiap permintaan.
 * `Http::assertNothingSent()` tidak mencatat penangan yang melempar, jadi "tidak ada yang dikirim"
 * dibuktikan dari catatan itu, bukan dari asersi bawaan.
 *
 * Perbedaan dengan YAML yang ditemukan saat test ini ditulis:
 *
 * - Kontrak menyebut body push "sama persis" dengan satu elemen `data` pada pull. Nilainya memang
 *   sama, tetapi byte-nya tidak: push dikodekan dengan `JSON_UNESCAPED_SLASHES` dan
 *   `JSON_UNESCAPED_UNICODE` (`/` dan `·` apa adanya), sedangkan pull memakai pengodean bawaan
 *   Laravel (`\/` dan `·`). Signature dihitung atas byte push. Snapshot body push mengunci
 *   byte-nya; kesamaan dengan pull dikunci sebagai nilai.
 * - Kontrak webhook hanya menulis jawaban `200`. Kode menerima seluruh 2xx sebagai terkirim,
 *   termasuk `204` tanpa body, dan body ack hanya dibaca dari jawaban 2xx.
 * - Body jawaban 2xx yang bukan ack sah (misalnya `{"status": "posted"}` tanpa
 *   `external_reference`) diam-diam dianggap "terkirim tanpa ack"; tidak ada kesalahan yang
 *   dilaporkan ke pembaca.
 * - Kontrak tidak menyebut batas waktu: sambungan 3 detik, jawaban 10 detik. Habisnya waktu
 *   diperlakukan seperti tidak terjangkau (dicoba lagi).
 * - Header `Accept: application/json` dan `Content-Type: application/json` ikut terkirim tanpa
 *   disebut kontrak.
 */
#[Group('kontrak-feed-finance-v1')]
class PushContractTest extends TestCase
{
    use CocokDenganKontrak, PreparesFinanceFeed, RefreshDatabase;

    /** @var list<array{request: HttpRequest, options: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareFinanceFeed();
    }

    public function test_push_request_matches_the_v1_snapshot_and_the_signature_is_recomputable(): void
    {
        $client = $this->pushClient('https://finance.example.test/hook');
        $this->publish($this->acquisition('AST-ACQ-0001', '2026-09-28'));
        $this->travel(1)->seconds();
        $this->publish($this->depreciation('AST-DEP-2026-09', '2026-09-30'));
        $this->travel(1)->minutes();
        $this->recordPushes(fn (): mixed => Http::response('', 200));

        $this->assertSame(2, app(PostingPusher::class)->run()['sent']);

        $this->assertCount(2, $this->sent);
        $pull = json_decode((string) $this->pull($this->pullToken())->assertOk()->getContent(), false, 512, JSON_THROW_ON_ERROR);
        foreach ($this->sent as $i => ['request' => $request, 'options' => $options]) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://finance.example.test/hook', $request->url());
            $this->assertSame(['application/json'], $request->header('Content-Type'));
            $this->assertSame(['application/json'], $request->header('Accept'));
            $this->assertSame(
                ['X-CoreERP-Client-Id', 'X-CoreERP-Event-Signature', 'X-CoreERP-Event-Timestamp'],
                collect(array_keys($request->headers()))->filter(fn (string $name): bool => str_starts_with(strtolower($name), 'x-coreerp'))->sort()->values()->all(),
            );
            $this->assertSame([$client['id']], $request->header('X-CoreERP-Client-Id'));

            // Timestamp: detik Unix saat dikirim, sebagai teks angka.
            $timestamp = $request->header('X-CoreERP-Event-Timestamp')[0];
            $this->assertSame((string) now()->getTimestamp(), $timestamp);
            $this->assertMatchesRegularExpression('/^\d{10}$/', $timestamp);

            // Signature: HMAC-SHA256 heksadesimal huruf kecil atas `<timestamp>.<raw body>`, key = signing secret.
            $signature = $request->header('X-CoreERP-Event-Signature')[0];
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
            $this->assertSame(hash_hmac('sha256', $timestamp.'.'.$request->body(), $client['secret']), $signature);
            $this->assertNotSame(hash_hmac('sha256', $request->body(), $client['secret']), $signature);

            // Body: nilai sama dengan elemen pull pada urutan yang sama, dan cocok dengan skema.
            $this->assertSame($this->canonicalJson((string) json_encode($pull->data[$i])), $this->canonicalJson($request->body()));
            $this->assertCocokSkema(json_decode($request->body(), true), 'FinancePosting');

            // Redirect tidak diikuti; batas waktu sambungan 3 detik dan jawaban 10 detik.
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertArrayNotHasKey('curl', $options);
        }

        $this->assertSame(['AST-ACQ-0001', 'AST-DEP-2026-09'], array_map(fn (array $one): string => $one['request']->data()['posting_id'], $this->sent));
        $this->assertMatchesSnapshot('push-body-asset-acquisition.json', strtr($this->sent[0]['request']->body(), $this->placeholders())."\n");
        $this->assertMatchesSnapshot('push-body-asset-depreciation.json', strtr($this->sent[1]['request']->body(), $this->placeholders())."\n");
    }

    public function test_signature_matches_an_independent_hmac_of_a_known_body(): void
    {
        // Contoh yang dapat diulang tim finance di bahasa apa pun.
        $this->assertSame(
            hash_hmac('sha256', '1790614204.{"posting_id":"AST-ACQ-0001"}', 'rahasia-uji'),
            SignedPush::sign('rahasia-uji', '{"posting_id":"AST-ACQ-0001"}', 1790614204)['signature'],
        );
        $this->assertSame('1790614204', SignedPush::sign('rahasia-uji', '{}', 1790614204)['timestamp']);
    }

    public function test_reader_responses_are_treated_as_v1_says(): void
    {
        $cases = [
            'a200_ack_posted' => fn (): mixed => Http::response(['status' => 'posted', 'external_reference' => 'JV-PUSH-1'], 200),
            'a200_ack_rejected' => fn (): mixed => Http::response(['status' => 'rejected', 'reason_code' => 'PERIOD_CLOSED', 'reason' => 'Tutup'], 200),
            'a200_kosong' => fn (): mixed => Http::response('', 200),
            'a200_ack_tidak_sah' => fn (): mixed => Http::response(['status' => 'posted'], 200),
            'a200_bukan_json' => fn (): mixed => Http::response('OK', 200),
            'a201' => fn (): mixed => Http::response('', 201),
            'a202_ack_posted' => fn (): mixed => Http::response(['status' => 'posted', 'external_reference' => 'JV-PUSH-2'], 202),
            'a204' => fn (): mixed => Http::response('', 204),
            'a301' => fn (): mixed => Http::response('', 301, ['Location' => 'https://10.0.0.5/']),
            'a302' => fn (): mixed => Http::response('', 302, ['Location' => 'https://10.0.0.5/']),
            'a307' => fn (): mixed => Http::response('', 307, ['Location' => 'https://10.0.0.5/']),
            'a400' => fn (): mixed => Http::response(['message' => 'salah'], 400),
            'a401' => fn (): mixed => Http::response('', 401),
            'a403' => fn (): mixed => Http::response('', 403),
            'a404' => fn (): mixed => Http::response('', 404),
            'a409' => fn (): mixed => Http::response('', 409),
            'a410' => fn (): mixed => Http::response('', 410),
            'a422' => fn (): mixed => Http::response(['message' => 'akun tidak dikenal'], 422),
            'a408' => fn (): mixed => Http::response('', 408),
            'a429' => fn (): mixed => Http::response('', 429),
            'a500' => fn (): mixed => Http::response('', 500),
            'a502' => fn (): mixed => Http::response('', 502),
            'a503' => fn (): mixed => Http::response('', 503),
            'a504' => fn (): mixed => Http::response('', 504),
            'putus' => fn (): mixed => Http::failedConnection('Connection refused'),
        ];
        $clients = [];
        foreach (array_keys($cases) as $i => $label) {
            if ($i === 15) {
                // Layar admin membatasi 20 klien baru per menit.
                $this->travel(1)->minutes();
            }
            $clients[$label] = $this->pushClient('https://finance.example.test/'.$label, [$label.'.'], $label);
            $this->publish($this->acquisition($label.'-1', overrides: ['posting_type' => $label.'.uji']));
        }
        $this->recordPushes(function (HttpRequest $request) use ($cases): mixed {
            return $cases[basename((string) parse_url($request->url(), PHP_URL_PATH))]();
        });

        $summary = app(PostingPusher::class)->run();

        $delivered = ['delivered', 'pending', null];
        $failed = ['failed', 'pending', null];
        $retrying = ['retrying', 'pending', 1];
        $expected = [
            'a200_ack_posted' => [200, 'delivered', 'posted', null, 'JV-PUSH-1'],
            'a200_ack_rejected' => [200, 'delivered', 'rejected', null, 'PERIOD_CLOSED'],
            'a200_kosong' => [200, ...$delivered, null],
            'a200_ack_tidak_sah' => [200, ...$delivered, null],
            'a200_bukan_json' => [200, ...$delivered, null],
            'a201' => [201, ...$delivered, null],
            'a202_ack_posted' => [202, 'delivered', 'posted', null, 'JV-PUSH-2'],
            'a204' => [204, ...$delivered, null],
            'a301' => [301, ...$failed, null],
            'a302' => [302, ...$failed, null],
            'a307' => [307, ...$failed, null],
            'a400' => [400, ...$failed, null],
            'a401' => [401, ...$failed, null],
            'a403' => [403, ...$failed, null],
            'a404' => [404, ...$failed, null],
            'a409' => [409, ...$failed, null],
            'a410' => [410, ...$failed, null],
            'a422' => [422, ...$failed, null],
            'a408' => [408, ...$retrying, null],
            'a429' => [429, ...$retrying, null],
            'a500' => [500, ...$retrying, null],
            'a502' => [502, ...$retrying, null],
            'a503' => [503, ...$retrying, null],
            'a504' => [504, ...$retrying, null],
            'putus' => [null, ...$retrying, null],
        ];
        $actual = [];
        foreach (array_keys($cases) as $label) {
            $posting = FinancePosting::query()->where('posting_id', $label.'-1')->firstOrFail();
            $delivery = FinancePostingDelivery::query()->where('finance_posting_id', $posting->id)->firstOrFail();
            $actual[$label] = [
                $delivery->last_status_code,
                $delivery->status,
                $posting->status,
                $delivery->next_attempt_at === null ? null : (int) now()->diffInMinutes($delivery->next_attempt_at),
                $posting->external_reference ?? $posting->reason_code,
            ];
            $this->assertSame(1, $delivery->attempts, $label);
        }
        $this->assertSame($expected, $actual);
        $this->assertSame(['clients' => 25, 'sent' => 8, 'retrying' => 7, 'failed' => 10, 'skipped' => null], $summary);
        // Setiap posting dikirim tepat sekali; redirect tidak diikuti.
        $this->assertCount(25, $this->sent);

        // Ack di jawaban push tercatat atas nama klien yang menjawab.
        $this->assertSame($clients['a200_ack_posted']['id'], FinancePosting::query()->where('posting_id', 'a200_ack_posted-1')->value('acknowledged_by_client_id'));
        $this->assertSame(
            'Ditolak pembaca dengan HTTP 422. {"message":"akun tidak dikenal"}',
            FinancePostingDelivery::query()->where('last_status_code', 422)->value('last_error'),
        );

        // Putaran berikutnya: yang terkirim dan yang gagal tidak dikirim lagi; yang menunggu jeda belum.
        app(PostingPusher::class)->run();
        $this->assertCount(25, $this->sent);
        $this->travel(1)->minutes();
        app(PostingPusher::class)->run();
        $this->assertCount(32, $this->sent);
    }

    public function test_retry_delays_double_up_to_60_minutes_and_stop_after_24_hours(): void
    {
        $this->pushClient();
        $this->publish($this->acquisition('AST-ACQ-A', '2026-09-10'));
        $this->publish($this->acquisition('AST-ACQ-B', '2026-09-11'));
        $this->recordPushes(fn (): mixed => Http::response('sibuk', 503));
        $first = now()->toImmutable();

        $delays = [];
        for ($i = 0; $i < 9; $i++) {
            app(PostingPusher::class)->run();
            $delivery = FinancePostingDelivery::query()->sole();
            $delays[] = (int) now()->diffInMinutes($delivery->next_attempt_at);
            // Belum waktunya: tidak ada kiriman.
            $count = count($this->sent);
            app(PostingPusher::class)->run();
            $this->assertCount($count, $this->sent);
            $this->travelTo($delivery->next_attempt_at);
        }

        $this->assertSame([1, 2, 4, 8, 16, 32, 60, 60, 60], $delays);
        // Selama A menunggu jeda, B tidak pernah dikirim ke klien ini.
        $this->assertSame(['AST-ACQ-A'], array_values(array_unique(array_map(fn (array $one): string => $one['request']->data()['posting_id'], $this->sent))));

        $this->travelTo($first->addHours(24)->addMinute());
        app(PostingPusher::class)->run();

        $a = FinancePostingDelivery::query()->whereHas('posting', fn ($query) => $query->where('posting_id', 'AST-ACQ-A'))->sole();
        $this->assertSame(['failed', 503, 10, null], [$a->status, $a->last_status_code, $a->attempts, $a->next_attempt_at]);
        $this->assertSame('Batas percobaan 24 jam habis. Terakhir: sibuk', $a->last_error);
        $this->assertSame('pending', FinancePosting::query()->where('posting_id', 'AST-ACQ-A')->value('status'));
        // Yang gagal tidak menahan antrean: B dikirim pada putaran yang sama.
        $this->assertSame('AST-ACQ-B', end($this->sent)['request']->data()['posting_id']);
    }

    public function test_push_url_must_be_https_and_saas_refuses_private_networks_at_send_time(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', [
            'name' => 'Tanpa TLS', 'delivery_mode' => 'push', 'push_url' => 'http://finance.example.test/hook',
            'scopes' => ['finance-postings.read'], 'posting_type_prefixes' => [], 'allowed_ips' => [],
        ])->assertStatus(422)->assertJsonPath('errors.push_url', ['URL tujuan harus alamat https:// yang lengkap.']);
        $this->assertSame('URL tujuan harus alamat https:// yang lengkap.', (new PushDestination)->reject('http://finance.example.test/hook'));

        $addresses = ['203.0.113.20'];
        $this->app->instance(PushDestination::class, new PushDestination(function () use (&$addresses): array {
            return $addresses;
        }));
        config(['coreerp.base_domain' => 'erp.example.test']);
        $this->pushClient('https://finance.example.test/hook');
        $this->recordPushes(fn (): mixed => Http::response('', 200));

        // DNS berganti ke jaringan privat sesudah klien disimpan: ditolak sebelum mengirim.
        $addresses = ['10.0.0.5'];
        $this->publish($this->acquisition('AST-ACQ-PRIVAT'));
        app(PostingPusher::class)->run();
        $this->assertSame([], $this->sent);
        $privat = FinancePostingDelivery::query()->sole();
        $this->assertSame(['failed', null], [$privat->status, $privat->last_status_code]);
        $this->assertSame('URL tujuan menunjuk jaringan privat. Dari layanan SaaS, aplikasi finance harus dapat dijangkau lewat alamat publik.', $privat->last_error);

        // Alamat publik: dikirim ke alamat yang baru saja diperiksa.
        $addresses = ['203.0.113.20'];
        $this->publish($this->acquisition('AST-ACQ-PUBLIK', '2026-09-29'));
        app(PostingPusher::class)->run();
        $this->assertCount(1, $this->sent);
        $this->assertSame(['finance.example.test:443:203.0.113.20'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);

        // On-prem: jaringan privat boleh, dan resolusi DNS diserahkan ke sistem.
        config(['coreerp.base_domain' => null]);
        $addresses = ['10.0.0.5'];
        $this->publish($this->acquisition('AST-ACQ-LAN', '2026-09-30'));
        app(PostingPusher::class)->run();
        $this->assertCount(2, $this->sent);
        $this->assertArrayNotHasKey('curl', $this->sent[1]['options']);
    }

    /** @param  callable(HttpRequest, array<string, mixed>): mixed  $answer */
    private function recordPushes(callable $answer): void
    {
        Http::fake(function (HttpRequest $request, array $options) use ($answer): mixed {
            $this->sent[] = ['request' => $request, 'options' => $options];

            return $answer($request, $options);
        });
    }
}
