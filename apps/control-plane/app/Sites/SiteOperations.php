<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Carbon\CarbonImmutable;
use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Models\SiteRelease;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Antrean operasi situs: diminta operator, diambil agen, dilaporkan per langkah.
 *
 * ## Tenggat dibaca saat diminta
 *
 * Konsol ini tidak punya penjadwal. Operasi yang *lease*-nya habis — agen mati di tengah pembaruan —
 * ditutup `failed` pada saat berikutnya seseorang bertanya: agen yang meminta operasi baru, atau
 * operator yang membuka rincian situs. Hasilnya sama dengan penjadwal; yang berbeda hanya kapan
 * tepatnya ia terlihat.
 *
 * ## Yang ditegakkan database, bukan kelas ini
 *
 * Satu operasi berjalan per situs, dan satu permintaan per jenis per situs, adalah partial unique
 * index. Kelas ini menerjemahkan penolakannya menjadi kalimat; ia tidak menggantikannya.
 */
final class SiteOperations
{
    public function __construct(private readonly LicenseIssuer $licenses) {}

    /** @param  array<string, mixed>  $input */
    public function request(Request $request, Site $site, string $operation, array $input): SiteOperation
    {
        if (! in_array($operation, SiteOperation::OPERATIONS, true)) {
            throw new SiteRejected('operation_unknown', 'Operasi tidak dikenal.');
        }

        if ($site->revoked()) {
            throw new SiteRejected('site_revoked', 'Situs ini sudah dicabut.');
        }

        if (! $site->enrolled()) {
            throw new SiteRejected('site_not_enrolled', 'Situs ini belum terdaftar; agen belum pernah menyambung.');
        }

        try {
            return DB::transaction(function () use ($request, $site, $operation, $input): SiteOperation {
                // Di dalam transaksi, karena menerbitkan lisensi menulis `sites.license_*` dan jejak
                // auditnya sendiri. Permintaan yang kemudian ditolak indeks satu-permintaan-per-jenis
                // tidak boleh meninggalkan catatan "lisensi diterbitkan" untuk lisensi yang tidak pernah
                // diantar ke mana pun.
                $parameters = match ($operation) {
                    'upgrade' => $this->upgradeParameters($site, $input),
                    'install_license' => $this->licenseParameters($request, $site, $input),
                    default => [],
                };

                $created = SiteOperation::query()->create([
                    'site_id' => $site->id,
                    'operation' => $operation,
                    'parameters' => $parameters,
                    'status' => 'requested',
                    'requested_by' => $request->user()?->getAuthIdentifier(),
                    'requested_at' => now(),
                    'expires_at' => now()->addDays((int) config('sites.request_expiry_days')),
                ]);

                // Isi lisensi tidak ikut dicatat — ia dapat dibaca dari operasinya, dan jejak audit
                // tidak perlu menjadi salinan kedua.
                OperatorAudit::record($request, 'site.operation.requested', 'site', $site->id, [
                    'operation_id' => $created->id,
                    'operation' => $operation,
                    'release' => $parameters['release'] ?? null,
                ]);

                return $created;
            });
        } catch (UniqueConstraintViolationException $conflict) {
            if (! str_contains($conflict->getMessage(), 'site_operations_satu_permintaan_per_jenis')) {
                throw $conflict;
            }

            throw new SiteRejected('operation_pending', 'Operasi yang sama untuk situs ini masih menunggu diambil agen.');
        }
    }

    public function cancel(Request $request, Site $site, SiteOperation $operation): void
    {
        DB::transaction(function () use ($request, $site, $operation): void {
            $cancelled = SiteOperation::query()
                ->whereKey($operation->id)
                ->where('site_id', $site->id)
                ->where('status', 'requested')
                ->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);

            if ($cancelled !== 1) {
                throw new SiteRejected('operation_not_pending', 'Hanya operasi yang belum diambil agen yang dapat dibatalkan.');
            }

            OperatorAudit::record($request, 'site.operation.cancelled', 'site', $site->id, [
                'operation_id' => $operation->id,
                'operation' => $operation->operation,
            ]);
        });
    }

    /**
     * Menutup operasi yang tenggatnya habis. Dipanggil sebelum membaca antrean, dari mana pun.
     */
    public function expireStale(Site $site): void
    {
        SiteOperation::query()
            ->where('site_id', $site->id)
            ->where('status', 'running')
            ->where('lease_until', '<', now())
            ->update([
                'status' => 'failed',
                'failure_message' => 'Tenggat habis: agen berhenti melapor sebelum operasi selesai. Periksa keadaan server sebelum meminta ulang.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        SiteOperation::query()
            ->where('site_id', $site->id)
            ->where('status', 'requested')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'finished_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Menyerahkan operasi berikutnya kepada agen situs ini, atau null.
     *
     * `upgrade` hanya diserahkan di dalam jendela pembaruan. Operasi lain kapan saja: cadangan,
     * lisensi, dan diagnosa tidak menyentuh aplikasi yang sedang melayani pasien.
     */
    public function claim(Site $site, ?CarbonImmutable $now = null): ?SiteOperation
    {
        $this->expireStale($site);

        return DB::transaction(function () use ($site, $now): ?SiteOperation {
            $locked = Site::query()->lockForUpdate()->find($site->id);

            if (! $locked instanceof Site) {
                return null;
            }

            $running = SiteOperation::query()
                ->where('site_id', $site->id)
                ->where('status', 'running')
                ->exists();

            if ($running) {
                return null;
            }

            $candidates = SiteOperation::query()
                ->where('site_id', $site->id)
                ->where('status', 'requested')
                ->orderBy('requested_at')
                ->get();

            $next = $candidates->first(
                fn (SiteOperation $operation): bool => $operation->operation !== 'upgrade' || $locked->withinUpdateWindow($now),
            );

            if (! $next instanceof SiteOperation) {
                return null;
            }

            $next->forceFill([
                'status' => 'running',
                'started_at' => now(),
                'lease_until' => now()->addMinutes((int) config('sites.lease_minutes')),
            ])->save();

            return $next;
        });
    }

    /**
     * Mencatat satu langkah, atau hasil akhir, dari agen.
     *
     * Operasi yang bukan milik situs ini, sudah ditutup, atau tenggatnya habis ditolak: agen yang
     * menerima penolakan itu harus berhenti melapor, karena operasinya sudah tidak dipegangnya.
     */
    public function recordStep(Site $site, string $operationId, string $status, string $step, ?string $failureMessage): SiteOperation
    {
        return DB::transaction(function () use ($site, $operationId, $status, $step, $failureMessage): SiteOperation {
            $operation = SiteOperation::query()
                ->whereKey($operationId)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->first();

            if (! $operation instanceof SiteOperation
                || $operation->status !== 'running'
                || $operation->lease_until === null
                || $operation->lease_until->isPast()) {
                throw new SiteRejected('operation_not_held', 'Operasi ini tidak lagi dipegang agen ini.');
            }

            $attributes = match ($status) {
                'running' => [
                    'step' => $step,
                    'lease_until' => now()->addMinutes((int) config('sites.lease_minutes')),
                ],
                'succeeded' => [
                    'status' => 'succeeded',
                    'step' => $step,
                    'finished_at' => now(),
                ],
                'failed' => [
                    'status' => 'failed',
                    'step' => $step,
                    'failure_message' => $failureMessage !== null && trim($failureMessage) !== ''
                        ? $failureMessage
                        : 'Agen melaporkan gagal tanpa menyebut sebabnya.',
                    'finished_at' => now(),
                ],
                default => throw new SiteRejected('step_status_invalid', 'Status langkah tidak dikenal.'),
            };

            $operation->forceFill($attributes)->save();

            return $operation;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{edition: string, release: string}
     */
    private function upgradeParameters(Site $site, array $input): array
    {
        $release = is_string($input['release'] ?? null) ? $input['release'] : '';

        $registered = SiteRelease::query()
            ->where('edition', $site->edition)
            ->where('release', $release)
            ->exists();

        if (! $registered) {
            throw new SiteRejected('release_unknown', 'Rilis itu tidak terdaftar untuk edisi situs ini.');
        }

        // Penjaga yang sama juga ada di agen. Di sini ia mencegah operator memilih rilis yang pasti
        // akan ditolak; di agen ia menjaga dari konsol ini sendiri.
        if ($site->reported_release !== null && SiteRelease::compare($release, $site->reported_release) <= 0) {
            throw new SiteRejected(
                'release_not_newer',
                sprintf('Rilis %s tidak lebih baru dari rilis terpasang %s. Mundur hanya terjadi di dalam update.sh ketika pembaruan gagal.', $release, $site->reported_release),
            );
        }

        return ['edition' => $site->edition, 'release' => $release];
    }

    /**
     * Tanggal kosong berarti masa bawaan penerbit (`sites.license_valid_days`). Tanggal yang diisi
     * tetap diperiksa di sini.
     *
     * @param  array<string, mixed>  $input
     * @return array{license: string, signature: string}
     */
    private function licenseParameters(Request $request, Site $site, array $input): array
    {
        $raw = $input['valid_until'] ?? null;
        $validUntil = null;

        if ($raw !== null && $raw !== '') {
            $validUntil = is_string($raw) ? $raw : '';
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d', $validUntil, $site->timezone)
                : null;

            // Dibandingkan bolak-balik: `2027-02-31` lolos pola dan diterima Carbon sebagai 3 Maret.
            // Lisensi yang ditandatangani dengan tanggal yang tidak ada di kalender dibaca Core sebagai
            // lisensi rusak — dan lisensi rusak mengunci.
            if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $validUntil) {
                throw new SiteRejected('license_date_invalid', 'Tanggal berakhir lisensi harus berbentuk tahun-bulan-tanggal.');
            }

            // Lisensi yang sudah habis saat diterbitkan mengunci klinik begitu terpasang. Menghentikan
            // sewa punya tombolnya sendiri, dan tombol itu membiarkan lisensi yang berjalan habis.
            if ($validUntil < CarbonImmutable::now($site->timezone)->toDateString()) {
                throw new SiteRejected('license_date_past', 'Tanggal berakhir lisensi sudah lewat; lisensi itu akan langsung mengunci server klien.');
            }
        }

        try {
            return $this->licenses->issueForOperator($request, $site, $validUntil);
        } catch (EntitlementsUnavailable $e) {
            throw new SiteRejected('entitlements_unavailable', 'Daftar app tenant tidak terbaca dari Core, jadi lisensi tidak diterbitkan. '.$e->getMessage());
        }
    }
}
