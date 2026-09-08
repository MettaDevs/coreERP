<?php

use App\Http\Middleware\RequireCoreErpContext;
use App\Http\Middleware\VerifyCoreErpEvent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    // Service ini hanya menyajikan API. UI app adalah artifact terpisah pada
    // `ui/`, jadi tidak ada route web, session, atau view di sini.
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'coreerp' => RequireCoreErpContext::class,
            'coreerp-event' => VerifyCoreErpEvent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
