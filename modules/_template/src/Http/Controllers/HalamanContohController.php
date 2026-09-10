<?php

declare(strict_types=1);

namespace Modules\PenerbitContoh\ChangeMe\Http\Controllers;

use App\Support\Modules\Contracts\KonteksPermintaan;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PenerbitContoh\ChangeMe\Models\Contoh;

/**
 * Layar module, bukan endpoint JSON: layar dan datanya datang dalam satu jawaban dari proses
 * yang sama. Tidak ada iframe, tidak ada aplikasi React kedua, dan tidak ada token yang perlu
 * dipertukarkan lebih dulu.
 *
 * Nama halaman berbentuk `<id module>::<berkas>` dan diselesaikan pemilih halaman shell ke
 * `modules/penerbit-contoh/change-me/ui/Pages/Daftar.tsx`. Penerbit tidak ikut disebut; id
 * module sudah unik di seluruh runtime.
 *
 * Dua hal yang menjadi pola, bukan kebetulan:
 *
 * 1. **Izin diperiksa lewat `KonteksPermintaan`, bukan dibaca dari permintaan.** Konteksnya
 *    diisi middleware `konteks-module` yang dipasang pada grup rute module.
 * 2. **`tenant_id` tidak disebut satu kali pun di sini.** `MilikTenant` pada modelnya yang
 *    menyaring, dan penyaringan itu gagal-menutup: tanpa tenant aktif, query dibatalkan
 *    alih-alih dijalankan tanpa saringan. Menuliskan `where('tenant_id', ...)` sendiri hanya
 *    menambah tempat yang bisa lupa ditulis.
 */
final class HalamanContohController
{
    public function __invoke(KonteksPermintaan $akses): Response
    {
        abort_unless($akses->punyaIzin('change-me.contoh.read'), 403);

        return Inertia::render('change-me::Daftar', [
            'contoh' => Contoh::query()
                ->orderBy('kode')
                ->get(['id', 'kode', 'nama']),
        ]);
    }
}
