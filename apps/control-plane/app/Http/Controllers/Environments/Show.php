<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Environments\InstalledModules;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\EnvironmentOperation;
use ControlPlane\Models\Site;
use ControlPlane\Sites\ClientServerSetup;
use ControlPlane\Sites\InstallProgress;
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
    public function __invoke(Request $request, string $environment, InstalledModules $modules, SiteOperations $operations): InertiaResponse
    {
        $row = Environment::query()
            ->with('tenant:id,name,slug')
            ->whereKey($environment)
            ->firstOrFail();

        $onClientServer = $row->hosting === 'client_server';

        return Inertia::render('environments/show', [
            'environment' => fn (): array => $row->forScreen() + [
                'ownDatabase' => $row->database_name !== null,
                'createdAt' => $row->created_at?->toDateTimeString(),
            ],
            'history' => fn (): array => $this->history($row),
            // Dibaca dari database lingkungan itu, bukan disimpulkan dari entitlement tenantnya.
            // Entitlement menjawab apa yang boleh ada; hanya tabel di dalam databasenya yang
            // menjawab apa yang benar-benar ada — dan selisih keduanya persis yang dicari operator
            // ketika ia bertanya kenapa sebuah demo terasa kosong.
            'modules' => fn (): array => $onClientServer ? [] : $modules($row),
            // Sama persis dengan daftar status yang diterima `environment:provision`. Ditulis di
            // sini supaya tombolnya tidak pernah muncul untuk keadaan yang akan ditolak Core —
            // tombol yang selalu terlihat lalu selalu gagal melatih orang mengabaikan pesannya.
            // Lingkungan di server klien lahir `provisioning` dan Core menolak menyiapkannya.
            'canProvision' => fn (): bool => ! $onClientServer && in_array($row->status, ['provisioning', 'degraded'], true),
            'serverClient' => fn (): ?array => $row->runsOnClientServer() ? $this->serverClient($row, $operations) : null,
            'installCommand' => fn (): ?array => $this->installCommand($request),
        ]);
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
                'address' => $site->address,
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
