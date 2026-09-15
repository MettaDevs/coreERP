<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\Site;
use ControlPlane\Models\Tenant;
use ControlPlane\Sites\InstallProgress;
use ControlPlane\Sites\SiteOperations;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Semua lingkungan yang dikenal sistem, satu tabel.
 *
 * Belum ada penyaring maupun halaman. Keduanya menunggu jumlahnya cukup banyak untuk membuat satu
 * layar tidak terbaca lagi — menambahkannya sekarang berarti menebak bentuk penyaring yang belum
 * ada seorang pun yang membutuhkannya.
 *
 * Produksi di server klien membawa keadaan pemasangannya (`serverClient`), dihitung `InstallProgress`
 * yang sama dengan panel di halaman rinciannya — satu kata di dua layar, bukan dua tafsiran.
 */
class Index extends Controller
{
    public function __invoke(SiteOperations $operations): InertiaResponse
    {
        $rows = Environment::query()
            ->with('tenant:id,name,slug')
            ->whereNull('deleted_at')
            ->orderBy('tenant_id')
            ->orderByDesc('created_at')
            ->get();

        $onClientServer = $rows->filter(fn (Environment $e): bool => $e->runsOnClientServer());
        $sites = Site::query()
            ->whereIn('environment_id', $onClientServer->pluck('id')->all())
            ->get()
            ->keyBy('environment_id');

        foreach ($sites as $site) {
            $operations->expireStale($site);
        }

        $progress = InstallProgress::forSites($sites->all());

        $environments = $rows
            ->map(function (Environment $e) use ($sites, $progress): array {
                $site = $sites->get($e->id);

                return $e->forScreen() + [
                    'serverClient' => $e->runsOnClientServer()
                        ? ($site instanceof Site ? $progress[$site->id] : InstallProgress::forSite(null))
                        : null,
                    // Alamat produksi di server klien milik server itu, bukan domain kita — lihat
                    // `Environment::url()`. Kolom Alamat di daftar membacanya dari sini.
                    'site' => $site instanceof Site ? [
                        'id' => $site->id,
                        'serverAddress' => $site->server_address,
                        'address' => $site->address,
                    ] : null,
                ];
            })
            ->all();

        return Inertia::render('environments/index', [
            'environments' => $environments,
            'tenant' => Tenant::options(),
            'kinds' => Environment::KINDS,
        ]);
    }
}
