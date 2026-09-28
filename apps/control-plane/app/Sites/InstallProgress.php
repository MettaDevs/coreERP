<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Carbon\CarbonInterface;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteEnrollmentToken;
use ControlPlane\Models\SiteOperation;
use Illuminate\Support\Facades\DB;

/**
 * Keadaan pemasangan sebuah server klien, dihitung di server — satu kata untuk panel, daftar
 * lingkungan, dan ringkasan situs.
 *
 * ## Kenapa dihitung di sini, bukan di React
 *
 * Tiga layar menampilkan status yang sama (PS-05). Aturan yang disalin ke tiga komponen menyimpang pada
 * salinan yang paling jarang dibuka, dan operator yang membaca "Memasang" di daftar lalu "Gagal" di
 * rinciannya berhenti memercayai keduanya. Layar hanya menerjemahkan kuncinya menjadi kata.
 *
 * ## Urutan penentunya
 *
 * 1. Belum ada situs → `not_prepared`.
 * 2. Situs dicabut → `revoked`, apa pun yang tersisa di antreannya.
 * 3. Operasi `install` terakhir:
 *    - `running` → `installing`;
 *    - `failed` atau `expired` → `failed`;
 *    - `succeeded` → `ready`, atau `stale` bila agen berhenti melapor;
 *    - `requested` → belum terdaftar: `awaiting_command` selama tokennya hidup, `no_command` bila
 *      tokennya habis tanpa dipakai; sudah terdaftar: `awaiting_release` bila rilisnya kosong,
 *      `connected` bila agen tinggal mengambilnya.
 * 4. Operasi terakhir dibatalkan → `no_command`. Pembatalan manusia berarti belum ada perintah yang
 *    sedang ditunggu, dan menampilkan "Jalan" untuk server yang tidak pernah dipasang lebih buruk.
 * 5. Tanpa operasi `install` sama sekali — situs yang didaftarkan sebelum pemasangan satu perintah ada:
 *    belum terdaftar mengikuti tokennya; terdaftar berarti dipasang dengan tangan, jadi `ready` atau
 *    `stale`.
 *
 * ## Yang dipantau ulang
 *
 * Hanya keadaan yang berubah tanpa tindakan di layar ini: menunggu teknisi menjalankan perintah,
 * menunggu agen mengambil, dan pemasangan yang berjalan. `awaiting_release` tidak ikut — rilis yang
 * baru terdaftar tidak mengubah operasi yang sudah dibuat; yang mengubahnya operator, lewat perintah
 * pasang yang baru.
 */
final class InstallProgress
{
    public const STATES = [
        'not_prepared',
        'no_command',
        'awaiting_command',
        'awaiting_release',
        'connected',
        'installing',
        'ready',
        'stale',
        'failed',
        'revoked',
    ];

    public const POLLED_STATES = ['awaiting_command', 'connected', 'installing'];

    /**
     * @return array{state: string, final: bool, step: ?string, failureMessage: ?string, release: ?string, reportedRelease: ?string, lastSeenAt: ?string, commandExpiresAt: ?string, commandExpired: bool}
     */
    public static function derive(?Site $site, ?SiteOperation $latestInstall, ?SiteEnrollmentToken $latestToken, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        $liveToken = $latestToken !== null && $latestToken->used_at === null && $latestToken->expires_at->gt($now);
        $expiredToken = $latestToken !== null && $latestToken->used_at === null && ! $liveToken;

        $state = match (true) {
            ! $site instanceof Site => 'not_prepared',
            $site->revoked() => 'revoked',
            default => self::stateOf($site, $latestInstall, $liveToken),
        };

        $showsOperation = in_array($state, ['installing', 'failed', 'awaiting_release', 'connected', 'ready', 'stale'], true)
            && $latestInstall instanceof SiteOperation;
        $release = $latestInstall?->parameters['release'] ?? null;

        return [
            'state' => $state,
            'final' => ! in_array($state, self::POLLED_STATES, true),
            'step' => $showsOperation ? $latestInstall->step : null,
            'failureMessage' => $state === 'failed' ? ($latestInstall->failure_message ?? self::expiredMessage($latestInstall)) : null,
            'release' => $showsOperation && is_string($release) ? $release : null,
            'reportedRelease' => $site?->reported_release,
            'lastSeenAt' => $site?->last_seen_at?->toDateTimeString(),
            'commandExpiresAt' => $liveToken ? $latestToken->expires_at->toDateTimeString() : null,
            'commandExpired' => $state === 'no_command' && $expiredToken,
        ];
    }

    /**
     * Keadaan untuk banyak situs sekaligus, dengan dua query berapa pun jumlah situsnya.
     *
     * @param  iterable<Site>  $sites
     * @return array<string, array{state: string, final: bool, step: ?string, failureMessage: ?string, release: ?string, reportedRelease: ?string, lastSeenAt: ?string, commandExpiresAt: ?string, commandExpired: bool}>
     */
    public static function forSites(iterable $sites): array
    {
        $byId = [];

        foreach ($sites as $site) {
            $byId[$site->id] = $site;
        }

        if ($byId === []) {
            return [];
        }

        $ids = array_keys($byId);

        // DISTINCT ON memilih satu baris terakhir per situs di PostgreSQL. Token menumpuk setiap kali
        // perintah pasang dibuat ulang; membaca semuanya hanya untuk membuang semua kecuali satu adalah
        // pemborosan yang tumbuh bersama umur situs.
        $installs = SiteOperation::query()
            ->select(DB::raw('DISTINCT ON (site_id) site_operations.*'))
            ->whereIn('site_id', $ids)
            ->where('operation', 'install')
            ->orderBy('site_id')
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy('site_id');

        $tokens = SiteEnrollmentToken::query()
            ->select(DB::raw('DISTINCT ON (site_id) site_enrollment_tokens.*'))
            ->whereIn('site_id', $ids)
            ->orderBy('site_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy('site_id');

        $progress = [];

        foreach ($byId as $id => $site) {
            $install = $installs->get($id);
            $token = $tokens->get($id);

            $progress[$id] = self::derive(
                $site,
                $install instanceof SiteOperation ? $install : null,
                $token instanceof SiteEnrollmentToken ? $token : null,
            );
        }

        return $progress;
    }

    /**
     * @return array{state: string, final: bool, step: ?string, failureMessage: ?string, release: ?string, reportedRelease: ?string, lastSeenAt: ?string, commandExpiresAt: ?string, commandExpired: bool}
     */
    public static function forSite(?Site $site): array
    {
        return $site instanceof Site ? self::forSites([$site])[$site->id] : self::derive(null, null, null);
    }

    private static function stateOf(Site $site, ?SiteOperation $install, bool $liveToken): string
    {
        $waitingForEnrollment = $liveToken ? 'awaiting_command' : 'no_command';

        if (! $install instanceof SiteOperation) {
            if (! $site->enrolled()) {
                return $waitingForEnrollment;
            }

            return $site->stale() ? 'stale' : 'ready';
        }

        return match ($install->status) {
            'running' => 'installing',
            'failed', 'expired' => 'failed',
            'succeeded' => $site->stale() ? 'stale' : 'ready',
            'requested' => match (true) {
                ! $site->enrolled() => $waitingForEnrollment,
                ! is_string($install->parameters['release'] ?? null) || $install->parameters['release'] === '' => 'awaiting_release',
                default => 'connected',
            },
            default => 'no_command',
        };
    }

    /** Operasi `expired` tidak punya `failure_message`; kalimatnya disusun di sini. */
    private static function expiredMessage(?SiteOperation $install): ?string
    {
        return $install?->status === 'expired'
            ? 'Perintah pasang tidak pernah diambil agen sampai batas waktunya habis.'
            : null;
    }
}
