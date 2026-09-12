<?php

declare(strict_types=1);

namespace ControlPlane\Environments;

use ControlPlane\Customers\CreateCustomer;
use ControlPlane\Models\Environment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Menyuruh Core menyiapkan database sebuah lingkungan, lalu membacakan hasilnya.
 *
 * Bentuknya sengaja sama dengan {@see CreateCustomer}: konsol ini memerintah, Core yang mengerjakan.
 * Yang tahu cara membuat database, menjalankan migration Core, membaca registry module, dan
 * menyemai data awalnya hanya Core — dan menyalin pengetahuan itu ke sini berarti dua tempat yang
 * akan menyimpang.
 *
 * ## Kenapa tenggatnya sendiri, bukan `core.timeout`
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
final class ProvisionViaCore
{
    /**
     * @param  ?int  $requestedBy  Id operator yang menekan tombolnya; kosong berarti riwayatnya "Sistem".
     * @return array{status: string, database: ?string, modules: list<array{id: string, version: string, status: string, seeded: bool}>}
     *
     * @throws EnvironmentRejected Core menjawab, dan jawabannya "tidak".
     */
    public function __invoke(Environment $environment, ?int $requestedBy = null): array
    {
        $endpoint = rtrim((string) config('core.base_url'), '/')
            .'/api/internal/v1/environments/'.$environment->id.'/provision';

        try {
            $response = Http::withToken((string) config('core.token'))
                ->acceptJson()
                ->timeout(max(1, (int) config('core.provision_timeout')))
                // Siapa yang menekan tombolnya ikut dikirim supaya kolom "Oleh" pada riwayat
                // operasi menyebut orangnya. Tanpa ini ia berbunyi "Sistem" — jawaban yang benar
                // untuk penjadwal, dan jawaban yang salah untuk tombol.
                ->post($endpoint, $requestedBy === null ? [] : ['requested_by' => $requestedBy]);
        } catch (ConnectionException $disconnected) {
            throw new EnvironmentRejected(
                'Core tidak menjawab di '.$endpoint.' dalam batas waktu. Penyiapannya mungkin masih '
                .'berjalan di sana — muat ulang halaman ini sebelum mencoba lagi. Pesan aslinya: '
                .$disconnected->getMessage(),
                previous: $disconnected,
            );
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if ($response->failed()) {
            throw new EnvironmentRejected($this->reason($response->status(), $payload, $endpoint));
        }

        return [
            'status' => is_string($payload['status'] ?? null) ? $payload['status'] : $environment->status,
            'database' => is_string($payload['database'] ?? null) ? $payload['database'] : null,
            'modules' => $this->modules($payload['modules'] ?? null),
        ];
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function reason(int $status, array $payload, string $endpoint): string
    {
        if ($status === 401 || $status === 403) {
            return 'Core menolak kunci konsol ini (HTTP '.$status.' dari '.$endpoint.'). Nilai '
                .'CONTROL_PLANE_TOKEN di sini harus sama persis dengan yang diperiksa Core.';
        }

        $message = $payload['message'] ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'Core menolak penyiapannya dengan HTTP '.$status.' dari '.$endpoint
            .', tanpa menyebut alasan.';
    }

    /**
     * Membersihkan daftar module yang datang lewat jaringan.
     *
     * Isinya dari aplikasi lain, jadi diperlakukan bahan mentah: baris yang bentuknya tidak
     * dikenali dilewati, bukan dipaksa. Satu baris aneh tidak boleh menjatuhkan seluruh jawaban —
     * kalau ia jatuh, yang hilang justru bukti bahwa penyiapannya berhasil.
     *
     * @return list<array{id: string, version: string, status: string, seeded: bool}>
     */
    private function modules(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                continue;
            }

            $clean[] = [
                'id' => $item['id'],
                'version' => is_string($item['version'] ?? null) ? $item['version'] : '—',
                'status' => is_string($item['status'] ?? null) ? $item['status'] : 'installed',
                'seeded' => ($item['seeded'] ?? false) === true,
            ];
        }

        return $clean;
    }
}
