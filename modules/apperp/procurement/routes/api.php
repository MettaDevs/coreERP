<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('konteks-module:procurement')->group(function (): void {
    foreach (glob(__DIR__.'/api/*.php') ?: [] as $routes) {
        require $routes;
    }
});
