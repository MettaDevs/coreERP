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
     * @return array{status: string, database: ?string, modules: list<array{id: string, version: string, status: string, seeded: bool}>, modules_error: ?string}
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

        if ($response->status() !== 200 && $response->failed()) {
            throw new EnvironmentRejected($this->reason($response->status(), $payload, $endpoint));
        }

        if ($response->status() !== 200
            || ($payload['status'] ?? null) !== 'active'
            || ! array_key_exists('database', $payload)
            || ($payload['database'] !== null && ! is_string($payload['database']))
            || ! is_array($payload['modules'] ?? null)
            || ! array_is_list($payload['modules'])
            || (isset($payload['modules_error']) && ! is_string($payload['modules_error']))) {
            throw new EnvironmentRejected('Hasil penyiapan belum dapat dipastikan karena jawaban Core tidak lengkap. Penyiapan mungkin sudah berjalan; muat ulang dan periksa riwayat sebelum mencoba lagi.');
        }

        return [
            'status' => $payload['status'],
            'database' => is_string($payload['database'] ?? null) ? $payload['database'] : null,
            'modules' => $this->modules($payload['modules']),
            'modules_error' => $payload['modules_error'] ?? null,
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
     * Jawaban pemasangan harus lengkap agar nilai jatuhan tidak menyamarkan kegagalan membaca.
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
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === ''
                || ! is_string($item['version'] ?? null)
                || ! in_array($item['status'] ?? null, ['installed', 'disabled', 'uninstalled'], true)
                || ! is_bool($item['seeded'] ?? null)) {
                throw new EnvironmentRejected('Hasil pemasangan aplikasi belum dapat dibaca. Periksa riwayat penyiapan sebelum mencoba lagi.');
            }

            $clean[] = [
                'id' => $item['id'],
                'version' => $item['version'],
                'status' => $item['status'],
                'seeded' => $item['seeded'],
            ];
        }

        return $clean;
    }
}
