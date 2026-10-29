<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\ModulePageController;

Route::middleware(['web', 'auth', 'konteks-module:procurement'])
    ->prefix('procurement')
    ->name('procurement.')
    ->group(function (): void {
        Route::get('{view}/{tail?}', ModulePageController::class)
            ->where('tail', '.*')
            ->name('page');
    });
