<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Insurance\AssetInsuranceController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Insurance\InsurancePolicyController;

// Asuransi aset: polis dan pertanggungannya, lalu dua bacaan dari sisi aset. Master jenis asuransi
// terdaftar di `master-data.php`. `vendor` didahulukan agar `{id}` tidak menelannya.
Route::get('polis-asuransi', [InsurancePolicyController::class, 'index']);
Route::post('polis-asuransi', [InsurancePolicyController::class, 'store']);
Route::get('polis-asuransi/vendor', [InsurancePolicyController::class, 'vendor']);
Route::get('polis-asuransi/{id}', [InsurancePolicyController::class, 'show']);
Route::patch('polis-asuransi/{id}', [InsurancePolicyController::class, 'update']);
Route::delete('polis-asuransi/{id}', [InsurancePolicyController::class, 'destroy']);
Route::post('polis-asuransi/{id}/pertanggungan', [InsurancePolicyController::class, 'addCoverage']);
Route::post('polis-asuransi/{id}/pertanggungan/{coverageId}/akhiri', [InsurancePolicyController::class, 'endCoverage']);
Route::post('polis-asuransi/{id}/pertanggungan/{coverageId}/ganti-nilai', [InsurancePolicyController::class, 'replaceCoverage']);
Route::delete('polis-asuransi/{id}/pertanggungan/{coverageId}', [InsurancePolicyController::class, 'archiveCoverage']);
Route::get('asuransi-aset', [AssetInsuranceController::class, 'forAsset']);
Route::get('asuransi-aset/ringkasan', [AssetInsuranceController::class, 'summary']);
