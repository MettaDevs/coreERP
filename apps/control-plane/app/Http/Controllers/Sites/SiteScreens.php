<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Sites;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Models\SiteRelease;
use ControlPlane\Models\Tenant;
use ControlPlane\Sites\SiteOperations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Layar Situs: daftar, pembuatan, dan rincian.
 *
 * Tindakan yang mengubah server klien — operasi, token pendaftaran, pencabutan — ada di
 * `SiteActions`, terpisah dari yang hanya membaca, supaya setiap method yang menulis jejak audit
 * terkumpul di satu tempat yang mudah diperiksa.
 */
final class SiteScreens extends Controller
{
    public function index(): InertiaResponse
    {
        $sites = Site::query()
            ->with('tenant:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site): array => $this->row($site))
            ->all();

        return Inertia::render('sites/index', [
            'sites' => $sites,
            'tenants' => Tenant::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:100'],
            'edition' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'address' => ['nullable', 'url:https,http', 'max:255'],
            'connectivity' => ['required', 'in:'.implode(',', Site::CONNECTIVITIES)],
            'update_window_start' => ['nullable', 'date_format:H:i', 'required_with:update_window_end'],
            'update_window_end' => ['nullable', 'date_format:H:i', 'required_with:update_window_start'],
        ], [
            'edition.regex' => 'Edisi ditulis huruf kecil, angka, dan tanda hubung — sama dengan nama berkas di folder editions.',
            'update_window_start.required_with' => 'Jendela pembaruan butuh jam mulai dan jam selesai.',
            'update_window_end.required_with' => 'Jendela pembaruan butuh jam mulai dan jam selesai.',
        ]);

        try {
            $site = DB::transaction(function () use ($request, $input): Site {
                $site = Site::query()->create([
                    ...$input,
                    'profile' => 'managed_on_prem',
                    'timezone' => 'Asia/Jakarta',
                    'created_by' => $request->user()?->getAuthIdentifier(),
                ]);

                OperatorAudit::record($request, 'site.created', 'site', $site->id, [
                    'tenant_id' => $site->tenant_id,
                    'edition' => $site->edition,
                    'connectivity' => $site->connectivity,
                ]);

                return $site;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Tenant ini sudah punya situs dengan nama itu.']);
        }

        return redirect('/situs/'.$site->id)->with('message', 'Situs "'.$site->name.'" tercatat. Buat perintah pasang atau paket pendaftarannya.');
    }

    public function show(string $site, SiteOperations $operations): InertiaResponse
    {
        $row = Site::query()->with('tenant:id,name')->whereKey($site)->firstOrFail();

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
                'address' => $row->address,
                'profile' => $row->profile,
                'updateWindow' => $row->updateWindow(),
                'enrolledAt' => $row->enrolled_at?->toDateTimeString(),
                'reportedDigest' => $row->reported_digest,
                'lastReport' => $row->last_report,
            ],
            'history' => $history,
            'releases' => $releases,
            'audit' => $audit,
            'licenseKeyConfigured' => config('sites.license_private_key_path') !== null
                && is_readable((string) config('sites.license_private_key_path')),
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
            'connectivity' => $site->connectivity,
            'state' => match (true) {
                $site->revoked() => 'revoked',
                ! $site->enrolled() => 'not_enrolled',
                $site->stale() => 'stale',
                default => 'enrolled',
            },
            'reportedRelease' => $site->reported_release,
            'lastSeenAt' => $site->last_seen_at?->toDateTimeString(),
            'lastSeenVia' => $site->last_seen_via,
        ];
    }
}
