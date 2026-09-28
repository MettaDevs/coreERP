<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\ReportPreviewController;

Route::get('laporan/{code}', [ReportPreviewController::class, 'show']);
