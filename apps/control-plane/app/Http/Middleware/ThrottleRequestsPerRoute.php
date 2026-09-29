<?php

namespace ControlPlane\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;

/**
 * `throttle:N,M` yang menghitung per rute, bukan per pengguna saja.
 *
 * Bawaan Laravel memberi kunci `prefix + sha1(id pengguna)` pada setiap batas angka, sehingga semua rute
 * ber-`throttle:N,M` berbagi satu penghitung per pengguna (atau per IP bila belum masuk): 60 kali simpan
 * vendor ikut menghabiskan jatah rute lain yang batasnya 10 per menit, dan jawaban 429 muncul di rute
 * yang belum disentuh sama sekali. Kelas ini menambahkan metode dan pola URI rute ke kuncinya. Batas bernama
 * (`throttle:integration-client`) tidak berubah: kuncinya ditentukan limiter-nya sendiri.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $scope = $route instanceof Route ? $request->method().'|'.$route->uri() : '';

        return sha1($scope.'|'.parent::resolveRequestSignature($request));
    }
}
