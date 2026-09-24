<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Illuminate\Support\Carbon;

/**
 * Kesehatan feed posting finance satu server klien, dari `finance_feed` di laporan agen terakhir (TODO feed posting
 * finance area 14).
 *
 * Angkanya disusun Core di server klien (`finance-postings:summary`) dan dibawa agen: jumlah posting per status, jam
 * terbit posting `pending` tertua, jam pull terakhir pembaca, dan jam push terakhir yang diterima pembaca bila Core dan
 * agennya sudah mengenalnya (`pushReported`). Konsol tidak pernah melihat isi jurnalnya (K-02);
 * rinciannya ada di layar Posting finance › Pantau posting pada aplikasi server itu. Yang dikerjakan di sini hanya
 * menilai angka itu, supaya layar dan test membaca penilaian yang sama.
 *
 * | Keadaan | Artinya |
 * | --- | --- |
 * | `not_reported` | Belum ada laporan, atau laporan agen lama yang belum mengenal `finance_feed`. Sah: agen di server klien diperbarui sesudah konsol |
 * | `unreadable` | Agen mengenal bidangnya tetapi tidak mendapat ringkasan dari Core — belum ada rilis sehat, Core tidak menjawab, atau rilis Core belum punya perintahnya |
 * | `unused` | Belum ada satu posting pun dan belum pernah ada pull: feed belum dipakai di server ini |
 * | `attention` | Ada posting `rejected` atau `held`, atau posting `pending` tertua lebih tua dari `PENDING_ALERT_HOURS` |
 * | `healthy` | Selain itu |
 */
final class FinanceFeedHealth
{
    /** Status posting dalam urutan kontrak `finance_feed.counts`; sama dengan `PostingFeedSummary::STATUSES` di Core. */
    public const STATUSES = ['held', 'pending', 'posted', 'rejected', 'manual'];

    /**
     * Posting `pending` yang menunggu lebih lama dari ini menandai feed perlu perhatian.
     *
     * Satu hari penuh. Pembaca melakukan pull dengan job terjadwal, jadi posting yang masih `pending` sesudah sehari
     * berarti pembacanya melewatkan pull sepanjang hari itu — bukan jeda singkat seperti aplikasi finance yang sedang
     * diperbarui (K-03) — dan pembukuan hari itu di aplikasi finance sudah kehilangan jurnalnya. Ambang yang lebih
     * pendek menandai jeda yang pulih sendiri, dan tanda yang sering salah melatih operator mengabaikannya.
     *
     * Konstanta, bukan setelan konsol: pembacanya baru satu, dan belum ada server yang butuh ambang lain. Server
     * yang kelak berbeda mengikuti pola `LicenseTerms` — bawaan dari sini, angka sendiri per situs.
     */
    public const PENDING_ALERT_HOURS = 24;

    /**
     * @param  ?array<string, mixed>  $report  laporan agen terakhir, `sites.last_report`, yang sudah lolos `SiteReports::validate()`
     * @return array{state: string, counts: ?array<string, int>, oldestPendingAt: ?string, oldestPendingSeconds: ?int, lastPulledAt: ?string, lastPushedAt: ?string, pushReported: bool, alerts: list<string>, pendingAlertHours: int}
     */
    public static function fromReport(?array $report): array
    {
        $empty = [
            'counts' => null,
            'oldestPendingAt' => null,
            'oldestPendingSeconds' => null,
            'lastPulledAt' => null,
            'lastPushedAt' => null,
            'pushReported' => false,
            'alerts' => [],
            'pendingAlertHours' => self::PENDING_ALERT_HOURS,
        ];

        if ($report === null || ! array_key_exists('finance_feed', $report)) {
            return ['state' => 'not_reported', ...$empty];
        }

        $feed = $report['finance_feed'];

        if (! is_array($feed)) {
            return ['state' => 'unreadable', ...$empty];
        }

        $reported = is_array($feed['counts'] ?? null) ? $feed['counts'] : [];
        $counts = [];

        foreach (self::STATUSES as $status) {
            $counts[$status] = is_int($reported[$status] ?? null) ? $reported[$status] : 0;
        }

        $oldestPendingAt = self::time($feed['oldest_pending_at'] ?? null);
        $lastPulledAt = self::time($feed['last_pulled_at'] ?? null);
        // Kunci yang tidak ada berarti Core atau agennya belum mengenal push; `null` berarti belum pernah ada push
        // yang diterima pembaca. Keduanya dibedakan supaya layar tidak menulis "belum pernah" atas nama yang tidak tahu.
        $lastPushedAt = self::time($feed['last_pushed_at'] ?? null);

        // Umur terhadap jam server klien di laporan yang sama, bukan jam konsol. Kedua waktunya dari jam yang sama,
        // jadi selisih jam server klien terhadap konsol tidak ikut; yang terbaca adalah umurnya saat laporan terakhir.
        $oldestPendingSeconds = null;

        if ($oldestPendingAt !== null) {
            $reference = self::time($report['server_time'] ?? null) ?? now();
            $oldestPendingSeconds = max(0, (int) $oldestPendingAt->diffInSeconds($reference));
        }

        $alerts = [];

        if ($counts['rejected'] > 0) {
            $alerts[] = 'rejected';
        }

        if ($counts['held'] > 0) {
            $alerts[] = 'held';
        }

        if ($oldestPendingSeconds !== null && $oldestPendingSeconds > self::PENDING_ALERT_HOURS * 3600) {
            $alerts[] = 'pending_old';
        }

        return [
            'state' => match (true) {
                $alerts !== [] => 'attention',
                array_sum($counts) === 0 && $lastPulledAt === null => 'unused',
                default => 'healthy',
            },
            'counts' => $counts,
            'oldestPendingAt' => $oldestPendingAt?->toIso8601String(),
            'oldestPendingSeconds' => $oldestPendingSeconds,
            'lastPulledAt' => $lastPulledAt?->toIso8601String(),
            'lastPushedAt' => $lastPushedAt?->toIso8601String(),
            'pushReported' => array_key_exists('last_pushed_at', $feed),
            'alerts' => $alerts,
            'pendingAlertHours' => self::PENDING_ALERT_HOURS,
        ];
    }

    private static function time(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
