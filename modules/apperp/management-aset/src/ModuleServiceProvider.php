<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset;

use App\Support\Modules\Contracts\KeputusanWorkflowDiambil;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Apperp\ManagementAset\Http\Middleware\VerifyCoreErpEvent;
use Modules\Apperp\ManagementAset\Listeners\TerapkanKeputusanDekomisioning;
use Modules\Apperp\ManagementAset\Providers\AppServiceProvider;

/**
 * Penyedia layanan module Management Aset.
 *
 * Rutenya dipasang di bawah `api/modules/management-aset`, bukan `api/v1` seperti waktu ia
 * masih aplikasi tersendiri. Alasannya bukan kerapian: Core sudah memakai `api/v1` untuk
 * tujuh belas kelompok rutenya sendiri, dan dua pemilik pada satu ruang nama rute adalah
 * tabrakan yang menunggu tanggal — tabrakan yang muncul sebagai rute yang diam-diam menang,
 * bukan sebagai kesalahan.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Konfigurasi module digabungkan ke konfigurasi Core dengan kunci `management_aset`.
        //
        // Dulu berkas ini dimuat kerangka Laravel milik module sendiri; kerangka itu dihapus
        // pada F3-02, dan sejak saat itu `config('management_aset.…')` memulangkan null tanpa
        // satu pun peringatan. Yang pertama menemukannya bukan pembacaan kode melainkan dua
        // test penyediaan data awal yang gagal dengan "Template maintenance Indonesia tidak
        // tersedia" — pesan yang tidak menyebut konfigurasi sama sekali.
        //
        // `mergeConfigFrom` dipakai supaya setelan yang sudah ada di Core menang; F3-17 yang
        // memutuskan bentuk akhirnya.
        $this->mergeConfigFrom(dirname(__DIR__).'/api/config/management_aset.php', 'management_aset');

        // Definisi laporan module didaftarkan di sini, sama seperti waktu module masih
        // aplikasi tersendiri.
        $this->app->register(AppServiceProvider::class);
    }

    public function boot(): void
    {
        // `loadRoutesFrom` menghormati cache rute; memanggil Route::group di sini tidak.
        // Alias untuk panggilan balik Core lewat HTTP. Dulu didaftarkan `bootstrap/app.php`
        // milik module; berkas itu dihapus pada F3-02 dan aliasnya ikut lenyap tanpa ada yang
        // gagal — rutenya memang belum dimuat siapa pun waktu itu. Rumah yang benar untuknya
        // adalah penyedia layanan module ini.
        //
        // Tersisa satu rute yang memakainya — penyediaan data awal tenant — dan itu menjadi
        // event pada F3-11. Alias ini ikut dibuang di sana.
        $this->app['router']->aliasMiddleware('coreerp-event', VerifyCoreErpEvent::class);

        // Keputusan persetujuan tidak lagi datang sebagai permintaan HTTP. Listener ini
        // berjalan di dalam transaksi keputusan Core, jadi dokumen dan instance workflow
        // berpindah status bersama-sama.
        Event::listen(KeputusanWorkflowDiambil::class, TerapkanKeputusanDekomisioning::class);

        $this->app->booted(function (): void {
            // Grup `web` diperlukan, bukan pilihan gaya: konteks module dibaca dari sesi Core
            // (`CurrentWorkspace`), dan tanpa middleware sesi `Request::session()` melempar
            // "Session store not set on request" — muncul sebagai 500, bukan sebagai 401.
            //
            // Ini konsekuensi langsung dari F3-10: dulu rute ini API bertoken, sekarang ia
            // rute yang dipanggil layar yang penggunanya sudah masuk ke Core.
            // `auth` ada di depan `konteks-module` dengan sengaja: yang memastikan ada
            // pengguna adalah `auth`, dan yang memastikan pengguna itu berhak atas module ini
            // adalah `konteks-module`. Tanpa `auth`, permintaan tanpa sesi jatuh ke middleware
            // konteks dan dijawab 403 — padahal yang benar 401: soalnya identitas, bukan
            // wewenang.
            Route::middleware(['web', 'auth'])
                ->prefix('api/modules/management-aset')
                ->group(dirname(__DIR__).'/routes/api.php');

            // Panggilan balik Core: dipanggil mesin, diverifikasi tanda tangan, tanpa sesi
            // dan tanpa pengguna. Tidak boleh ikut grup `web` + `auth` di atas.
            Route::prefix('api/modules/management-aset')
                ->group(dirname(__DIR__).'/routes/panggilan-balik.php');
        });
    }
}
