<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\FinancePosting;
use App\Models\FinancePostingDelivery;
use App\Models\FinancePostingEvent;
use App\Models\IntegrationClient;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\Integration\SignedPush;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
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
 * permanen tidak menahan apa pun — ia sudah keluar dari antrean dan menunggu tangan manusia, yang
 * dapat mengembalikannya ke antrean lewat `resend()`.
 */
final class PostingPusher
{
    public const SENT = 'sent';

    public const RETRYING = 'retrying';

    public const FAILED = 'failed';

    public function __construct(
        private readonly SignedPush $push,
        private readonly PostingAcknowledger $ack,
        private readonly ActiveEnvironment $lingkungan,
    ) {}

    /** @return array{clients: int, sent: int, retrying: int, failed: int, skipped: ?string} */
    public function run(int $limit = 100): array
    {
        // Salinan sandbox tidak mengirim apa pun, dan tidak menandai apa pun: produksi yang
        // disalin tetap memegang antreannya sendiri (TODO 6.10.5).
        if (! $this->lingkungan->outboundAllowed()) {
            return ['clients' => 0, 'sent' => 0, 'retrying' => 0, 'failed' => 0, 'skipped' => $this->lingkungan->refusalReason()];
        }

        $klien = 0;
        $hasil = [];
        IntegrationClient::query()
            ->where('delivery_mode', IntegrationClient::PUSH)
            ->where('status', IntegrationClient::ACTIVE)
            ->whereNotNull('push_url')
            ->orderBy('id')
            ->each(function (IntegrationClient $client) use ($limit, &$klien, &$hasil): void {
                $klien++;
                array_push($hasil, ...$this->kirimUntuk($client, $limit));
            });
        $jumlah = array_count_values($hasil);

        return [
            'clients' => $klien,
            'sent' => $jumlah[self::SENT] ?? 0,
            'retrying' => $jumlah[self::RETRYING] ?? 0,
            'failed' => $jumlah[self::FAILED] ?? 0,
            'skipped' => null,
        ];
    }

    /**
     * Alasan kiriman ini tidak dapat dikirim ulang, dalam bahasa pengguna, atau `null` bila boleh.
     * Yang boleh hanya kiriman `failed` untuk posting yang masih `pending`, ke klien yang masih akan
     * mengirimnya: aktif, bermode push, dan prefix jenisnya mencakup posting itu. Kiriman untuk klien
     * yang tidak lagi mengirim akan menunggu di antrean selamanya.
     */
    public function resendRefusal(FinancePostingDelivery $delivery, FinancePosting $posting, ?IntegrationClient $client): ?string
    {
        if ($delivery->status !== FinancePostingDelivery::FAILED) {
            return 'Kiriman ini tidak sedang gagal, jadi tidak perlu dikirim ulang.';
        }
        if ($posting->status !== FinancePosting::PENDING) {
            return 'Posting ini tidak lagi menunggu aplikasi finance, jadi tidak dikirim ulang.';
        }
        if ($client === null || $client->status !== IntegrationClient::ACTIVE || $client->delivery_mode !== IntegrationClient::PUSH || $client->push_url === null) {
            return 'Klien integrasi ini sudah dicabut atau tidak lagi memakai push.';
        }
        $query = FinancePosting::query()->whereKey($posting->id);
        FinancePosting::batasiUntukKlien($query, $client);

        return $query->exists() ? null : 'Klien integrasi ini tidak lagi menerima jenis posting ini.';
    }

    /**
     * Mengembalikan kiriman yang `failed` ke antrean (TODO 7.3.4). Tidak ada yang dikirim di sini:
     * putaran `finance-postings:push` berikutnya yang mengirimnya, dalam urutan klien itu. Jumlah
     * percobaan dan batas waktunya dihitung dari nol, supaya kiriman ulang mendapat jeda dan batas
     * waktu yang sama dengan kiriman pertama. Percobaan sebelumnya tetap tercatat di riwayat.
     *
     * @throws StatusPostingBerubah Kiriman, posting, atau kliennya berubah sejak diperiksa.
     */
    public function resend(FinancePostingDelivery $delivery, int $userId): FinancePostingDelivery
    {
        return DB::transaction(function () use ($delivery, $userId): FinancePostingDelivery {
            // Posting dikunci lebih dulu, urutan yang sama dengan tandai manual dan ack.
            $posting = FinancePosting::query()->lockForUpdate()->findOrFail($delivery->finance_posting_id);
            $locked = FinancePostingDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $client = IntegrationClient::query()->find($locked->integration_client_id);
            if ($client === null || $this->resendRefusal($locked, $posting, $client) !== null) {
                throw new StatusPostingBerubah(sprintf('Kiriman %s tidak lagi dapat dikirim ulang.', $locked->id));
            }

            $previous = ['client' => $client->name, 'previous_attempts' => $locked->attempts, 'previous_status_code' => $locked->last_status_code];
            $locked->fill([
                'status' => FinancePostingDelivery::RETRYING,
                'attempts' => 0,
                'first_attempt_at' => null,
                'next_attempt_at' => null,
                'last_status_code' => null,
                'last_error' => null,
            ])->save();
            FinancePostingEvent::catat($posting, 'push_resend_requested', $posting->status, $posting->status, $client->id, $userId, $previous);

            return $locked;
        });
    }

    /** @return list<string> */
    private function kirimUntuk(IntegrationClient $client, int $limit): array
    {
        $query = FinancePosting::query()
            ->where('tenant_id', $client->tenant_id)
            ->where('status', FinancePosting::PENDING)
            ->whereDoesntHave('deliveries', fn ($inner) => $inner
                ->where('integration_client_id', $client->id)
                ->whereIn('status', [FinancePostingDelivery::DELIVERED, FinancePostingDelivery::FAILED]));
        FinancePosting::batasiUntukKlien($query, $client);
        $postings = $query->orderBy('posting_date')->orderBy('published_at')->orderBy('id')->limit($limit)->get();

        $hasil = [];
        foreach ($postings as $posting) {
            $delivery = FinancePostingDelivery::query()->firstOrNew(
                ['finance_posting_id' => $posting->id, 'integration_client_id' => $client->id],
                ['tenant_id' => $client->tenant_id, 'status' => FinancePostingDelivery::RETRYING, 'attempts' => 0],
            );
            if ($delivery->next_attempt_at !== null && $delivery->next_attempt_at->isFuture()) {
                break;
            }

            $hasil[] = $satu = $this->kirim($client, $posting, $delivery);
            if ($satu === self::RETRYING) {
                break;
            }
        }

        return $hasil;
    }

    private function kirim(IntegrationClient $client, FinancePosting $posting, FinancePostingDelivery $delivery): string
    {
        $sekarang = now();
        $delivery->fill([
            'attempts' => $delivery->attempts + 1,
            'first_attempt_at' => $delivery->first_attempt_at ?? $sekarang,
            'last_attempt_at' => $sekarang,
        ]);
        $badan = json_encode($posting->servedPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $jawaban = $this->push->send($client, $badan);
        } catch (ConnectionException $kegagalan) {
            return $this->ulangi($client, $posting, $delivery, null, 'Tidak terjangkau: '.$kegagalan->getMessage());
        } catch (RuntimeException $kegagalan) {
            return $this->gagal($client, $posting, $delivery, null, $kegagalan->getMessage());
        }

        $kode = $jawaban->status();
        if ($jawaban->successful()) {
            $delivery->fill([
                'status' => FinancePostingDelivery::DELIVERED,
                'delivered_at' => $sekarang,
                'next_attempt_at' => null,
                'last_status_code' => $kode,
                'last_error' => null,
            ])->save();
            FinancePostingEvent::catat($posting, 'push_delivered', $posting->status, $posting->status, $client->id, data: [
                'attempts' => $delivery->attempts, 'status_code' => $kode,
            ]);

            $ack = PostingAcknowledger::fromPushResponse($jawaban->json());
            if ($ack !== null) {
                $this->ack->acknowledge($posting->id, $client, $ack);
            }

            return self::SENT;
        }

        $cuplikan = Str::limit(trim($jawaban->body()), 300);
        if ($kode === 408 || $kode === 429 || $kode >= 500) {
            return $this->ulangi($client, $posting, $delivery, $kode, $cuplikan);
        }

        return $this->gagal($client, $posting, $delivery, $kode, sprintf('Ditolak pembaca dengan HTTP %d. %s', $kode, $cuplikan));
    }

    private function ulangi(IntegrationClient $client, FinancePosting $posting, FinancePostingDelivery $delivery, ?int $kode, string $pesan): string
    {
        $batasJam = (int) config('coreerp.finance_push_retry_hours', 24);
        if ($delivery->first_attempt_at !== null && $delivery->first_attempt_at->copy()->addHours($batasJam)->isPast()) {
            return $this->gagal($client, $posting, $delivery, $kode, sprintf('Batas percobaan %d jam habis. Terakhir: %s', $batasJam, $pesan));
        }

        $jeda = min(60, 2 ** min($delivery->attempts - 1, 6));
        $delivery->fill([
            'status' => FinancePostingDelivery::RETRYING,
            'next_attempt_at' => now()->addMinutes($jeda),
            'last_status_code' => $kode,
            'last_error' => Str::limit($pesan, 490),
        ])->save();
        FinancePostingEvent::catat($posting, 'push_retrying', $posting->status, $posting->status, $client->id, data: [
            'attempts' => $delivery->attempts, 'status_code' => $kode, 'retry_in_minutes' => $jeda,
        ]);

        return self::RETRYING;
    }

    private function gagal(IntegrationClient $client, FinancePosting $posting, FinancePostingDelivery $delivery, ?int $kode, string $pesan): string
    {
        $delivery->fill([
            'status' => FinancePostingDelivery::FAILED,
            'next_attempt_at' => null,
            'last_status_code' => $kode,
            'last_error' => Str::limit($pesan, 490),
        ])->save();
        FinancePostingEvent::catat($posting, 'push_failed', $posting->status, $posting->status, $client->id, data: [
            'attempts' => $delivery->attempts, 'status_code' => $kode,
        ]);

        return self::FAILED;
    }
}
