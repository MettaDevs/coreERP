<?php

declare(strict_types=1);

namespace ControlPlane\Dns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien API DNS Cloudflare, dengan token dari {@see CloudflareSettings}.
 *
 * Bentuk permintaan dan jawabannya dicocokkan dengan dokumentasi resmi (`developers.cloudflare.com/api`,
 * September 2026): `GET /zones?name=`, `GET|POST /zones/{zone}/dns_records` dengan saringan `name.exact`,
 * `PUT|DELETE /zones/{zone}/dns_records/{id}`, dan amplop `{success, errors, result}`.
 *
 * ## Token diperiksa lewat zonanya, bukan lewat `/user/tokens/verify`
 *
 * Cloudflare punya dua jenis token dengan dua jalur verifikasi — token milik pengguna di `/user/tokens/verify`
 * dan token milik akun di `/accounts/{id}/tokens/verify`. Keduanya sama-sama dapat membaca zona yang
 * diizinkan kepadanya, dan hanya itu yang dibutuhkan konsol ini. Jadi token dinyatakan sah bila zona domain
 * dasar terlihat olehnya, apa pun jenisnya.
 */
final class CloudflareClient
{
    public function __construct(private readonly CloudflareSettings $settings) {}

    /**
     * Zona Cloudflare yang memuat domain dasar: `erp.grenery.xyz` dicari sebagai `erp.grenery.xyz`, lalu
     * `grenery.xyz`. Zona anak didahulukan karena ia yang berwenang atas namanya bila ada.
     *
     * @return array{id: string, name: string}
     */
    public function zone(): array
    {
        $domain = $this->settings->baseDomain();

        if ($domain === '' || substr_count($domain, '.') < 1) {
            throw new DnsUnavailable('Domain dasar belum disetel di konsol ini (COREERP_BASE_DOMAIN), jadi tidak ada zona DNS untuk alamat server klien.');
        }

        $labels = explode('.', $domain);

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            $result = $this->result($this->send(fn (PendingRequest $http): Response => $http->get('/zones', ['name' => $candidate])), 'membaca zona');

            foreach (is_array($result) ? $result : [] as $zone) {
                if (is_array($zone) && ($zone['name'] ?? null) === $candidate && is_string($zone['id'] ?? null)) {
                    return ['id' => $zone['id'], 'name' => $candidate];
                }
            }
        }

        throw new DnsUnavailable(sprintf('Token Cloudflare tidak melihat zona untuk %s. Pastikan zonanya ada di akun itu dan token punya izin Zone → DNS → Edit untuk zona tersebut.', $domain));
    }

    /**
     * Record A, AAAA, dan CNAME dengan nama persis ini — tiga jenis yang saling meniadakan pada satu nama.
     *
     * @return list<array{id: string, type: string, content: string, comment: ?string}>
     */
    public function records(string $zoneId, string $name): array
    {
        $result = $this->result($this->send(fn (PendingRequest $http): Response => $http->get("/zones/{$zoneId}/dns_records", [
            'name.exact' => $name,
            'per_page' => 100,
        ])), 'membaca record DNS');

        $records = [];

        foreach (is_array($result) ? $result : [] as $record) {
            if (! is_array($record) || ! in_array($record['type'] ?? null, ['A', 'AAAA', 'CNAME'], true)
                || ! is_string($record['id'] ?? null) || ! is_string($record['content'] ?? null)) {
                continue;
            }

            $records[] = [
                'id' => $record['id'],
                'type' => $record['type'],
                'content' => $record['content'],
                'comment' => is_string($record['comment'] ?? null) ? $record['comment'] : null,
            ];
        }

        return $records;
    }

    /**
     * Membuat record, atau menimpa record dengan id itu. `proxied` selalu mati: sertifikat server klien
     * diambil proxy di mesin itu sendiri lewat Let's Encrypt, dan lalu lintas klinik tidak boleh bergantung
     * pada jaringan kita. `ttl` 1 berarti otomatis.
     *
     * @return string id record
     */
    public function put(string $zoneId, ?string $recordId, string $name, string $type, string $content, string $comment): string
    {
        $body = [
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'ttl' => 1,
            'proxied' => false,
            'comment' => $comment,
        ];

        $response = $this->send(fn (PendingRequest $http): Response => $recordId === null
            ? $http->post("/zones/{$zoneId}/dns_records", $body)
            : $http->put("/zones/{$zoneId}/dns_records/{$recordId}", $body));

        $result = $this->result($response, $recordId === null ? 'membuat record DNS' : 'memperbarui record DNS');
        $id = is_array($result) ? ($result['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            throw new DnsUnavailable('Jawaban Cloudflare saat menulis record DNS tidak memuat id record.');
        }

        return $id;
    }

    /** Record yang sudah tidak ada dianggap terhapus, supaya pencabutan yang diulang tidak macet. */
    public function delete(string $zoneId, string $recordId): void
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->delete("/zones/{$zoneId}/dns_records/{$recordId}"));

        if ($response->status() === 404) {
            return;
        }

        $this->result($response, 'menghapus record DNS');
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call): Response
    {
        $token = $this->settings->token();

        if ($token === null) {
            throw new DnsUnavailable('Token Cloudflare belum disetel di konsol ini (php artisan dns:token-cloudflare).');
        }

        $http = Http::baseUrl(rtrim((string) config('sites.cloudflare_api_url'), '/'))
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(15);

        try {
            return $call($http);
        } catch (ConnectionException) {
            throw new DnsUnavailable('Cloudflare tidak dapat dihubungi dari konsol ini.');
        }
    }

    /**
     * Isi `result` dari amplop Cloudflare, atau penolakan yang menyebut sebabnya. Pesan galat Cloudflare
     * ikut dikutip karena ia yang menjelaskan izin atau bentuk yang salah; ia tidak pernah memuat token.
     */
    private function result(Response $response, string $action): mixed
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new DnsUnavailable(sprintf('Cloudflare menolak token saat %s (HTTP %d). Periksa token dan izinnya: Zone → DNS → Edit.', $action, $response->status()));
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            $messages = [];

            foreach (is_array($payload['errors'] ?? null) ? $payload['errors'] : [] as $error) {
                if (is_array($error) && is_string($error['message'] ?? null)) {
                    $messages[] = $error['message'];
                }
            }

            throw new DnsUnavailable(sprintf(
                'Cloudflare menolak saat %s (HTTP %d)%s',
                $action,
                $response->status(),
                $messages === [] ? '.' : ': '.implode('; ', array_slice($messages, 0, 3)),
            ));
        }

        return $payload['result'] ?? null;
    }
}
