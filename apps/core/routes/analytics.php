<?php

use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Analytics\Http\Controllers\DashboardController;
use App\Platform\Analytics\Http\Controllers\DashboardPageController;
use App\Platform\Analytics\Http\Controllers\DatasetController;
use App\Platform\Analytics\Http\Controllers\ExploreController;
use App\Platform\Analytics\Http\Controllers\QueryController;
use App\Platform\Analytics\Http\Controllers\SavedQueryController;
use App\Platform\Analytics\Http\Controllers\WidgetController;
use App\Platform\Analytics\Http\Controllers\WidgetDataController;
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

// Area 6: penyimpanan dasbor dan API layar. Melihat dijaga `dashboard.read` untuk seluruh blok, membuat juga
// `dashboard.create`; mengubah dan mengarsipkan diputuskan `DashboardAccess` di controller, karena dasbor pribadi
// dan bersama butuh permission berbeda. Id dasbor, widget, dan query tersimpan dicari di tenant aktif saja
// (`BindsWithinActiveTenant`), jadi id tenant lain 404 sebelum controller berjalan. Rate limit
// `analytics-interactive` untuk data widget menyusul bersama limiternya di area 9.
Route::middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::ANALYTICS_DASHBOARD_READ))->group(function (): void {
    // Halaman Shell; komponennya dibuat area 7.
    Route::get('analytics', [DashboardPageController::class, 'index'])->name('analytics.index');
    Route::get('analytics/dashboards/{dashboard}', [DashboardPageController::class, 'show'])->name('analytics.dashboards.show');

    Route::prefix('api/v1/analytics')->name('api.analytics.')->group(function (): void {
        Route::get('datasets', [DatasetController::class, 'index'])->name('datasets.index');
        Route::get('datasets/{code}', [DatasetController::class, 'show'])->name('datasets.show');

        Route::get('dashboards', [DashboardController::class, 'index'])->name('dashboards.index');
        Route::post('dashboards', [DashboardController::class, 'store'])
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::ANALYTICS_DASHBOARD_CREATE))
            ->name('dashboards.store');
        Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show');
        Route::patch('dashboards/{dashboard}', [DashboardController::class, 'update'])->name('dashboards.update');
        Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])->name('dashboards.destroy');

        Route::post('dashboards/{dashboard}/widgets', [WidgetController::class, 'store'])->name('widgets.store');
        Route::patch('widgets/{widget}', [WidgetController::class, 'update'])->name('widgets.update');
        Route::delete('widgets/{widget}', [WidgetController::class, 'destroy'])->name('widgets.destroy');
        Route::get('widgets/{widget}/data', [WidgetDataController::class, 'show'])->name('widgets.data');
        Route::post('widgets/{widget}/refresh', [WidgetDataController::class, 'refresh'])->name('widgets.refresh');

        Route::get('saved-queries', [SavedQueryController::class, 'index'])->name('saved-queries.index');
        Route::post('saved-queries', [SavedQueryController::class, 'store'])
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::ANALYTICS_DASHBOARD_CREATE))
            ->name('saved-queries.store');
        Route::get('saved-queries/{savedQuery}', [SavedQueryController::class, 'show'])->name('saved-queries.show');
        Route::patch('saved-queries/{savedQuery}', [SavedQueryController::class, 'update'])->name('saved-queries.update');
        Route::delete('saved-queries/{savedQuery}', [SavedQueryController::class, 'destroy'])->name('saved-queries.destroy');
    });
});
