<?php

use App\Http\Middleware\AuthenticateAppService;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LampirkanKonteksJejak;
use App\Http\Middleware\ResolveModuleContext;
use App\Support\Observabilitas\JejakAktif;
use App\Support\Observabilitas\PelaporKesalahan;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('COREERP_TRUSTED_PROXIES');
        if (is_string($trustedProxies) && $trustedProxies !== '') {
            $middleware->trustProxies(at: $trustedProxies);
        }

        // Nama alias-nya didaftarkan di sini bersama alias lain; yang *memasangnya* adalah
        // penyedia layanan tiap module, per grup rute, karena middleware ini butuh id module
        // sebagai parameter dan tidak ada gunanya dipasang global.
        $middleware->alias([
            'internal-app' => AuthenticateAppService::class,
            'konteks-module' => ResolveModuleContext::class,
        ]);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Global, bukan per grup: rute `internal/v1` yang dipanggil app lain juga membawa
        // tenant (ditulis `AuthenticateAppService`), dan justru panggilan antar-layanan itu
        // yang paling sulit ditelusuri tanpa atribut tenant pada span-nya.
        $middleware->append(LampirkanKonteksJejak::class);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Tidak mengembalikan `false`: pelaporan bawaan — log, dan apa pun yang dipasang
        // setelahnya — tetap berjalan. Yang ditambahkan di sini hanya menyalin kesalahan
        // yang sama ke span, supaya jejak dan log menunjuk kejadian yang sama alih-alih
        // dua kejadian yang harus dicocokkan manual.
        $exceptions->report(function (Throwable $kesalahan): void {
            JejakAktif::catatKesalahan($kesalahan);
        });

        // Pelapor kedua, sengaja tidak digabung dengan yang di atas. Keduanya menjawab
        // pertanyaan berbeda — yang satu menandai span supaya kesalahan terhitung pada grafik,
        // yang satu menyusun laporan yang dibaca orang — dan menggabungkannya berarti satu
        // kegagalan menjatuhkan dua hal yang seharusnya berdiri sendiri.
        $exceptions->report(function (Throwable $kesalahan): void {
            PelaporKesalahan::laporkan($kesalahan, PelaporKesalahan::permintaanSaatIni());
        });
    })->create();
