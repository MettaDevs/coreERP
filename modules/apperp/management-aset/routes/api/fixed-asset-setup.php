<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\master\FixedAssetSetupController;

// Pengaturan aset tetap: satu kartu per tenant, padanan Fixed Asset Setup Business Central.
Route::get('pengaturan-aset-tetap', [FixedAssetSetupController::class, 'show']);
Route::put('pengaturan-aset-tetap', [FixedAssetSetupController::class, 'update']);
