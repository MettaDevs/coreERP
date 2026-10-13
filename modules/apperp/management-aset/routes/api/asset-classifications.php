<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\master\AssetClassifications\AssetClassificationController;

Route::get('kelompok-harta', [AssetClassificationController::class, 'index']);
Route::post('kelompok-harta', [AssetClassificationController::class, 'store']);
Route::patch('kelompok-harta/{id}', [AssetClassificationController::class, 'update'])->whereUlid('id');
Route::delete('kelompok-harta/{id}', [AssetClassificationController::class, 'destroy'])->whereUlid('id');
