<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Environments\InstalledModules;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\EnvironmentOperation;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Satu lingkungan beserta riwayat operasinya.
 *
 * Riwayatnya dibatasi 50 baris terakhir. Halaman ini dibaca ketika ada yang ingin diketahui
 * sekarang — "kenapa ia begini" — dan jawaban itu hampir selalu ada di baris teratas.
 */
class Show extends Controller
{
    public function __invoke(string $environment, InstalledModules $modules): InertiaResponse
    {
        $row = Environment::query()
            ->with('tenant:id,name,slug')
            ->whereKey($environment)
            ->firstOrFail();

        $history = $row->operations()
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
            ->all();

        return Inertia::render('environments/show', [
            'environment' => $row->forScreen() + [
                'ownDatabase' => $row->database_name !== null,
                'createdAt' => $row->created_at?->toDateTimeString(),
            ],
            'history' => $history,
            // Dibaca dari database lingkungan itu, bukan disimpulkan dari entitlement tenantnya.
            // Entitlement menjawab apa yang boleh ada; hanya tabel di dalam databasenya yang
            // menjawab apa yang benar-benar ada — dan selisih keduanya persis yang dicari operator
            // ketika ia bertanya kenapa sebuah demo terasa kosong.
            'modules' => $modules($row),
            // Sama persis dengan daftar status yang diterima `environment:provision`. Ditulis di
            // sini supaya tombolnya tidak pernah muncul untuk keadaan yang akan ditolak Core —
            // tombol yang selalu terlihat lalu selalu gagal melatih orang mengabaikan pesannya.
            'canProvision' => in_array($row->status, ['provisioning', 'degraded'], true),
        ]);
    }
}
