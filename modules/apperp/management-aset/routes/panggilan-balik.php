<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\TenantProvisioningController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\DekomisioningAset\WorkflowDecisionController;

/*
 * Panggilan balik Core ke module lewat HTTP.
 *
 * Berkas ini terpisah dari `api.php` karena middleware-nya berbeda secara mendasar: rute di
 * sini dipanggil **mesin**, diverifikasi tanda tangan HMAC, dan tidak punya sesi maupun
 * pengguna. Menaruhnya di grup `web` + `auth` bersama rute layar membuatnya dijawab 401 —
 * benar menurut aturan grup itu, salah menurut siapa yang memanggilnya.
 *
 * Keduanya berhenti masuk akal begitu module berada di proses yang sama dengan Core:
 * keputusan workflow menjadi event Laravel pada F3-09, penyediaan data awal tenant pada
 * F3-11. Berkas ini ikut dihapus di sana.
 */
Route::post('internal/v1/workflow-events', [WorkflowDecisionController::class, 'store'])->middleware('coreerp-event');
Route::post('internal/v1/provisioning/tenant', [TenantProvisioningController::class, 'store'])->middleware('coreerp-event');
