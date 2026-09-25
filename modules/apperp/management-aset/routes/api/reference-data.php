<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\ReferenceDataController;

Route::get('reference-data/units-of-measure', [ReferenceDataController::class, 'unitsOfMeasure']);
Route::get('reference-data/kelompok-harta-fiskal', [ReferenceDataController::class, 'fiscalClassifications']);
// Unit kerja dan orang milik Core, supaya layar menampilkan nama dan bukan ULID.
Route::get('reference-data/unit-kerja', [ReferenceDataController::class, 'operatingUnits']);
Route::get('reference-data/anggota', [ReferenceDataController::class, 'members']);
