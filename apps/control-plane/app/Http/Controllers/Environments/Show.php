<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Environments\EnvironmentRejected;
use ControlPlane\Environments\FleetFromCore;
use ControlPlane\Environments\InstalledModules;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\EnvironmentOperation;
use ControlPlane\Models\Site;
use ControlPlane\Sites\ClientServerSetup;
use ControlPlane\Sites\InstallProgress;
use ControlPlane\Sites\SiteDns;
use ControlPlane\Sites\SiteOperations;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Satu lingkungan beserta riwayat operasinya.
 *
 * Riwayatnya dibatasi 50 baris terakhir. Halaman ini dibaca ketika ada yang ingin diketahui
 * sekarang — "kenapa ia begini" — dan jawaban itu hampir selalu ada di baris teratas.
 *
 * ## Produksi di server klien
 *
 * Lingkungan `client_server` tercatat di sini tetapi isinya tidak ada di server kami. Tombol "Siapkan"
 * dan daftar module membaca database pooled — bukan milik lingkungan ini — jadi keduanya tidak dikirim,
 * dan tempatnya diambil panel "Server klien" (`serverClient`). Panel itu dimuat ulang sebagian secara
 * berkala oleh layar selama pemasangannya belum mencapai keadaan akhir; karena itu ia sebuah closure,
 * supaya muat ulang parsial tidak ikut membaca riwayat dan module.
 */
class Show extends Controller
{
    public function __invoke(Request $request, string $environment, InstalledModules $modules, SiteOperations $operations, FleetFromCore $fleet): InertiaResponse
    {
        $row = Environment::query()
            ->with('tenant:id,name,slug')
            ->whereKey($environment)
            ->firstOrFail();

        $onClientServer = $row->hosting === 'client_server';

        $moduleResult = function () use ($onClientServer, $modules, $row): array {
            return once(function () use ($onClientServer, $modules, $row): array {
                try {
                    return ['items' => $onClientServer ? [] : $modules($row), 'error' => null];
                } catch (EnvironmentRejected $failure) {
                    return ['items' => [], 'error' => $failure->getMessage()];
                }
            });
        };

        return Inertia::render('environments/show', [
            'environment' => fn (): array => $row->forScreen() + [
                'ownDatabase' => $row->database_name !== null,
                'createdAt' => $row->created_at?->toDateTimeString(),
            ],
            'history' => fn (): array => $this->history($row),
            'installedRelease' => fn (): array => $this->installedRelease($row, $fleet),
            // Dibaca dari database lingkungan itu, bukan disimpulkan dari entitlement tenantnya.
            // Entitlement menjawab apa yang boleh ada; hanya tabel di dalam databasenya yang
            // menjawab apa yang benar-benar ada — dan selisih keduanya persis yang dicari operator
            // ketika ia bertanya kenapa sebuah demo terasa kosong.
            'modules' => fn (): array => $moduleResult()['items'],
            'modulesError' => fn (): ?string => $moduleResult()['error'],
            // Sama persis dengan daftar status yang diterima `environment:provision`. Ditulis di
            // sini supaya tombolnya tidak pernah muncul untuk keadaan yang akan ditolak Core —
            // tombol yang selalu terlihat lalu selalu gagal melatih orang mengabaikan pesannya.
            // Lingkungan di server klien lahir `provisioning` dan Core menolak menyiapkannya.
            'copyState' => fn (): ?array => $this->copyState($row),
            'canProvision' => fn (): bool => ! $onClientServer && $row->source_environment_id === null && in_array($row->status, ['provisioning', 'degraded'], true),
            'serverClient' => fn (): ?array => $row->runsOnClientServer() ? $this->serverClient($row, $operations) : null,
            'installCommand' => fn (): ?array => $this->installCommand($request),
        ]);
    }

    /** @return array{sourceId:string,targetId:?string,name:string,canRequest:bool,pending:bool,status:string,reason:?string}|null */
    private function copyState(Environment $row): ?array
    {
        if ($row->kind === 'production') {
            $source = $row;
            $target = Environment::query()->where('tenant_id', $row->tenant_id)->where('kind', 'sandbox')->whereNull('deleted_at')->first();
        } elseif ($row->kind === 'sandbox' && $row->source_environment_id !== null) {
            $source = Environment::query()->whereKey($row->source_environment_id)->where('tenant_id', $row->tenant_id)->whereNull('deleted_at')->first();
            $target = $row;
        } else {
            return null;
        }

        $reason = null;
        if (! $source instanceof Environment || $source->kind !== 'production' || $source->status !== 'active'
            || $source->hosting !== 'provider' || $source->database_name === null) {
            $reason = 'Penyalinan memerlukan production aktif di server kita dengan database sendiri.';
        } elseif ($target instanceof Environment && $target->source_environment_id !== $source->id) {
            $reason = 'Tenant sudah punya sandbox lain. Penyalinan tidak menimpanya.';
        } elseif ($target?->status === 'active') {
            $reason = 'Sandbox sudah aktif. Penyalinan tidak menimpa sandbox yang sedang dipakai.';
        }
        $operation = $target instanceof Environment
            ? EnvironmentOperation::query()->where('environment_id', $target->id)->where('operation', 'copy')->latest('id')->first()
            : null;
        $expired = $operation?->status === 'running' && $operation->lease_until !== null && $operation->lease_until->isPast();
        $pending = $target?->status === 'copying' && ! $expired;
        $status = match (true) {
            $target?->status === 'active' => 'Sandbox aktif',
            $expired => 'Penyalinan terhenti',
            $pending && $operation?->status === 'running' => 'Sedang menyalin',
            $pending => 'Menunggu giliran',
            $target?->status === 'degraded' => 'Penyalinan perlu dilanjutkan',
            default => 'Sandbox dari production belum dibuat',
        };

        return [
            'sourceId' => $source?->id ?? (string) $row->source_environment_id,
            'targetId' => $target?->id, 'name' => $target?->name ?? '',
            'canRequest' => $reason === null && ! $pending,
            'pending' => $pending, 'status' => $status,
            'reason' => $reason ?? ($target?->status === 'degraded' ? $operation?->failure_message : null),
        ];
    }

    /** @return array{version: ?string, error: ?string} */
    private function installedRelease(Environment $row, FleetFromCore $fleet): array
    {
        if ($row->hosting === 'client_server') {
            return ['version' => $row->site?->reported_release, 'error' => null];
        }

        try {
            return ['version' => $fleet()['platform_release'], 'error' => null];
        } catch (EnvironmentRejected $failure) {
            return ['version' => null, 'error' => $failure->getMessage()];
        }
    }

    /** @return list<array<string, mixed>> */
    private function history(Environment $row): array
    {
        return array_values($row->operations()
            ->with('requester:id,name')
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (EnvironmentOperation $o): array => [
                'id' => $o->id,
                'operation' => $o->operation,
                'status' => $o->status,
                'step' => $o->step,
                'reason' => $o->failure_message,
                'startedAt' => $o->started_at->toDateTimeString(),
                'finishedAt' => $o->finished_at?->toDateTimeString(),
                // Boleh kosong, dan itu bukan data hilang: sebuah operasi memang dapat dimulai
                // sistem, bukan manusia — penyapuan kedaluwarsa misalnya.
                'requestedBy' => $o->requester->name ?? 'Sistem',
            ])
            ->all());
    }

    /** @return array<string, mixed> */
    private function serverClient(Environment $row, SiteOperations $operations): array
    {
        $site = Site::query()->where('environment_id', $row->id)->first();

        if ($site instanceof Site) {
            // Tenggat yang habis ditutup sekarang, supaya panel tidak menampilkan "Memasang" milik
            // agen yang sudah lama berhenti — sama dengan rincian situs.
            $operations->expireStale($site);
        }

        return [
            'site' => $site instanceof Site ? [
                'id' => $site->id,
                'name' => $site->name,
                'serverAddress' => $site->server_address,
                'lastSeenIp' => $site->last_seen_ip,
                'appUrl' => $row->url(),
                'dns' => SiteDns::forScreen($site->setRelation('environment', $row)),
                'updateWindow' => $site->updateWindow(),
                'enrolledAt' => $site->enrolled_at?->toDateTimeString(),
            ] : null,
            'progress' => InstallProgress::forSite($site),
            'newestRelease' => ClientServerSetup::newestRelease($site->edition ?? Site::SINGLE_IMAGE_EDITION),
        ];
    }

    /**
     * Perintah pasang dan kata sandi sementara yang baru saja dibuat, dibaca satu kali lalu habis.
     *
     * Bentuknya diperiksa, bukan dipercaya: flash yang rusak lebih baik tidak tampil daripada tampil
     * sebagian — kata sandi tanpa emailnya tidak dapat dipakai siapa pun.
     *
     * @return array{command: string, expiresAt: string, email: string, password: string, release: ?string}|null
     */
    private function installCommand(Request $request): ?array
    {
        $flash = $request->session()->get('install_command');

        if (! is_array($flash)) {
            return null;
        }

        foreach (['command', 'expiresAt', 'email', 'password'] as $key) {
            if (! is_string($flash[$key] ?? null)) {
                return null;
            }
        }

        return [
            'command' => $flash['command'],
            'expiresAt' => $flash['expiresAt'],
            'email' => $flash['email'],
            'password' => $flash['password'],
            'release' => is_string($flash['release'] ?? null) ? $flash['release'] : null,
        ];
    }
}
