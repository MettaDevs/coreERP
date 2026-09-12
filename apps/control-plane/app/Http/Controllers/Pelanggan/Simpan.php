<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Pelanggan;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Pelanggan\BuatPelanggan;
use ControlPlane\Pelanggan\CoreTidakTerjangkau;
use ControlPlane\Pelanggan\PelangganDitolak;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Simpan extends Controller
{
    public function __invoke(Request $request, BuatPelanggan $buat): RedirectResponse
    {
        $isian = $request->validate([
            'nama_badan_hukum' => ['required', 'string', 'max:150'],
            'nama_admin' => ['required', 'string', 'max:150'],
            'email_admin' => ['required', 'string', 'email', 'max:150'],
            // Bentuknya diperiksa di sini, **ketersediaannya tidak**. Yang tahu app mana tersedia,
            // apa prerequisite-nya, dan apakah ia ada di edisi ini hanyalah Core — dan ia memang
            // memeriksanya saat permintaannya tiba. Menyalin pemeriksaan itu ke sini berarti dua
            // daftar app yang harus tetap sama, dan yang kedua akan basi lebih dulu.
            'app_ids' => ['required', 'array', 'min:1'],
            'app_ids.*' => ['required', 'string', 'max:80'],
        ], [
            'app_ids.required' => 'Pilih setidaknya satu app yang dibeli.',
            'app_ids.min' => 'Pilih setidaknya satu app yang dibeli.',
        ]);

        $app = [];

        foreach (is_array($isian['app_ids']) ? $isian['app_ids'] : [] as $satu) {
            $app[] = (string) $satu;
        }

        try {
            $hasil = $buat(
                $isian['nama_badan_hukum'],
                $isian['nama_admin'],
                $isian['email_admin'],
                $app,
            );
        } catch (PelangganDitolak $ditolak) {
            // Penolakan Core dipulangkan sebagai kesalahan formulir, bukan halaman 500. Keduanya
            // berarti "permintaanmu ditolak", tetapi hanya yang pertama yang memberi tahu sebabnya
            // — dan kalau Core menyebut isian mana yang salah, pesannya mendarat persis di sana.
            throw ValidationException::withMessages(
                $ditolak->perIsian() !== []
                    ? $ditolak->perIsian()
                    : ['core' => $ditolak->getMessage()],
            );
        } catch (CoreTidakTerjangkau $putus) {
            // Kuncinya `core`, bukan salah satu nama isian: yang salah memang bukan isian mana pun,
            // melainkan setelan di luar formulir. Dialog merendernya sebagai spanduk di atas,
            // tempat kalimat sepanjang ini masih terbaca.
            throw ValidationException::withMessages(['core' => $putus->getMessage()]);
        }

        /*
         * Kata sandi sementara dititipkan ke flash session, lalu ditampilkan sekali di daftar.
         *
         * Ia tidak ikut ke alamat dan tidak ikut ke log. Sekali sebuah rahasia masuk query string,
         * ia tinggal di riwayat peramban, di access log, dan di header Referer setiap permintaan
         * berikutnya — tiga tempat yang tidak pernah dibersihkan siapa pun.
         */
        return redirect('/pelanggan')->with('kredensial', [
            'tenant' => $hasil['tenant_id'],
            'nama' => $isian['nama_badan_hukum'],
            'email' => $hasil['email'],
            'kataSandi' => $hasil['kata_sandi_sementara'],
        ]);
    }
}
