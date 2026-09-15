<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Sites;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Models\SiteRelease;
use ControlPlane\Sites\InstallProgress;
use ControlPlane\Sites\SiteOperations;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Layar Situs: ringkasan dan rincian.
 *
 * Tindakan yang mengubah server klien — operasi, token pendaftaran, pencabutan, perpanjangan lisensi — ada di
 * `SiteActions`, terpisah dari yang hanya membaca, supaya setiap method yang menulis jejak audit
 * terkumpul di satu tempat yang mudah diperiksa.
 *
 * ## Tidak ada lagi "Situs baru"
 *
 * Situs lahir dari panel "Server klien" di halaman lingkungan produksinya (`ClientServerSetup`). Formulir
 * yang berdiri sendiri menanyakan hal yang sudah diketahui sistem — tenant, nama, edisi — dan tidak
 * menyebut apakah sesuatu sudah terpasang. Ringkasan di sini menaut ke halaman lingkungan itu; situs
 * lama yang lahir sebelum 15 September 2026 tanpa lingkungan tetap tampil dan tetap punya rinciannya.
 */
final class SiteScreens extends Controller
{
    public function index(SiteOperations $operations): InertiaResponse
    {
        $rows = Site::query()
            ->with(['tenant:id,name', 'environment:id,name'])
            ->orderBy('name')
            ->get();

        foreach ($rows as $site) {
            $operations->expireStale($site);
        }

        $progress = InstallProgress::forSites($rows->all());

        return Inertia::render('sites/index', [
            'sites' => $rows
                ->map(fn (Site $site): array => $this->row($site) + [
                    'environment' => $site->environment !== null
                        ? ['id' => $site->environment->id, 'name' => $site->environment->name]
                        : null,
                    'progress' => $progress[$site->id],
                ])
                ->all(),
        ]);
    }

    public function show(string $site, SiteOperations $operations): InertiaResponse
    {
        $row = Site::query()->with(['tenant:id,name', 'environment:id,name'])->whereKey($site)->firstOrFail();

        // Tenggat yang habis ditutup sekarang, supaya layar tidak menampilkan operasi "berjalan" milik
        // agen yang sudah lama berhenti.
        $operations->expireStale($row);

        $history = SiteOperation::query()
            ->where('site_id', $row->id)
            ->with('requester:id,name')
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get()
            ->map(fn (SiteOperation $o): array => [
                'id' => $o->id,
                'operation' => $o->operation,
                'status' => $o->status,
                'step' => $o->step,
                'reason' => $o->failure_message,
                'release' => $o->parameters['release'] ?? null,
                'requestedAt' => $o->requested_at->toDateTimeString(),
                'finishedAt' => $o->finished_at?->toDateTimeString(),
                'requestedBy' => $o->requester->name ?? 'Sistem',
            ])
            ->all();

        $releases = SiteRelease::query()
            ->where('edition', $row->edition)
            ->get(['release'])
            ->pluck('release')
            ->filter(fn (string $release): bool => $row->reported_release === null || SiteRelease::compare($release, $row->reported_release) > 0)
            ->sort(fn (string $a, string $b): int => SiteRelease::compare($b, $a))
            ->values()
            ->all();

        $audit = OperatorAuditEvent::query()
            ->where('subject_type', 'site')
            ->where('subject_id', $row->id)
            ->with('user:id,name')
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn (OperatorAuditEvent $e): array => [
                'id' => $e->id,
                'action' => $e->action,
                'by' => $e->user->name ?? 'Tanpa akun',
                'at' => $e->occurred_at->toDateTimeString(),
            ])
            ->all();

        return Inertia::render('sites/show', [
            'site' => $this->row($row) + [
                'environment' => $row->environment !== null
                    ? ['id' => $row->environment->id, 'name' => $row->environment->name]
                    : null,
                'address' => $row->address,
                'profile' => $row->profile,
                'updateWindow' => $row->updateWindow(),
                'enrolledAt' => $row->enrolled_at?->toDateTimeString(),
                'reportedDigest' => $row->reported_digest,
                'lastReport' => $row->last_report,
                'license' => [
                    'validUntil' => $row->license_valid_until?->toDateString(),
                    'issuedAt' => $row->license_issued_at?->toDateTimeString(),
                    'suspendedAt' => $row->license_suspended_at?->toDateTimeString(),
                    /*
                     * Hanya `false` yang dilaporkan agen yang memicu peringatan. Kosong berarti agen
                     * lama yang belum mengenal bidangnya, atau situs yang belum pernah melapor —
                     * keduanya bukan bukti bahwa kewajiban lisensi dimatikan.
                     */
                    'notRequiredOnServer' => ($row->last_report['license_required'] ?? null) === false,
                ],
            ],
            'history' => $history,
            // Daftar formulir "Minta operasi", dari server. `install` tidak ada di sana — lihat
            // `SiteOperation::MANUAL_OPERATIONS` — walaupun riwayat di bawahnya dapat memuatnya.
            'operations' => SiteOperation::MANUAL_OPERATIONS,
            'releases' => $releases,
            'audit' => $audit,
            'licenseKeyConfigured' => config('sites.license_private_key_path') !== null
                && is_readable((string) config('sites.license_private_key_path')),
            'licenseValidDays' => (int) config('sites.license_valid_days'),
            'enrollment' => session('enrollment'),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'tenant' => $site->tenant->name ?? 'Tanpa tenant',
            'edition' => $site->edition,
            'state' => match (true) {
                $site->revoked() => 'revoked',
                ! $site->enrolled() => 'not_enrolled',
                $site->stale() => 'stale',
                default => 'enrolled',
            },
            'reportedRelease' => $site->reported_release,
            'lastSeenAt' => $site->last_seen_at?->toDateTimeString(),
        ];
    }
}
