<?php

declare(strict_types=1);

namespace App\Foundation\FinancePosting\Support;

use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Foundation\FinancePosting\Models\FinancePostingDelivery;
use App\Foundation\FinancePosting\Models\FinancePostingEvent;
use App\Platform\ChangeLog\Support\AuditActor;
use App\Platform\Environment\Support\ActiveEnvironment;
use App\Platform\Integration\Models\IntegrationClient;
use App\Platform\Integration\Support\IntegrationClientAccounts;
use App\Platform\Integration\Support\SignedPush;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mengirim posting `pending` ke klien mode `push` (TODO 6.10, K-03).
 *
 * - Badan = payload yang sama persis dengan yang disajikan tarikan, bertanda tangan `SignedPush`.
 * - 2xx dihitung terkirim. Bila badan jawabannya ack yang sah, ack itu diterapkan seperti
 *   `POST …/ack`; bila bukan, posting tetap `pending` sampai pembaca meng-ack lewat API.
 * - 408, 429, 5xx, atau tidak terjangkau: dicoba lagi dengan jeda 1, 2, 4, … sampai 60 menit,
 *   selama `coreerp.finance_push_retry_hours` sejak percobaan pertama. Sesudahnya gagal.
 * - 4xx lain, atau tujuan yang ditolak aturan `PushDestination`: gagal, tampil di layar pantau, dan
 *   tidak dikirim lagi otomatis.
 *
 * Urutan kirim per klien sama dengan urutan tarikan. Posting yang sedang menunggu jeda menahan
 * posting sesudahnya **untuk klien itu saja**; klien lain tidak ikut tertahan. Posting yang gagal
 * permanen tidak menahan apa pun — ia sudah keluar dari antrean dan menunggu tangan manusia.
 */
final class PostingPusher
{
    public const SENT = 'sent';

    public const RETRYING = 'retrying';

    public const FAILED = 'failed';

    public function __construct(
        private readonly SignedPush $push,
        private readonly PostingAcknowledger $ack,
        private readonly ActiveEnvironment $environment,
        private readonly IntegrationClientAccounts $accounts,
    ) {}

    /** @return array{clients: int, sent: int, retrying: int, failed: int, skipped: ?string} */
    public function run(int $limit = 100): array
    {
        // Salinan sandbox tidak mengirim apa pun, dan tidak menandai apa pun: produksi yang
        // disalin tetap memegang antreannya sendiri (TODO 6.10.5).
        if (! $this->environment->outboundAllowed()) {
            return ['clients' => 0, 'sent' => 0, 'retrying' => 0, 'failed' => 0, 'skipped' => $this->environment->refusalReason()];
        }

        $clientCount = 0;
        $result = [];
        IntegrationClient::query()
            ->where('delivery_mode', IntegrationClient::PUSH)
            ->where('status', IntegrationClient::ACTIVE)
            ->whereNotNull('push_url')
            ->orderBy('id')
            ->each(function (IntegrationClient $client) use ($limit, &$clientCount, &$result): void {
                $clientCount++;
                array_push($result, ...$this->pushFor($client, $limit));
            });
        $count = array_count_values($result);

        return [
            'clients' => $clientCount,
            'sent' => $count[self::SENT] ?? 0,
            'retrying' => $count[self::RETRYING] ?? 0,
            'failed' => $count[self::FAILED] ?? 0,
            'skipped' => null,
        ];
    }

    /** @return list<string> */
    private function pushFor(IntegrationClient $client, int $limit): array
    {
        $query = FinancePosting::query()
            ->where('tenant_id', $client->tenant_id)
            ->where('status', FinancePosting::PENDING)
            ->whereDoesntHave('deliveries', fn ($inner) => $inner
                ->where('integration_client_id', $client->id)
                ->whereIn('status', [FinancePostingDelivery::DELIVERED, FinancePostingDelivery::FAILED]));
        FinancePosting::restrictToClient($query, $client);
        $postings = $query->orderBy('posting_date')->orderBy('published_at')->orderBy('id')->limit($limit)->get();

        $result = [];
        foreach ($postings as $posting) {
            $delivery = FinancePostingDelivery::query()->firstOrNew(
                ['finance_posting_id' => $posting->id, 'integration_client_id' => $client->id],
                ['tenant_id' => $client->tenant_id, 'status' => FinancePostingDelivery::RETRYING, 'attempts' => 0],
            );
            if ($delivery->next_attempt_at !== null && $delivery->next_attempt_at->isFuture()) {
                break;
            }

            $result[] = $item = $this->push($client, $posting, $delivery);
            if ($item === self::RETRYING) {
                break;
            }
        }

        return $result;
    }

    private function push(IntegrationClient $client, FinancePosting $posting, FinancePostingDelivery $delivery): string
    {
        $now = now();
        $delivery->fill([
            'attempts' => $delivery->attempts + 1,
            'first_attempt_at' => $delivery->first_attempt_at ?? $now,
            'last_attempt_at' => $now,
        ]);
        $body = json_encode($posting->servedPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $answer = $this->push->send($client, $body);
        } catch (ConnectionException $failure) {
            return $this->retry($client, $posting, $delivery, null, 'Tidak terjangkau: '.$failure->getMessage());
        } catch (RuntimeException $failure) {
            return $this->fail($client, $posting, $delivery, null, $failure->getMessage());
        }

        $code = $answer->status();
        if ($answer->successful()) {
            $delivery->fill([
                'status' => FinancePostingDelivery::DELIVERED,
                'delivered_at' => $now,
                'next_attempt_at' => null,
                'last_status_code' => $code,
                'last_error' => null,
            ])->save();
            FinancePostingEvent::record($posting, 'push_delivered', $posting->status, $posting->status, $client->id, data: [
                'attempts' => $delivery->attempts, 'status_code' => $code,
            ]);

            // Ack di jawaban push keputusan klien, bukan sistem: dicatat atas nama akun aplikasinya,
            // sama dengan `POST …/ack` lewat API.
            $ack = PostingAcknowledger::fromPushResponse($answer->json());
            if ($ack !== null) {
                AuditActor::runAs(
                    $client->user_id ?? $this->accounts->ensure($client),
                    fn () => $this->ack->acknowledge($posting->id, $client, $ack),
                );
            }

            return self::SENT;
        }

        $excerpt = Str::limit(trim($answer->body()), 300);
        if ($code === 408 || $code === 429 || $code >= 500) {
            return $this->retry($client, $posting, $delivery, $code, $excerpt);
        }

        return $this->fail($client, $posting, $delivery, $code, sprintf('Ditolak pembaca dengan HTTP %d. %s', $code, $excerpt));
    }

    private function retry(IntegrationClient $client, FinancePosting $posting, FinancePostingDelivery $delivery, ?int $code, string $message): string
    {
        $hourLimit = (int) config('coreerp.finance_push_retry_hours', 24);
        if ($delivery->first_attempt_at !== null && $delivery->first_attempt_at->copy()->addHours($hourLimit)->isPast()) {
            return $this->fail($client, $posting, $delivery, $code, sprintf('Batas percobaan %d jam habis. Terakhir: %s', $hourLimit, $message));
        }

        $delay = min(60, 2 ** min($delivery->attempts - 1, 6));
        $delivery->fill([
            'status' => FinancePostingDelivery::RETRYING,
            'next_attempt_at' => now()->addMinutes($delay),
            'last_status_code' => $code,
            'last_error' => Str::limit($message, 490),
        ])->save();
        FinancePostingEvent::record($posting, 'push_retrying', $posting->status, $posting->status, $client->id, data: [
            'attempts' => $delivery->attempts, 'status_code' => $code, 'retry_in_minutes' => $delay,
        ]);

        return self::RETRYING;
    }

    private function fail(IntegrationClient $client, FinancePosting $posting, FinancePostingDelivery $delivery, ?int $code, string $message): string
    {
        $delivery->fill([
            'status' => FinancePostingDelivery::FAILED,
            'next_attempt_at' => null,
            'last_status_code' => $code,
            'last_error' => Str::limit($message, 490),
        ])->save();
        FinancePostingEvent::record($posting, 'push_failed', $posting->status, $posting->status, $client->id, data: [
            'attempts' => $delivery->attempts, 'status_code' => $code,
        ]);

        return self::FAILED;
    }
}
