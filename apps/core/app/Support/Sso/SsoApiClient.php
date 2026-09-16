<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Pengangkut untuk API pengelolaan penyedia SSO — pencarian pengguna dan permintaan kirim undangan.
 *
 * Bukan bagian dari OIDC, dan itu bukan kelalaian melainkan kenyataan yang harus ditulis: alamat
 * kedua endpoint ini **tidak ada di dokumen discovery**. Setiap alamat lain yang dipakai
 * `SharedIdentityProvider` ditemukan sendiri dari `.well-known/openid-configuration`; dua ini tidak
 * dapat, jadi ia disetel — `COREERP_SSO_API_URL`, dan bila kosong diturunkan dari issuer.
 *
 * ## Kredensial
 *
 * Penyedia memeriksa pasangan client id dan secret Passport lewat header `X-Client-ID` dan
 * `X-Client-Secret`, tanpa scope sama sekali. Bawaannya pasangan yang sama dengan alur OIDC, karena
 * penyedia tidak mengenal kredensial mesin yang terpisah; `COREERP_SSO_API_CLIENT_ID` dan
 * `_SECRET` menggantikannya bila suatu hari client kedua didaftarkan, tanpa menyentuh kode ini.
 *
 * ## Tanpa percobaan ulang
 *
 * Pemanggilnya adalah permintaan yang sedang ditunggu operator di depan layar. Mengulang hanya
 * melipatgandakan waktu tunggunya sebelum kalimat yang sama muncul, dan untuk `send-invitation`
 * ia berisiko mengirim surat dua kali.
 */
class SsoApiClient
{
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function baseUrl(): string
    {
        $configured = $this->setting('api_url');

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $issuer = rtrim($this->setting('issuer'), '/');

        return $issuer === '' ? '' : $issuer.'/api/v1';
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws SsoApiUnavailable
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send(fn () => $this->pending()->get($this->baseUrl().$path, $query), $path);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws SsoApiUnavailable
     */
    public function post(string $path, array $body = []): Response
    {
        return $this->send(fn () => $this->pending()->post($this->baseUrl().$path, $body), $path);
    }

    /**
     * Kegagalan yang tidak pernah berupa jawaban yang dapat dibaca pemanggil.
     *
     * 404 sengaja **tidak** ditangani di sini: bagi pencarian ia jawaban yang sah ("tidak terdaftar"),
     * dan hanya pemanggil yang tahu artinya.
     *
     * @param  callable(): Response  $kirim
     *
     * @throws SsoApiUnavailable
     */
    private function send(callable $kirim, string $path): Response
    {
        try {
            $response = $kirim();
        } catch (ConnectionException $e) {
            throw new SsoApiUnavailable('Penyedia SSO tidak dapat dihubungi dari server ini: '.$e->getMessage());
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            // Yang salah hampir selalu satu hal, dan menyebut nama variabelnya jauh lebih menolong
            // daripada mengutip jawaban penyedia. Rahasianya sendiri tidak pernah ikut.
            throw new SsoApiUnavailable(sprintf(
                'Penyedia SSO menolak kredensial konsol ini (HTTP %d di %s). Periksa COREERP_SSO_CLIENT_ID dan COREERP_SSO_CLIENT_SECRET, atau pasangan COREERP_SSO_API_* bila dipakai.',
                $status,
                $path,
            ));
        }

        if ($status === 429) {
            throw new SsoApiUnavailable('Penyedia SSO sedang membatasi jumlah permintaan. Coba lagi satu menit lagi.');
        }

        if ($status >= 500) {
            throw new SsoApiUnavailable(sprintf('Penyedia SSO menjawab %d di %s.', $status, $path));
        }

        return $response;
    }

    private function pending(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'X-Client-ID' => $this->clientId(),
            'X-Client-Secret' => $this->clientSecret(),
        ])->acceptJson()->connectTimeout(3)->timeout(10);
    }

    private function clientId(): string
    {
        return $this->setting('api_client_id') ?: $this->setting('client_id');
    }

    private function clientSecret(): string
    {
        return $this->setting('api_client_secret') ?: $this->setting('client_secret');
    }

    private function setting(string $key): string
    {
        $value = config('coreerp.sso.'.$key);

        return is_string($value) ? trim($value) : '';
    }
}
