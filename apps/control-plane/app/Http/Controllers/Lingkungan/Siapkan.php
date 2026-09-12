<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Lingkungan;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Lingkungan\LingkunganDitolak;
use ControlPlane\Lingkungan\SiapkanLewatCore;
use ControlPlane\Models\Lingkungan;
use ControlPlane\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tombol "Siapkan" pada layar rincian lingkungan.
 *
 * Sampai ini ada, layar itu hanya menampilkan perintah `environment:siapkan` untuk disalin ke
 * terminal — dan itu memutus alurnya tepat di tengah: operator membuat lingkungan dari layar, lalu
 * harus membuka SSH untuk menyalakannya. Alasan yang dulu ditulis di sana sudah tidak berlaku;
 * jalur konsol → Core sudah berdiri dan sudah dipakai pembuatan pelanggan.
 */
class Siapkan extends Controller
{
    public function __invoke(Request $permintaan, string $lingkungan, SiapkanLewatCore $siapkan): RedirectResponse
    {
        $baris = Lingkungan::query()->whereKey($lingkungan)->firstOrFail();
        $operator = $permintaan->user();

        try {
            // Operatornya ikut disebut supaya kolom "Oleh" pada riwayat operasi menyebut orangnya,
            // bukan "Sistem". Riwayat yang menamai penjadwal dan manusia dengan kata yang sama
            // menghapus satu-satunya keterangan yang membedakan keduanya.
            $hasil = $siapkan($baris, $operator instanceof User ? (int) $operator->id : null);
        } catch (LingkunganDitolak $ditolak) {
            /*
             * Dipulangkan sebagai galat di halaman yang sama, bukan halaman 500. Keduanya berarti
             * "tidak jadi", tetapi hanya yang pertama yang menyebut apa yang harus diperbaiki — dan
             * riwayat operasi di halaman itu sering sudah memuat sebab yang lebih rinci.
             *
             * Lewat `withErrors`, bukan lewat kunci flash baru. `errors` sudah dibagikan middleware
             * Inertia bawaan, jadi jalur ini tidak menuntut satu pun prop bersama tambahan — dan
             * prop bersama yang ditambah untuk satu layar adalah prop yang harus diingat setiap
             * layar lain.
             */
            return redirect('/lingkungan/'.$baris->id)
                ->withErrors(['siapkan' => $ditolak->getMessage()]);
        }

        $modul = array_map(static fn (array $satu): string => $satu['id'], $hasil['modul']);

        return redirect('/lingkungan/'.$baris->id)->with('pesan', sprintf(
            'Lingkungan "%s" disiapkan di database "%s". %s',
            $baris->name,
            $hasil['database'] ?? '—',
            $modul === []
                ? 'Tidak ada module yang dibeli tenant ini, jadi hanya skema Core yang dipasang.'
                : 'Module terpasang: '.implode(', ', $modul).'.',
        ));
    }
}
