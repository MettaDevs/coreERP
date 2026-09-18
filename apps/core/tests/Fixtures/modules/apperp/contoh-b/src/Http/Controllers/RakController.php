<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohB\Http\Controllers;

use App\Support\Modules\Contracts\KonteksPermintaan;
use Illuminate\Http\JsonResponse;
use Modules\Apperp\ContohB\Models\Rak;

/**
 * Rute contoh. Ia ada supaya penjaga batas punya sesuatu untuk diuji.
 *
 * Tiga hal yang ditunjukkan dengan sengaja:
 *
 * 1. Module memanggil Core lewat kontrak, bukan lewat kelas Core langsung. `KonteksTenant`
 *    dan `KonteksPermintaan` adalah dua dari pintu resmi yang didaftar `CoreServices`.
 * 2. Setiap query menyaring `tenant_id`. Tidak ada lagi database terpisah yang menahan
 *    kebocoran, jadi satu query yang lupa menyaring membocorkan data seluruh tenant.
 * 3. Izin yang diperiksa memakai awalan module ini sendiri. Kode izin module lain tidak akan
 *    pernah muncul di sini, karena middleware konteks hanya mengisi izin untuk satu module.
 *
 * Yang tidak boleh: menyebut namespace module lain, termasuk di dalam komentar. Module tidak
 * menyentuh module lain, dan penjaga F1-05 menolaknya — penjaga itu membaca berkas, jadi
 * menuliskan contoh pelanggarannya di sini pun ikut tertangkap.
 */
final class RakController
{
    public function index(KonteksPermintaan $akses): JsonResponse
    {
        abort_unless($akses->punyaIzin('contoh-b.rak.read'), 403);

        return new JsonResponse([
            'data' => Rak::query()
                ->orderBy('kode')
                ->get(['id', 'kode', 'nama']),
        ]);
    }
}
