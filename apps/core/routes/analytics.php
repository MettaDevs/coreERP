<?php

use App\Platform\Analytics\Http\Controllers\ExploreController;
use App\Platform\Analytics\Http\Controllers\QueryController;
use App\Platform\Analytics\Http\Middleware\EnsureAnalyticsEnabled;
use Illuminate\Support\Facades\Route;

/*
 * Rute engine analitik: halaman Shell dan API layarnya. Rancangannya di docs/todo/analitik.
 *
 * Di-require satu baris dari grup `auth` di routes/web.php, jadi setiap rute di sini sudah melewati
 * grup `web` (sesi, CSRF) dan `auth`. Halaman tinggal di `/analytics/...`, API layar di
 * `/api/v1/analytics/...` dengan nama rute berawalan `api.analytics.`.
 *
 * Satu blok per area, berkomentar nomor area; area lain hanya menambah blok, tidak menyusun ulang.
 *
 * Semuanya di balik saklar `analytics.enabled` ({@see EnsureAnalyticsEnabled}) selama rantai izin
 * analitik (KA-14) belum disetujui: belum ada permission analitik, jadi hak membaca hanya keanggotaan
 * tenant ditambah permission baca resource dataset (KA-15), diperiksa engine sendiri.
 */
Route::middleware(EnsureAnalyticsEnabled::class)->group(function (): void {
    // Area 0: kerangka berjalan. Halaman sementara dengan satu tile dan satu grafik; area 8 menggantinya.
    Route::get('analytics/explore', ExploreController::class)->name('analytics.explore');

    Route::prefix('api/v1/analytics')->name('api.analytics.')->group(function (): void {
        // Area 0: query bebas atas satu dataset, dijalankan sebagai pengguna yang meminta.
        Route::post('query', QueryController::class)->name('query');
    });
});
