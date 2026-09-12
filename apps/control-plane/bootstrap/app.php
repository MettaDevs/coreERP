<?php

use ControlPlane\Http\Middleware\HandleInertiaRequests;
use ControlPlane\Http\Middleware\OperatorOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
 * Konsol operator vendor.
 *
 * Ia sengaja kurus: tanpa migration sendiri, tanpa antrean, tanpa broadcast. Skema yang dibacanya
 * milik Core dan hanya Core yang boleh mengubahnya — kalau konsol ini ikut punya migration, dua
 * aplikasi akan berebut satu tabel dan urutannya tidak pernah dapat dipastikan lagi.
 *
 * Session dan cache memakai berkas, bukan database, justru karena alasan yang sama: keduanya akan
 * menuntut tabel, dan tabelnya harus lahir di suatu tempat.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'operator' => OperatorOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
