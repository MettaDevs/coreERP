<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\FinancePosting;
use App\Models\FinancePostingEvent;
use App\Models\IntegrationClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Menerapkan ack pembaca ke satu posting (TODO 6.5 dan 6.10.2).
 *
 * Satu kelas untuk dua jalan masuk — `POST …/ack` pada mode pull dan body jawaban pada mode
 * push — supaya aturan idempotensinya tidak pernah berbeda di antara keduanya:
 *
 * - Posting `pending` menerima `posted` atau `rejected` dan berhenti di sana.
 * - Ack yang sama diulang (nomor voucher sama, atau kode alasan sama) menjawab tanpa perubahan.
 * - Ack yang bertentangan dengan status akhir, atau ack atas posting yang tidak pernah disajikan
 *   (`held`, `manual`), adalah konflik. Posting yang ditolak dikoreksi dengan posting baru di
 *   periode yang masih terbuka, tidak diberi tanggal ulang (K-17).
 */
final class PostingAcknowledger
{
    public const APPLIED = 'applied';

    public const UNCHANGED = 'unchanged';

    public const CONFLICT = 'conflict';

    /**
     * Aturan validasi badan ack, dipakai controller dan pembaca jawaban push.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'status' => ['required', 'in:'.FinancePosting::POSTED.','.FinancePosting::REJECTED],
            'external_reference' => ['required_if:status,'.FinancePosting::POSTED, 'nullable', 'string', 'max:120'],
            'reason_code' => ['required_if:status,'.FinancePosting::REJECTED, 'nullable', 'in:'.implode(',', FinancePosting::REJECTION_CODES)],
            'reason' => ['required_if:status,'.FinancePosting::REJECTED, 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Badan ack yang sah dari jawaban push, atau `null` bila jawabannya bukan ack.
     *
     * @return array{status: string, external_reference: ?string, reason_code: ?string, reason: ?string}|null
     */
    public static function fromPushResponse(mixed $body): ?array
    {
        if (! is_array($body) || Validator::make($body, self::rules())->fails()) {
            return null;
        }

        return self::bentuk($body);
    }

    /**
     * @param  array<string, mixed>  $ack
     * @return array{status: string, external_reference: ?string, reason_code: ?string, reason: ?string}
     */
    public static function bentuk(array $ack): array
    {
        $teks = static fn (string $kunci): ?string => isset($ack[$kunci]) && is_string($ack[$kunci]) && trim($ack[$kunci]) !== '' ? trim($ack[$kunci]) : null;
        $posted = ($ack['status'] ?? null) === FinancePosting::POSTED;

        return [
            'status' => $posted ? FinancePosting::POSTED : FinancePosting::REJECTED,
            'external_reference' => $posted ? $teks('external_reference') : null,
            'reason_code' => $posted ? null : $teks('reason_code'),
            'reason' => $posted ? null : $teks('reason'),
        ];
    }

    /**
     * @param  array{status: string, external_reference: ?string, reason_code: ?string, reason: ?string}  $ack
     * @return array{result: string, posting: FinancePosting}
     */
    public function acknowledge(string $postingRowId, IntegrationClient $client, array $ack): array
    {
        return DB::transaction(function () use ($postingRowId, $client, $ack): array {
            $posting = FinancePosting::query()->lockForUpdate()->findOrFail($postingRowId);

            if ($posting->status === FinancePosting::PENDING) {
                $posting->fill([
                    'status' => $ack['status'],
                    'external_reference' => $ack['external_reference'],
                    'reason_code' => $ack['reason_code'],
                    'reason' => $ack['reason'],
                    'acknowledged_at' => now(),
                    'acknowledged_by_client_id' => $client->id,
                ])->save();
                FinancePostingEvent::catat(
                    $posting,
                    $ack['status'] === FinancePosting::POSTED ? 'acknowledged_posted' : 'acknowledged_rejected',
                    FinancePosting::PENDING,
                    $posting->status,
                    $client->id,
                    data: array_filter([
                        'external_reference' => $ack['external_reference'],
                        'reason_code' => $ack['reason_code'],
                    ]),
                );

                return ['result' => self::APPLIED, 'posting' => $posting];
            }

            $sama = $posting->status === $ack['status'] && ($ack['status'] === FinancePosting::POSTED
                ? $posting->external_reference === $ack['external_reference']
                : $posting->reason_code === $ack['reason_code']);

            return ['result' => $sama ? self::UNCHANGED : self::CONFLICT, 'posting' => $posting];
        });
    }
}
