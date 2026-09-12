<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Lingkungan;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Lingkungan;
use ControlPlane\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response as HalamanInertia;

/**
 * Semua lingkungan yang dikenal sistem, satu tabel.
 *
 * Belum ada penyaring maupun halaman. Keduanya menunggu jumlahnya cukup banyak untuk membuat satu
 * layar tidak terbaca lagi — menambahkannya sekarang berarti menebak bentuk penyaring yang belum
 * ada seorang pun yang membutuhkannya.
 */
class Daftar extends Controller
{
    public function __invoke(): HalamanInertia
    {
        $daftar = Lingkungan::query()
            ->with('tenant:id,name,slug')
            ->whereNull('deleted_at')
            ->orderBy('tenant_id')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Lingkungan $l): array => $l->untukLayar())
            ->all();

        return Inertia::render('lingkungan/daftar', [
            'daftar' => $daftar,
            'tenant' => Tenant::pilihan(),
            'jenis' => Lingkungan::JENIS,
        ]);
    }
}
