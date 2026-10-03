<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saklar sementara engine analitik (`analytics.enabled`, env `COREERP_ANALYTICS_ENABLED`).
 *
 * Selama rantai izin analitik (KA-14) belum disetujui pemilik produk, belum ada satu pun kode
 * permission analitik — kode yang sudah masuk role tenant tidak dapat diganti diam-diam. Sampai itu,
 * seluruh rute analitik menjawab 404 bila saklar mati, seolah tidak ada.
 *
 * Rutenya tetap terdaftar dan yang memutuskan middleware ini, bukan pendaftaran bersyarat: daftar rute
 * — yang dibaca Wayfinder untuk tipe layar dan di-cache saat container naik — jadi sama di setiap
 * lingkungan, dan saklar dapat dibuktikan dengan test dalam satu proses. Area 4 melepasnya begitu KA-14
 * disetujui.
 */
final class EnsureAnalyticsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config()->boolean('analytics.enabled'), 404);

        return $next($request);
    }
}
