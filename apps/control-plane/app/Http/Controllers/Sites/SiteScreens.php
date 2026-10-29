<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Sites;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Models\SiteRelease;
use ControlPlane\Sites\ClientServerSetup;
use ControlPlane\Sites\FinanceFeedHealth;
use ControlPlane\Sites\InstallProgress;
use ControlPlane\Sites\LicenseTerms;
use ControlPlane\Sites\SiteDns;
use ControlPlane\Sites\SiteOperations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Layar Server klien (`/situs`): daftar setiap mesin milik klien yang dikelola dari sini, dan rinciannya.
 *
 * Tindakan yang mengubah server klien — operasi, token pendaftaran, pencabutan, perpanjangan lisensi — ada di
 * `SiteActions`, terpisah dari yang hanya membaca, supaya setiap method yang menulis jejak audit
 * terkumpul di satu tempat yang mudah diperiksa. Setelan alamat dan jendela pembaruan ada di `SiteSettings`.
 *
 * ## Untuk apa daftar ini
 *
 * Satu tempat yang menjawab "server klien mana saja yang kita pegang, di alamat mana, dan mana yang perlu
 * didatangi hari ini" — tanpa membuka halaman lingkungan tenant satu per satu. Karena itu setiap baris
 * membawa alamat mesinnya, keadaan pemasangan, rilis terpasang terhadap rilis terbaru, dan masa lisensi.
 *
 * ## Tidak ada formulir "Situs baru" yang berdiri sendiri
 *
 * Formulir lama menanyakan hal yang sudah diketahui sistem — tenant, nama, edisi — dan tidak menyebut apakah
 * sesuatu sudah terpasang. Tombol "Tambah server klien" di sini hanya memilih lingkungan produksi server
 * klien yang belum punya server, lalu memakai pintu yang sama dengan panel di halaman lingkungan
 * (`ClientServerSetup::prepare`). Situs lama yang lahir sebelum 15 September 2026 tanpa lingkungan tetap
 * tampil dan tetap punya rinciannya.
 */
final class SiteScreens extends Controller
{
    public function index(Request $request, SiteOperations $operations): InertiaResponse|JsonResponse
    {
        if (! $request->wantsJson()) {
            return Inertia::render('sites/index', ['hasCandidates' => $this->candidateQuery()->exists()]);
        }

        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'filter' => ['nullable', Rule::in(['all', 'attention', 'running', 'waiting', 'outdated', 'revoked'])],
            'lookup' => ['nullable', Rule::in(['candidates'])],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $cursor = isset($input['cursor']) ? Cursor::fromEncoded($input['cursor']) : null;
        if (isset($input['cursor']) && ($cursor === null || ! is_string($cursor->toArray()['id'] ?? null)
            || (! isset($input['lookup']) && ! is_string($cursor->toArray()['name'] ?? null)))) {
            throw ValidationException::withMessages(['cursor' => 'Posisi daftar tidak valid. Muat ulang daftar.']);
        }
        if (isset($input['lookup'])) {
            $query = $this->candidateQuery();
            if (($search = trim($input['search'] ?? '')) !== '') {
                $pattern = '%'.mb_strtolower($search).'%';
                $query->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$pattern])
                    ->orWhereHas('tenant', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$pattern])));
            }
            $batch = $query->orderBy('id')->cursorPaginate(50, ['*'], 'cursor', $cursor);

            return response()->json(['data' => $batch->map(fn (Environment $environment): array => [
                'id' => $environment->id, 'name' => $environment->name, 'tenant' => $environment->tenant->name ?? 'Tanpa tenant',
            ])->all(), 'next_cursor' => $batch->nextCursor()?->encode()]);
        }

        $query = Site::query()
            ->with(['tenant:id,name', 'environment:id,name,tenant_id,kind,hosting', 'environment.tenant:id,slug'])
            ->select('sites.*');
        if ($cursor !== null) {
            $query->selectRaw('CASE WHEN name > ? OR (name = ? AND id > ?) THEN 1 ELSE 0 END AS beyond_cursor', [$cursor->parameter('name'), $cursor->parameter('name'), $cursor->parameter('id')]);
        }
        $newest = $this->newestReleases();
        $counts = array_fill_keys(['all', 'attention', 'running', 'waiting', 'outdated', 'revoked'], 0);
        $filter = $input['filter'] ?? 'all';
        $needle = mb_strtolower(trim($input['search'] ?? ''));
        $items = [];
        $scanCursor = null;

        // Ringkasan membutuhkan seluruh armada, tetapi hanya satu batch dan hasil halaman disimpan di memori.
        do {
            $batch = (clone $query)->orderBy('name')->orderBy('id')->cursorPaginate(50, ['*'], 'scan_cursor', $scanCursor);
            $rows = $batch->getCollection();
            $operations->expireStaleForSites($rows);
            $progress = InstallProgress::forSites($rows);
            foreach ($rows as $site) {
                $state = $progress[$site->id];
                $latest = $newest[$site->edition] ?? null;
                foreach ($counts as $key => $count) {
                    if ($this->matches($site, $state['state'], $latest, $key)) {
                        $counts[$key]++;
                    }
                }
                if (count($items) >= 51 || ($cursor !== null && ! $site->getAttribute('beyond_cursor'))
                    || ! $this->matches($site, $state['state'], $latest, $filter)) {
                    continue;
                }
                if ($needle !== '' && ! collect([$site->name, $site->tenant?->name, $site->environment?->name, $site->server_address, $site->appUrl(), $site->last_seen_ip])
                    ->contains(fn ($value): bool => is_string($value) && str_contains(mb_strtolower($value), $needle))) {
                    continue;
                }
                $items[] = $this->row($site) + [
                    'environment' => $site->environment !== null ? ['id' => $site->environment->id, 'name' => $site->environment->name] : null,
                    'progress' => $state,
                    'newestRelease' => $latest,
                ];
            }
            $scanCursor = $batch->nextCursor();
        } while ($scanCursor !== null);

        $hasMore = count($items) > 50;
        $items = array_slice($items, 0, 50);
        $last = $items === [] ? null : $items[array_key_last($items)];

        return response()->json([
            'data' => $items,
            'next_cursor' => $hasMore && $last !== null ? (new Cursor(['name' => $last['name'], 'id' => $last['id']], true))->encode() : null,
            'counts' => $counts,
        ]);
    }

    private function matches(Site $site, string $state, ?string $newest, string $filter): bool
    {
        return match ($filter) {
            'all' => $state !== 'revoked',
            'attention' => in_array($state, ['failed', 'stale'], true) || ($state !== 'revoked' && ! $site->licenseIssuedPerpetual()
                && $site->license_valid_until !== null && now()->startOfDay()->diffInDays($site->license_valid_until->copy()->startOfDay(), false) <= 7),
            'running' => $state === 'ready',
            'waiting' => in_array($state, ['no_command', 'awaiting_command', 'awaiting_release', 'connected', 'installing'], true),
            'outdated' => in_array($state, ['ready', 'stale'], true) && $site->reported_release !== null && $newest !== null && SiteRelease::compare($newest, $site->reported_release) > 0,
            'revoked' => $state === 'revoked',
            default => false,
        };
    }

    public function show(string $site, SiteOperations $operations, LicenseTerms $terms): InertiaResponse
    {
        $row = Site::query()->with(['tenant:id,name', 'environment:id,name,tenant_id,kind,hosting', 'environment.tenant:id,slug'])->whereKey($site)->firstOrFail();

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
                'profile' => $row->profile,
                'newestRelease' => ClientServerSetup::newestRelease($row->edition),
                // Situs yang lahir dari halaman lingkungan dipasang dari sana. Rinciannya menampilkan
                // keadaan pemasangan yang sama dan menaut ke panelnya, bukan formulir pendaftaran lama.
                'progress' => InstallProgress::forSite($row),
                'enrolledAt' => $row->enrolled_at?->toDateTimeString(),
                'cleanupPending' => $row->revoked() && ($row->dns_record_id !== null || SiteOperation::query()->where('site_id', $row->id)->whereNotNull('registry_robot_id')->exists()),
                'reportedDigest' => $row->reported_digest,
                'lastReport' => $row->last_report,
                'license' => [
                    'validUntil' => $row->license_valid_until?->toDateString(),
                    'issuedAt' => $row->license_issued_at?->toDateTimeString(),
                    'suspendedAt' => $row->license_suspended_at?->toDateTimeString(),
                    /*
                     * Setelan dan keadaan dikirim terpisah. `perpetual` yang menyala sementara
                     * `issuedPerpetual` masih mati berarti operator baru saja mengubahnya dan lisensi
                     * permanennya belum sampai ke server klien — layar menyebutkan itu, bukan menyamakan
                     * keduanya dan berbohong tentang apa yang sedang berlaku di klinik.
                     */
                    'perpetual' => $row->license_perpetual,
                    'issuedPerpetual' => $row->licenseIssuedPerpetual(),
                    'terms' => $terms->forSite($row),
                    'defaultTerms' => $terms->defaults(),
                    /*
                     * Hanya `false` yang dilaporkan agen yang memicu peringatan. Kosong berarti agen
                     * lama yang belum mengenal bidangnya, atau situs yang belum pernah melapor —
                     * keduanya bukan bukti bahwa kewajiban lisensi dimatikan.
                     */
                    'notRequiredOnServer' => ($row->last_report['license_required'] ?? null) === false,
                ],
                // Dinilai di sini, bukan di layar: ambangnya satu, dan test membaca penilaian yang sama.
                'financeFeed' => FinanceFeedHealth::fromReport($row->last_report),
            ],
            'history' => $history,
            // Daftar formulir "Minta operasi", dari server. `install` tidak ada di sana — lihat
            // `SiteOperation::MANUAL_OPERATIONS` — walaupun riwayat di bawahnya dapat memuatnya.
            'operations' => SiteOperation::MANUAL_OPERATIONS,
            'releases' => $releases,
            'audit' => $audit,
            'licenseKeyConfigured' => config('sites.license_private_key_path') !== null
                && is_readable((string) config('sites.license_private_key_path')),
            'licenseValidDays' => $terms->forSite($row)['validDays'],
            'enrollment' => session('enrollment'),
        ]);
    }

    /**
     * Bentuk yang sama untuk daftar dan rincian.
     *
     * Waktu dikirim dua kali: `lastSeenAt` untuk dibaca apa adanya, dan `lastSeenIso` — dengan zona waktunya —
     * untuk dihitung layar menjadi "3 menit lalu". Menghitung selisih dari teks tanpa zona waktu membuat
     * peramban di Jakarta membacanya tujuh jam lebih tua dari sebenarnya.
     *
     * @return array<string, mixed>
     */
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
            'serverAddress' => $site->server_address,
            'appUrl' => $site->appUrl(),
            'appUrlAutomatic' => $site->environment_id !== null,
            'dns' => SiteDns::forScreen($site),
            'lastSeenIp' => $site->last_seen_ip,
            'updateWindow' => $site->updateWindow(),
            'reportedRelease' => $site->reported_release,
            'lastSeenAt' => $site->last_seen_at?->toDateTimeString(),
            'lastSeenIso' => $site->last_seen_at?->toIso8601String(),
            'licenseValidUntil' => $site->license_valid_until?->toDateString(),
            // Yang ditampilkan daftar adalah lisensi yang sedang berlaku, bukan setelan yang baru diubah:
            // situs yang ditandai permanen tetapi lisensi permanennya belum diterbitkan masih memakai
            // tanggal lamanya, dan daftar yang menulis "Permanen" di situ menyembunyikan tanggal itu.
            'licensePerpetual' => $site->licenseIssuedPerpetual(),
            'licenseSuspended' => $site->licenseRenewalSuspended(),
        ];
    }

    /**
     * Rilis terdaftar terbaru per edisi, dengan satu query untuk seluruh daftar.
     *
     * @return array<string, string>
     */
    private function newestReleases(): array
    {
        $newest = [];

        foreach (SiteRelease::query()->get(['edition', 'release']) as $release) {
            $current = $newest[$release->edition] ?? null;

            if ($current === null || SiteRelease::compare($release->release, $current) > 0) {
                $newest[$release->edition] = $release->release;
            }
        }

        return $newest;
    }

    /**
     * Lingkungan yang dapat diberi server klien dari tombol "Tambah server klien": produksi di server klien
     * yang belum dihapus dan belum punya situs. Aturan yang sama ditegakkan lagi oleh `ClientServerSetup`
     * dan indeks `sites_satu_per_lingkungan`; daftar ini hanya supaya pilihan yang pasti ditolak tidak
     * pernah ditawarkan.
     *
     * @return Builder<Environment>
     */
    private function candidateQuery(): Builder
    {
        return Environment::query()
            ->with('tenant:id,name')
            ->where('kind', 'production')
            ->where('hosting', 'client_server')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('sites')->whereColumn('sites.environment_id', 'environments.id'));
    }
}
