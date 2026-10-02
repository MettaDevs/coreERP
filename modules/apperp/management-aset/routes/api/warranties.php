<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\ServiceContract\ServiceContractController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Warranty\AssetWarrantyController;

// Garansi aset dan kontrak servis vendor. `vendor` dan `akan-berakhir` didahulukan agar `{id}` tidak
// menelannya. Pemberitahuan garansi untuk work order berada di bawah alamat work order karena dijaga
// permission work order.
Route::get('garansi-aset', [AssetWarrantyController::class, 'index']);
Route::post('garansi-aset', [AssetWarrantyController::class, 'store']);
Route::get('garansi-aset/vendor', [AssetWarrantyController::class, 'vendor']);
Route::get('garansi-aset/akan-berakhir', [AssetWarrantyController::class, 'expiring']);
Route::patch('garansi-aset/{id}', [AssetWarrantyController::class, 'update']);
Route::delete('garansi-aset/{id}', [AssetWarrantyController::class, 'destroy']);
Route::get('pemeliharaan-aset/referensi/garansi', [AssetWarrantyController::class, 'forWorkOrder']);
Route::get('kontrak-servis', [ServiceContractController::class, 'index']);
Route::post('kontrak-servis', [ServiceContractController::class, 'store']);
Route::get('kontrak-servis/{id}', [ServiceContractController::class, 'show']);
Route::patch('kontrak-servis/{id}', [ServiceContractController::class, 'update']);
Route::delete('kontrak-servis/{id}', [ServiceContractController::class, 'destroy']);
