<?php

declare(strict_types=1);

use ControlPlane\Http\Controllers\Account;
use ControlPlane\Http\Controllers\Customers\Index as CustomerIndex;
use ControlPlane\Http\Controllers\Customers\Store as CustomerStore;
use ControlPlane\Http\Controllers\Environments\Index as EnvironmentIndex;
use ControlPlane\Http\Controllers\Environments\Provision as EnvironmentProvision;
use ControlPlane\Http\Controllers\Environments\Show as EnvironmentShow;
use ControlPlane\Http\Controllers\Environments\Store as EnvironmentStore;
use ControlPlane\Http\Controllers\Installer\InstallerFiles;
use ControlPlane\Http\Controllers\Login;
use ControlPlane\Http\Controllers\Logout;
use ControlPlane\Http\Controllers\Sites\SiteActions;
use ControlPlane\Http\Controllers\Sites\SiteScreens;
use ControlPlane\Http\Controllers\Sso\SsoBackchannelLogout;
use ControlPlane\Http\Controllers\Sso\SsoLogin;
use ControlPlane\Http\Controllers\Updates\Index as UpdateIndex;
use ControlPlane\Http\Controllers\Updates\Upgrade as UpdateUpgrade;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/lingkungan');

/*
 * Berkas pemasang untuk server klien. Tanpa login: isinya bukan rahasia, dan yang membuat pemasangan
 * sah adalah token pendaftaran berumur satu jam. Alasan lengkapnya di InstallerFiles.
 *
 * `kunci-rilis.pub` didaftarkan sebelum `{berkas}`, supaya namanya tidak pernah ditangkap pola umum.
 */
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/pasang.sh', [InstallerFiles::class, 'installer'])->name('installer.script');
    Route::get('/agen/kunci-rilis.pub', [InstallerFiles::class, 'releaseKey'])->name('installer.release-key');
    Route::get('/agen/{berkas}', [InstallerFiles::class, 'file'])
        ->whereIn('berkas', array_keys(InstallerFiles::FILES))
        ->name('installer.file');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [Login::class, 'form'])->name('login');
    Route::post('/login', [Login::class, 'submit']);
    Route::get('/sso/masuk', [SsoLogin::class, 'start'])->middleware('throttle:30,1')->name('sso.start');
});

Route::post('/logout', Logout::class)->middleware('auth');

/*
 * Alamat balik penyedia dan logout back-channel. Tidak di dalam `guest` maupun `auth`: alamat balik
 * juga menyelesaikan upacara "hubungkan" milik operator yang sedang masuk, dan logout back-channel
 * dipanggil server penyedia tanpa sesi apa pun. Keduanya menjawab 404 selama SSO tidak disetel.
 */
Route::get('/sso/callback', [SsoLogin::class, 'callback'])->middleware('throttle:30,1')->name('sso.callback');
Route::post('/sso/backchannel-logout', SsoBackchannelLogout::class)->middleware('throttle:60,1')->name('sso.backchannel-logout');

/*
 * Setiap alamat di bawah lewat `auth` DAN `operator`.
 *
 * Keduanya, bukan salah satu. `auth` hanya menjawab "ini siapa"; yang menjawab "ia boleh di sini"
 * adalah `operator`. Pola yang justru harus dihindari ada di Core hari ini: satu rute katalog
 * terdaftar tanpa middleware apa pun sambil memulangkan nama database, sementara halaman yang
 * menampilkan data yang sama dijaga gate.
 */
Route::middleware(['auth', 'operator'])->group(function (): void {
    Route::get('/tenant', CustomerIndex::class)->name('customers.index');
    Route::post('/tenant', CustomerStore::class)->name('customers.store');
    // Alamat lama, sebelum layar ini berganti nama menjadi Tenant pada 14 September 2026. Penanda
    // buku dan tautan yang sudah dibagikan tetap sampai.
    Route::redirect('/pelanggan', '/tenant');

    Route::get('/lingkungan', EnvironmentIndex::class)->name('environments.index');
    Route::post('/lingkungan', EnvironmentStore::class)->name('environments.store');
    Route::get('/lingkungan/{lingkungan}', EnvironmentShow::class)->name('environments.show');
    Route::post('/lingkungan/{lingkungan}/siapkan', EnvironmentProvision::class)->name('environments.provision');

    /*
     * Satu controller melayani kedua tombol, karena yang membedakannya hanya ada atau tidaknya satu
     * id di alamatnya. Alamatnya tetap berbahasa Indonesia seperti seluruh konsol ini — yang
     * berbahasa Inggris nama berkas dan methodnya, bukan yang dibaca operator di bilah alamat.
     */
    Route::get('/pembaruan', UpdateIndex::class)->name('updates.index');
    Route::post('/pembaruan', UpdateUpgrade::class)->name('updates.upgrade');
    Route::post('/pembaruan/{lingkungan}', UpdateUpgrade::class)->name('updates.upgrade-one');

    /*
     * Situs: server milik klien yang dikelola lewat agen. Setiap POST di bawah meminta nama situs
     * diketik ulang dan menulis jejak audit — lihat `SiteActions`.
     */
    Route::get('/situs', [SiteScreens::class, 'index'])->name('sites.index');
    Route::post('/situs', [SiteScreens::class, 'store'])->name('sites.store');
    Route::get('/situs/{situs}', [SiteScreens::class, 'show'])->name('sites.show');
    Route::post('/situs/{situs}/pendaftaran-online', [SiteActions::class, 'issueOnlineEnrollment'])->middleware('throttle:10,1')->name('sites.enrollment.online');
    Route::post('/situs/{situs}/paket-pendaftaran', [SiteActions::class, 'downloadOfflinePackage'])->middleware('throttle:10,1')->name('sites.enrollment.offline');
    Route::post('/situs/{situs}/operasi', [SiteActions::class, 'requestOperation'])->middleware('throttle:30,1')->name('sites.operations.request');
    Route::post('/situs/{situs}/operasi/{operasi}/batal', [SiteActions::class, 'cancelOperation'])->name('sites.operations.cancel');
    Route::post('/situs/{situs}/lisensi-offline', [SiteActions::class, 'downloadOfflineLicense'])->middleware('throttle:10,1')->name('sites.license.offline');
    Route::post('/situs/{situs}/laporan', [SiteActions::class, 'uploadReportFile'])->middleware('throttle:30,1')->name('sites.report.upload');
    Route::post('/situs/{situs}/cabut', [SiteActions::class, 'revoke'])->name('sites.revoke');

    Route::get('/akun', [Account::class, 'show'])->name('account.show');
    Route::post('/akun/sso', [Account::class, 'connect'])->middleware('throttle:10,1')->name('account.sso.connect');
    Route::delete('/akun/sso', [Account::class, 'disconnect'])->middleware('throttle:10,1')->name('account.sso.disconnect');
});
