<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Cancellation\AssetCancellationController;
use Modules\Apperp\ManagementAset\Services\AssetCancellationEngine;

foreach (AssetCancellationEngine::RESOURCES as $resource) {
    Route::get($resource.'/{id}/pratinjau-pembatalan', [AssetCancellationController::class, 'preview'])->defaults('resource', $resource);
    Route::post($resource.'/{id}/batal', [AssetCancellationController::class, 'cancel'])->defaults('resource', $resource);
    Route::post($resource.'/{id}/ajukan-pembatalan', [AssetCancellationController::class, 'submit'])->defaults('resource', $resource);
}
