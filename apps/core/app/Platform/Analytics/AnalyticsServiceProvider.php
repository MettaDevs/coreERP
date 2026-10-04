<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Pendaftaran milik engine analitik yang tidak dapat dinyatakan di tempat lain. Rancangannya di
 * `docs/todo/analitik/arsitektur.md` bagian *Susunan berkas*; area 17 menambah rute embed di sini.
 *
 * Area 9: limiter `analytics-interactive` untuk API layar analitik, dihitung **per pengguna**. Limiter bernama
 * punya penghitung sendiri; `throttle:N,M` tanpa nama menghitung per rute, dan batas analitik tidak boleh ikut
 * habis karena layar lain. Tombol Muat ulang yang melewati cache tetap tunduk pada batas ini.
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    public const INTERACTIVE_LIMITER = 'analytics-interactive';

    public function boot(): void
    {
        RateLimiter::for(self::INTERACTIVE_LIMITER, static fn (Request $request): Limit => Limit::perMinute(
            max(1, config()->integer('analytics.rate_limits.interactive_per_minute', 120)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))
            // Bentuk galat yang sama dengan galat analitik lain, supaya layar menampilkan pesannya.
            ->response(static fn (Request $request, array $headers): JsonResponse => response()->json(['error' => [
                'code' => 'analytics.rate_limited',
                'message' => 'Terlalu banyak permintaan analisis dalam satu menit. Tunggu sebentar, lalu coba lagi.',
            ]], 429, $headers)));
    }
}
