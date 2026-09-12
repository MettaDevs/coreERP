<?php

declare(strict_types=1);

namespace ControlPlane\Environments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Menyuruh Core mengantrekan pembaruan — satu lingkungan, atau semua yang tertinggal.
 *
 * ## Kenapa tenggatnya `core.timeout` dan bukan `core.provision_timeout`
 *
 * Karena yang ditunggu di sini bukan pekerjaannya, melainkan **penerimaannya**. Core memulangkan
 * 202 seketika sesudah job-nya masuk antrean, jadi panggilan ini selesai dalam hitungan milidetik
 * berapa pun jumlah lingkungannya. Menaikkan tenggatnya ke lima menit tidak membuat satu pun
 * pembaruan lebih mungkin berhasil; ia hanya membuat konsol menggantung lima menit ketika Core
 * mati.
 *
 * Inilah bedanya dengan {@see ProvisionViaCore}, yang memang menunggu pekerjaannya selesai.
 *
 * ## Kemajuannya dibaca dari layar, bukan dari balasan ini
 *
 * Balasan ini hanya menyebut **berapa yang diantrekan**. Business Central menyatakan aturan yang
 * sama sebagai aturan — yang dipantau adalah status operasinya, bukan daftar environmentnya — dan
 * di sini `GET /fleet` yang menjawabnya, dimuat ulang oleh layar Pembaruan selama masih ada
 * operasi yang berjalan.
 */
final class QueueUpgradeViaCore
{
    /**
     * @param  ?string  $environmentId  Kosong berarti seluruh armada yang tertinggal.
     * @param  ?int  $requestedBy  Id operator yang menekan tombolnya; kosong berarti riwayatnya "Sistem".
     * @param  bool  $force  Antrekan juga yang sidiknya sudah sama dengan image.
     * @return array{queued: list<string>, queued_count: int}
     *
     * @throws EnvironmentRejected Core menjawab, dan jawabannya "tidak".
     */
    public function __invoke(?string $environmentId, ?int $requestedBy = null, bool $force = false): array
    {
        $base = rtrim((string) config('core.base_url'), '/').'/api/internal/v1/environments';
        $endpoint = $environmentId === null
            ? $base.'/upgrade'
            : $base.'/'.$environmentId.'/upgrade';

        $body = array_filter([
            'requested_by' => $requestedBy,
            'force' => $force ?: null,
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $response = Http::withToken((string) config('core.token'))
                ->acceptJson()
                ->timeout(max(1, (int) config('core.timeout')))
                ->post($endpoint, $body);
        } catch (ConnectionException $disconnected) {
            throw new EnvironmentRejected(
                'Core tidak menjawab di '.$endpoint.' dalam batas waktu, jadi tidak ada yang '
                .'diantrekan. Periksa COREERP_URL di konsol ini. Pesan aslinya: '
                .$disconnected->getMessage(),
                previous: $disconnected,
            );
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if ($response->failed()) {
            throw new EnvironmentRejected($this->reason($response->status(), $payload, $endpoint));
        }

        $queued = [];

        foreach (is_array($payload['queued'] ?? null) ? $payload['queued'] : [] as $id) {
            if (is_string($id) && $id !== '') {
                $queued[] = $id;
            }
        }

        return [
            'queued' => $queued,
            /*
             * Dihitung dari daftarnya, bukan dibaca dari `queued_count`. Keduanya datang dari
             * jawaban yang sama dan seharusnya selalu cocok — tetapi yang dipakai layar untuk
             * berkata "tidak ada yang perlu diperbarui" hanya boleh satu, dan yang benar adalah
             * yang diturunkan dari isi.
             */
            'queued_count' => count($queued),
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

        return 'Core menolak mengantrekan pembaruannya dengan HTTP '.$status.' dari '.$endpoint
            .', tanpa menyebut alasan.';
    }
}
