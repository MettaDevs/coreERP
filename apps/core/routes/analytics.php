<?php

use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Analytics\Http\Controllers\ExploreController;
use App\Platform\Analytics\Http\Controllers\QueryController;
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
 * Setiap rute dijaga permission analitik-nya sendiri (area 4, rantai izin KA-14) lewat
 * `CoreSecurityCatalog::gate(...)`: langkah 2 urutan otorisasi di docs/todo/analitik/keamanan.md, sebelum
 * engine memeriksa module dataset dan permission baca resource-nya (KA-15). Rute baru wajib menyebut
 * gate-nya; permission per endpoint ada di docs/todo/analitik/dasbor-dan-visual.md bagian *API untuk layar*.
 */

// Area 0: kerangka berjalan. Halaman sementara dengan satu tile dan satu grafik; area 8 menggantinya.
Route::get('analytics/explore', ExploreController::class)
    ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::ANALYTICS_EXPLORE_INVOKE))
    ->name('analytics.explore');

Route::prefix('api/v1/analytics')->name('api.analytics.')->group(function (): void {
    // Area 0: query bebas atas satu dataset, dijalankan sebagai pengguna yang meminta.
    Route::post('query', QueryController::class)
        ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::ANALYTICS_EXPLORE_INVOKE))
        ->name('query');
});
