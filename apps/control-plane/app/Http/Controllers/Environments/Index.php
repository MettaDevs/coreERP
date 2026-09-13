<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Semua lingkungan yang dikenal sistem, satu tabel.
 *
 * Belum ada penyaring maupun halaman. Keduanya menunggu jumlahnya cukup banyak untuk membuat satu
 * layar tidak terbaca lagi — menambahkannya sekarang berarti menebak bentuk penyaring yang belum
 * ada seorang pun yang membutuhkannya.
 */
class Index extends Controller
{
    public function __invoke(): InertiaResponse
    {
        $environments = Environment::query()
            ->with('tenant:id,name,slug')
            ->whereNull('deleted_at')
            ->orderBy('tenant_id')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Environment $e): array => $e->forScreen())
            ->all();

        return Inertia::render('environments/index', [
            'environments' => $environments,
            'tenant' => Tenant::options(),
            'kinds' => Environment::KINDS,
        ]);
    }
}
