<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\ValueAdjustment\AssetValueAdjustmentController;

// Penyesuaian nilai aset: penurunan nilai (write-down) dan kenaikan nilai (appreciation). Pratinjau dan
// posting didahulukan agar `{id}` tidak menelannya; posting adalah POST karena perintah, bukan penyuntingan.
Route::get('penyesuaian-nilai-aset', [AssetValueAdjustmentController::class, 'index']);
Route::post('penyesuaian-nilai-aset', [AssetValueAdjustmentController::class, 'store']);
Route::get('penyesuaian-nilai-aset/{id}/pratinjau-posting', [AssetValueAdjustmentController::class, 'preview']);
Route::post('penyesuaian-nilai-aset/{id}/posting', [AssetValueAdjustmentController::class, 'post']);
Route::get('penyesuaian-nilai-aset/{id}', [AssetValueAdjustmentController::class, 'show']);
Route::patch('penyesuaian-nilai-aset/{id}', [AssetValueAdjustmentController::class, 'update']);
Route::delete('penyesuaian-nilai-aset/{id}', [AssetValueAdjustmentController::class, 'destroy']);
