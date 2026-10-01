<?php

declare(strict_types=1);

namespace App\Platform\Integration\Support;

use App\Platform\Integration\Models\IntegrationClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mengirim satu body JSON ke URL klien `push`, dengan signature (K-03).
 *
 * Signature-nya mengikuti pola yang sudah dipakai `PublishWorkflowEvents`, supaya pembaca yang
 * sudah memverifikasi event workflow tidak perlu belajar cara kedua:
 *
 * - `X-CoreERP-Event-Timestamp`: detik Unix saat dikirim.
 * - `X-CoreERP-Event-Signature`: HMAC-SHA256 heksadesimal atas `<timestamp>.<raw body>`,
 *   dengan signing secret klien sebagai key.
 * - `X-CoreERP-Client-Id`: id klien, supaya pembaca yang melayani beberapa tenant tahu secret
 *   mana yang dipakai memverifikasi.
 *
 * Pembaca wajib menolak timestamp yang terlalu jauh dari jamnya sendiri, supaya kiriman yang
 * disadap tidak dapat diputar ulang.
 */
final class SignedPush
{
    public function __construct(private readonly PushDestination $destination) {}

    /** @return array{timestamp: string, signature: string} */
    public static function sign(string $secret, string $body, ?int $timestamp = null): array
    {
        $issuedAt = (string) ($timestamp ?? now()->getTimestamp());

        return [
            'timestamp' => $issuedAt,
            'signature' => hash_hmac('sha256', $issuedAt.'.'.$body, $secret),
        ];
    }

    /**
     * @throws RuntimeException Klien bukan mode push, atau tujuannya ditolak aturan PushDestination.
     * @throws ConnectionException Tujuan tidak terjangkau atau melewati batas waktu.
     */
    public function send(IntegrationClient $client, string $body): Response
    {
        if ($client->delivery_mode !== IntegrationClient::PUSH || $client->push_url === null || $client->signing_secret === null) {
            throw new RuntimeException('Klien integrasi ini tidak memakai mode push.');
        }
        $check = $this->destination->inspect($client->push_url);
        if ($check['reason'] !== null) {
            throw new RuntimeException($check['reason']);
        }

        $signed = self::sign($client->signing_secret, $body);

        // Redirect tidak diikuti: tujuan yang sudah lolos PushDestination bisa mengalihkan ke
        // jaringan privat, dan pengalihan itu tidak pernah diperiksa. Jawaban 3xx dihitung gagal.
        $option = ['allow_redirects' => false];
        // Di SaaS, cURL memakai alamat yang baru saja diperiksa, bukan hasil resolusi DNS keduanya
        // sendiri. Nama host tetap dipakai untuk SNI dan verifikasi sertifikat.
        if ($check['pin'] !== []) {
            $option['curl'] = [CURLOPT_RESOLVE => $check['pin']];
        }

        return Http::acceptJson()
            ->withOptions($option)
            ->connectTimeout(3)
            ->timeout(10)
            ->withBody($body, 'application/json')
            ->withHeaders([
                'X-CoreERP-Event-Timestamp' => $signed['timestamp'],
                'X-CoreERP-Event-Signature' => $signed['signature'],
                'X-CoreERP-Client-Id' => $client->id,
            ])
            ->post($client->push_url);
    }
}
