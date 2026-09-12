<?php

declare(strict_types=1);

namespace ControlPlane\Environments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Keadaan seluruh armada, dibaca dari Core lewat satu panggilan.
 *
 * ## Kenapa lewat HTTP, padahal konsol ini punya koneksi ke database pusat sendiri
 *
 * Karena yang dibaca layar bukan daftar lingkungan melainkan **perbandingan**: sidik skema tiap
 * lingkungan terhadap sidik image Core yang sedang berjalan. Sidik image itu diturunkan dari isi
 * folder `database/migrations` milik Core — folder yang tidak ada di dalam container konsol, dan
 * memang tidak boleh ada. Konsol yang menyalin daftar migration Core adalah konsol yang akan
 * melaporkan "mutakhir" berdasarkan image yang berbeda dari yang sungguhan melayani pelanggan.
 *
 * `Environment` dan `EnvironmentOperation` di konsol ini tetap dibaca langsung untuk layar
 * Lingkungan, dan itu tidak bertentangan: layar itu menjawab "apa yang ada", layar ini menjawab
 * "apakah ia tertinggal". Hanya pertanyaan kedua yang menuntut pengetahuan Core.
 *
 * ## Bentuk yang datang diperlakukan bahan mentah
 *
 * Isinya dari aplikasi lain lewat jaringan. Baris tanpa `id` dilewati karena tidak ada yang dapat
 * dilakukan dengannya — tombolnya tidak punya sasaran. Selebihnya diisi nilai jatuhan, bukan
 * dilewati: baris yang hilang dari layar ini berarti sebuah lingkungan yang **tidak terpantau**,
 * dan itu kegagalan yang lebih mahal daripada satu kolom yang berbunyi "—".
 */
final class FleetFromCore
{
    /**
     * @return array{
     *     platform_fingerprint: string,
     *     counts: array{current: int, behind: int, failed: int, unknown: int},
     *     environments: list<array<string, mixed>>
     * }
     *
     * @throws EnvironmentRejected Core tidak menjawab, atau menjawab sesuatu yang tidak dapat dipakai.
     */
    public function __invoke(): array
    {
        $endpoint = rtrim((string) config('core.base_url'), '/').'/api/internal/v1/fleet';

        try {
            $response = Http::withToken((string) config('core.token'))
                ->acceptJson()
                ->timeout(max(1, (int) config('core.timeout')))
                ->get($endpoint);
        } catch (ConnectionException $disconnected) {
            throw new EnvironmentRejected(
                'Core tidak menjawab di '.$endpoint.'. Periksa COREERP_URL di konsol ini dan '
                .'pastikan runtime Core memang hidup di alamat itu. Pesan aslinya: '
                .$disconnected->getMessage(),
                previous: $disconnected,
            );
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if ($response->failed()) {
            throw new EnvironmentRejected($this->reason($response->status(), $payload, $endpoint));
        }

        $rows = $this->rows($payload['environments'] ?? null);

        return [
            'platform_fingerprint' => is_string($payload['platform_fingerprint'] ?? null)
                ? $payload['platform_fingerprint']
                : '—',
            /*
             * Dihitung ulang dari baris yang benar-benar sampai ke layar, bukan disalin dari kepala
             * jawaban. Kalau sebuah baris dilewati karena bentuknya rusak, angka di kepala halaman
             * harus ikut menyusut — kepala yang menjumlahkan sepuluh di atas tabel berisi sembilan
             * membuat operator mencari baris yang tidak akan pernah ia temukan.
             */
            'counts' => $this->counts($rows),
            'environments' => $rows,
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

        return 'Core menjawab HTTP '.$status.' dari '.$endpoint.', tanpa menyebut alasan.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $raw): array
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
                'tenant' => $this->text($item['tenant'] ?? null, '—'),
                'name' => $this->text($item['name'] ?? null, $item['id']),
                'slug' => $this->text($item['slug'] ?? null, ''),
                'kind' => $this->text($item['kind'] ?? null, 'demo'),
                'status' => $this->text($item['status'] ?? null, 'unknown'),
                'database' => is_string($item['database'] ?? null) ? $item['database'] : null,
                'fingerprint' => is_string($item['fingerprint'] ?? null) ? $item['fingerprint'] : null,
                /*
                 * Jatuh ke `unknown`, bukan ke `current`. Keadaan yang tidak terbaca harus terlihat
                 * sebagai keadaan yang tidak terbaca — memanggilnya mutakhir berarti layar ini
                 * menenangkan operator tentang sesuatu yang tidak ia ketahui.
                 */
                'state' => in_array($item['state'] ?? null, ['current', 'behind', 'failed', 'unknown'], true)
                    ? $item['state']
                    : 'unknown',
                'lastOperation' => $this->operation($item['last_operation'] ?? null),
            ];
        }

        return $clean;
    }

    /**
     * @return ?array{kind: string, status: string, step: ?string, reason: ?string, startedAt: ?string, finishedAt: ?string, requestedBy: ?string}
     */
    private function operation(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        return [
            'kind' => $this->text($raw['kind'] ?? null, '—'),
            'status' => $this->text($raw['status'] ?? null, 'running'),
            'step' => is_string($raw['step'] ?? null) ? $raw['step'] : null,
            'reason' => is_string($raw['reason'] ?? null) ? $raw['reason'] : null,
            'startedAt' => is_string($raw['started_at'] ?? null) ? $raw['started_at'] : null,
            'finishedAt' => is_string($raw['finished_at'] ?? null) ? $raw['finished_at'] : null,
            /*
             * Kosong berarti penjadwal, bukan manusia — dan layar yang menuliskan "Sistem" di kolom
             * ini menghapus satu-satunya keterangan yang membedakan keduanya. Yang memutuskan kata
             * apa yang muncul adalah layarnya, bukan aksi ini.
             */
            'requestedBy' => is_string($raw['requested_by'] ?? null) ? $raw['requested_by'] : null,
        ];
    }

    private function text(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{current: int, behind: int, failed: int, unknown: int}
     */
    private function counts(array $rows): array
    {
        $counts = ['current' => 0, 'behind' => 0, 'failed' => 0, 'unknown' => 0];

        foreach ($rows as $row) {
            /*
             * `match` dengan `default`, bukan `$counts[$state]++`.
             *
             * Yang kedua terbaca lebih pendek dan menambahkan kunci kelima diam-diam begitu sebuah
             * keadaan baru lahir di sisi Core — kunci yang tidak dibaca layar mana pun, sehingga
             * barisnya hilang dari hitungan tanpa seorang pun tahu. Yang ini memaksa keadaan asing
             * mendarat di `unknown`, tempat ia terlihat.
             */
            $counts[match ($row['state'] ?? null) {
                'current' => 'current',
                'behind' => 'behind',
                'failed' => 'failed',
                default => 'unknown',
            }]++;
        }

        return $counts;
    }
}
