<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\TenantProvisioningController;

/*
 * Panggilan balik Core ke module lewat HTTP.
 *
 * Berkas ini terpisah dari `api.php` karena middleware-nya berbeda secara mendasar: rute di
 * sini dipanggil **mesin**, diverifikasi tanda tangan HMAC, dan tidak punya sesi maupun
 * pengguna. Menaruhnya di grup `web` + `auth` bersama rute layar membuatnya dijawab 401 —
 * benar menurut aturan grup itu, salah menurut siapa yang memanggilnya.
 *
 * Keputusan workflow sudah keluar dari sini pada F3-09: ia kini event Laravel yang
 * didengarkan `TerapkanKeputusanDekomisioning`. Penyediaan data awal tenant menyusul pada
 * F3-11, dan berkas ini ikut dihapus di sana.
 */
Route::post('internal/v1/provisioning/tenant', [TenantProvisioningController::class, 'store'])->middleware('coreerp-event');
