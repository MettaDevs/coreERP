<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset;

use App\Support\Modules\Contracts\DaftarLaporan;
use App\Support\Modules\Contracts\KeputusanWorkflowDiambil;
use App\Support\Modules\Contracts\TenantDisiapkan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Apperp\ManagementAset\Console\Commands\BangunLayoutLaporanBawaan;
use Modules\Apperp\ManagementAset\Listeners\SiapkanDataAwalTenant;
use Modules\Apperp\ManagementAset\Listeners\TerapkanKeputusanDekomisioning;
use Modules\Apperp\ManagementAset\Reporting\Definitions\WorkOrderDocument;
use Modules\Apperp\ManagementAset\Reporting\Definitions\WorkOrderList;
use Modules\Apperp\ManagementAset\Reporting\PenyediaLaporan;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;

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
        // Konfigurasi module digabungkan ke konfigurasi Core di bawah `modules.management-aset`,
        // bukan di akar.
        //
        // Dulu berkas ini dimuat kerangka Laravel milik module sendiri; kerangka itu dihapus
        // pada F3-02, dan sejak saat itu `config('management_aset.…')` memulangkan null tanpa
        // satu pun peringatan. Yang pertama menemukannya bukan pembacaan kode melainkan dua
        // test penyediaan data awal yang gagal dengan "Template maintenance Indonesia tidak
        // tersedia" — pesan yang tidak menyebut konfigurasi sama sekali.
        //
        // Awalan `modules.` dipakai supaya kunci module tidak berdiri sejajar dengan kunci
        // Core: ruang akar itu milik Core, dan satu module yang menaruh namanya di sana
        // memberi izin diam-diam kepada module berikutnya untuk menimpa `database`, `cache`,
        // atau `mail` hanya dengan menamai berkasnya begitu.
        //
        // `mergeConfigFrom` dipakai supaya setelan yang sudah ada di Core menang.
        $this->mergeConfigFrom(dirname(__DIR__).'/config/management-aset.php', 'modules.management-aset');

        // Laporan yang dikenal module ini. Menambah laporan = menambah satu kelas definisi
        // dan mendaftarkannya di sini; layout, ekspor, dan UI-nya mengikuti otomatis.
        //
        // Dulu pendaftaran ini tinggal di `Providers\AppServiceProvider` — nama yang dibawa
        // kerangka aplikasi lama. Isinya cuma blok di bawah ini, jadi ia dilebur ke sini pada
        // F3-21 daripada dipertahankan sebagai penyedia kedua yang tidak menambah apa pun.
        $this->app->singleton(ReportRegistry::class, function (): ReportRegistry {
            $registry = new ReportRegistry;
            $registry->register(new WorkOrderDocument);
            $registry->register(new WorkOrderList);

            return $registry;
        });
    }

    public function boot(): void
    {
        // Perintah artisan module. Tanpa pendaftaran ini `laporan:bangun-layout-bawaan` tidak
        // ada sama sekali: kerangka lama menemukannya lewat pemindaian `app/Console/Commands`
        // miliknya sendiri, dan kerangka itu sudah dibuang. `routes/console.php` memuat
        // `management-aset:seed-maintenance`, yang alamatnya juga hilang bersama kerangka itu.
        if ($this->app->runningInConsole()) {
            $this->commands([BangunLayoutLaporanBawaan::class]);

            // `require`, bukan `loadRoutesFrom`: yang terakhir diam ketika rute HTTP sedang
            // di-cache, dan perintah artisan tidak ada hubungannya dengan cache itu — di image
            // produksi yang menjalankan `route:cache`, seed maintenance akan hilang tanpa jejak.
            require dirname(__DIR__).'/routes/console.php';
        }

        // Laporan module dibaca mesin laporan Core langsung di dalam proses ini. Tanpa
        // pendaftaran ini Core tidak tahu module punya laporan, dan ia jatuh ke jalur HTTP
        // lama — alamat yang sudah tidak ada.
        $this->app->make(DaftarLaporan::class)->daftarkan($this->app->make(PenyediaLaporan::class));

        // Tidak ada lagi alias `coreerp-event`. Dua panggilan balik HTTP yang memakainya —
        // keputusan workflow dan penyediaan data awal tenant — keduanya sudah menjadi event
        // di dalam proses, jadi tidak ada rute tersisa yang perlu memverifikasi tanda tangan.

        // Keputusan persetujuan tidak lagi datang sebagai permintaan HTTP. Listener ini
        // berjalan di dalam transaksi keputusan Core, jadi dokumen dan instance workflow
        // berpindah status bersama-sama.
        Event::listen(KeputusanWorkflowDiambil::class, TerapkanKeputusanDekomisioning::class);

        // Tenant baru: sama, arah masuk. Berjalan di dalam transaksi pendaftaran usaha,
        // sehingga tenant yang tersimpan pasti sudah punya data awalnya.
        Event::listen(TenantDisiapkan::class, SiapkanDataAwalTenant::class);

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

            // Rute layar, terpisah dari rute JSON di atas dan tanpa awalan `api`.
            //
            // Ia dimuat di sini dan bukan oleh Core karena alamat layarnya milik module:
            // Core hanya menyusun tautan sidebar dengan aturan `/<id module>/<id entri menu>`
            // dari manifest, dan module yang memutuskan apa yang terjadi di alamat itu.
            Route::group([], dirname(__DIR__).'/routes/web.php');
        });
    }
}
