<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Http\Controllers;

use App\Support\Modules\Contracts\KonteksPermintaan;
use App\Support\Modules\Contracts\KonteksTenant;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Apperp\ContohA\Models\Barang;

/**
 * Layar module, bukan endpoint JSON.
 *
 * Ini bentuk yang membedakan module dari app lama. App lama menyajikan aplikasi React
 * sendiri di dalam iframe, lalu mengambil datanya lewat permintaan kedua; di sini layar
 * dan datanya datang dalam satu jawaban dari proses yang sama. Tidak ada token yang perlu
 * dipertukarkan lebih dulu, dan tidak ada layar kosong selama permintaan kedua berjalan.
 *
 * Nama halaman berbentuk `<id module>::<berkas>` dan diselesaikan pemilih halaman shell ke
 * `apps/core/tests/Fixtures/modules/apperp/contoh-a/ui/Pages/Daftar.tsx`. Yang menerjemahkannya adalah
 * `resources/js/app.tsx`; module tidak perlu tahu di mana build shell meletakkan
 * potongannya.
 *
 * `BarangController` sengaja tidak disentuh. Ia melayani jalur JSON — yang tetap ada untuk
 * integrasi luar — dan mengubah jawabannya menjadi Inertia akan membuat pemanggil di luar
 * shell menerima HTML tanpa satu pun perubahan status.
 */
final class HalamanBarangController
{
    public function __invoke(KonteksTenant $konteks, KonteksPermintaan $akses): Response
    {
        abort_unless($akses->punyaIzin('contoh-a.barang.read'), 403);

        return Inertia::render('contoh-a::Daftar', [
            'barang' => Barang::query()
                ->where('tenant_id', $konteks->tenantId())
                ->orderBy('kode')
                ->get(['id', 'kode', 'nama']),
        ]);
    }
}
