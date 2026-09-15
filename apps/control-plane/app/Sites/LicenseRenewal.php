<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Carbon\CarbonImmutable;
use ControlPlane\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Perpanjangan lisensi lewat jawaban laporan agen.
 *
 * ## Tanpa penjadwal
 *
 * Konsol ini tidak punya penjadwal, dan agen memang sudah datang setiap menit. Lisensi baru ikut di
 * jawaban laporan yang kebetulan tiba ketika perpanjangannya jatuh tempo — aturannya di `due()`, dan
 * sama persis dengan yang tertulis di `docs/todo/lisensi-mengunci/README.md`.
 *
 * ## Laporan tidak pernah gagal karena lisensi
 *
 * Laporan sudah tercatat sebelum kelas ini dipanggil. Core yang mati, kunci yang belum disetel, atau
 * cacat di kelas ini sendiri hanya berarti jawaban tanpa `license`; lisensi yang berjalan masih punya
 * sisa sepuluh hari, dan agen yang laporannya ditolak justru berhenti menyimpan interval dan jam
 * laporannya.
 */
final class LicenseRenewal
{
    public function __construct(private readonly LicenseIssuer $issuer) {}

    /**
     * Apakah laporan ini perlu dijawab dengan lisensi baru.
     *
     * @param  array<string, mixed>  $report  laporan yang sudah lolos `SiteReports::validate()`
     */
    public function due(Site $site, array $report): bool
    {
        // Situs yang dicabut sudah ditolak tanda tangannya sebelum sampai ke sini. Penjaganya diulang
        // karena yang dipertaruhkan lisensi: jalan lain menuju kelas ini kelak tidak boleh
        // memperpanjang sewa situs yang sudah dilepas.
        if ($site->revoked() || $site->licenseRenewalSuspended()) {
            return false;
        }

        // Kosong berarti tidak ada lisensi yang terbaca di server klien — lisensi yang hilang, rusak,
        // atau belum pernah dipasang. Itu justru keadaan yang paling membutuhkan lisensi baru.
        $expires = $report['license_expires_at'] ?? null;
        $threshold = CarbonImmutable::now($site->timezone)
            ->addDays((int) config('sites.license_renew_before_days'))
            ->toDateString();

        // `Y-m-d` sudah dipastikan `SiteReports`, jadi urutan string sama dengan urutan tanggal.
        if (is_string($expires) && $expires > $threshold) {
            return false;
        }

        $cooldown = (int) config('sites.license_renew_cooldown_minutes');

        return $site->license_issued_at === null
            || $site->license_issued_at->lt(now()->subMinutes($cooldown));
    }

    /**
     * Lisensi untuk jawaban laporan ini, atau null. Tidak pernah melempar.
     *
     * @param  array<string, mixed>  $report
     * @return ?array{license: string, signature: string}
     */
    public function licenseFor(Site $site, array $report, ?string $agentIp = null): ?array
    {
        if (! $this->due($site, $report)) {
            return null;
        }

        // Penerbitan yang gagal tidak menulis `license_issued_at`, jadi jeda di atas tidak berlaku
        // untuknya. Tanpa penanda ini, Core yang mati dipanggil sekali setiap menit oleh setiap situs
        // yang jatuh tempo — dan setiap panggilannya menahan laporan sampai batas waktunya.
        $pause = 'lisensi:perpanjangan-gagal:'.$site->id;

        if (Cache::has($pause)) {
            return null;
        }

        try {
            return $this->issuer->renew($site, $agentIp);
        } catch (EntitlementsUnavailable|SiteRejected $expected) {
            Log::warning('Situs: perpanjangan lisensi gagal; laporan tetap diterima.', [
                'situs' => $site->id,
                'sebab' => $expected->getMessage(),
            ]);
        } catch (Throwable $unexpected) {
            // Cacat, bukan gangguan. Tetap dilaporkan ke penangan galat supaya terlihat, tetapi tidak
            // menggagalkan laporan yang sudah tercatat.
            report($unexpected);
        }

        Cache::put($pause, true, now()->addMinutes((int) config('sites.license_renew_cooldown_minutes')));

        return null;
    }
}
