<?php

declare(strict_types=1);

namespace ControlPlane\Lingkungan;

use ControlPlane\Models\Lingkungan;
use ControlPlane\Pelanggan\BuatPelanggan;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Menyuruh Core menyiapkan database sebuah lingkungan, lalu membacakan hasilnya.
 *
 * Bentuknya sengaja sama dengan {@see BuatPelanggan}: konsol ini memerintah, Core yang mengerjakan.
 * Yang tahu cara membuat database, menjalankan migration Core, membaca registry module, dan
 * menyemai data awalnya hanya Core — dan menyalin pengetahuan itu ke sini berarti dua tempat yang
 * akan menyimpang.
 *
 * ## Kenapa tenggatnya sendiri, bukan `core.tenggat`
 *
 * Melahirkan pelanggan menjalankan migration ke database yang sudah ada. Menyiapkan lingkungan
 * membuat databasenya lebih dulu, menjalankan seluruh migration Core ke dalamnya, lalu memasang
 * setiap module yang dibeli tenantnya — masing-masing dengan migration dan data awalnya sendiri.
 * Ketiga puluh detik yang cukup untuk yang pertama akan memutus yang kedua di tengah jalan.
 *
 * Putusnya tidak membatalkan apa pun di sisi Core: perintahnya tetap berjalan sampai selesai, dan
 * ia memang aman diulang. Tetapi operator yang melihat "gagal" padahal penyiapannya sedang berjalan
 * akan menekan tombolnya lagi, dan yang menahannya hanya kunci operasi — satu lapis, bukan dua.
 */
final class SiapkanLewatCore
{
    /**
     * @param  ?int  $diminta  Id operator yang menekan tombolnya; kosong berarti riwayatnya "Sistem".
     * @return array{status: string, database: ?string, modul: list<array{id: string, versi: string, status: string, disemai: bool}>}
     *
     * @throws LingkunganDitolak Core menjawab, dan jawabannya "tidak".
     */
    public function __invoke(Lingkungan $lingkungan, ?int $diminta = null): array
    {
        $tujuan = rtrim((string) config('core.alamat'), '/')
            .'/api/internal/v1/environments/'.$lingkungan->id.'/siapkan';

        try {
            $respons = Http::withToken((string) config('core.token'))
                ->acceptJson()
                ->timeout(max(1, (int) config('core.tenggat_siapkan')))
                // Siapa yang menekan tombolnya ikut dikirim supaya kolom "Oleh" pada riwayat
                // operasi menyebut orangnya. Tanpa ini ia berbunyi "Sistem" — jawaban yang benar
                // untuk penjadwal, dan jawaban yang salah untuk tombol.
                ->post($tujuan, $diminta === null ? [] : ['diminta_oleh' => $diminta]);
        } catch (ConnectionException $putus) {
            throw new LingkunganDitolak(
                'Core tidak menjawab di '.$tujuan.' dalam batas waktu. Penyiapannya mungkin masih '
                .'berjalan di sana — muat ulang halaman ini sebelum mencoba lagi. Pesan aslinya: '
                .$putus->getMessage(),
                previous: $putus,
            );
        }

        $isi = $respons->json();
        $isi = is_array($isi) ? $isi : [];

        if ($respons->failed()) {
            throw new LingkunganDitolak($this->alasan($respons->status(), $isi, $tujuan));
        }

        return [
            'status' => is_string($isi['status'] ?? null) ? $isi['status'] : $lingkungan->status,
            'database' => is_string($isi['database'] ?? null) ? $isi['database'] : null,
            'modul' => $this->modul($isi['modul'] ?? null),
        ];
    }

    /**
     * @param  array<mixed>  $isi
     */
    private function alasan(int $status, array $isi, string $tujuan): string
    {
        if ($status === 401 || $status === 403) {
            return 'Core menolak kunci konsol ini (HTTP '.$status.' dari '.$tujuan.'). Nilai '
                .'CONTROL_PLANE_TOKEN di sini harus sama persis dengan yang diperiksa Core.';
        }

        $pesan = $isi['message'] ?? null;

        if (is_string($pesan) && $pesan !== '') {
            return $pesan;
        }

        return 'Core menolak penyiapannya dengan HTTP '.$status.' dari '.$tujuan
            .', tanpa menyebut alasan.';
    }

    /**
     * Membersihkan daftar module yang datang lewat jaringan.
     *
     * Isinya dari aplikasi lain, jadi diperlakukan bahan mentah: baris yang bentuknya tidak
     * dikenali dilewati, bukan dipaksa. Satu baris aneh tidak boleh menjatuhkan seluruh jawaban —
     * kalau ia jatuh, yang hilang justru bukti bahwa penyiapannya berhasil.
     *
     * @return list<array{id: string, versi: string, status: string, disemai: bool}>
     */
    private function modul(mixed $mentah): array
    {
        if (! is_array($mentah)) {
            return [];
        }

        $rapi = [];

        foreach ($mentah as $satu) {
            if (! is_array($satu) || ! is_string($satu['id'] ?? null) || $satu['id'] === '') {
                continue;
            }

            $rapi[] = [
                'id' => $satu['id'],
                'versi' => is_string($satu['versi'] ?? null) ? $satu['versi'] : '—',
                'status' => is_string($satu['status'] ?? null) ? $satu['status'] : 'installed',
                'disemai' => ($satu['disemai'] ?? false) === true,
            ];
        }

        return $rapi;
    }
}
