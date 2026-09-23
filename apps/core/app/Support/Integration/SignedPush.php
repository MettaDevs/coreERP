<?php

declare(strict_types=1);

namespace App\Support\Integration;

use App\Models\IntegrationClient;
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
    public function __construct(private readonly PushDestination $tujuan) {}

    /** @return array{timestamp: string, signature: string} */
    public static function sign(string $secret, string $body, ?int $timestamp = null): array
    {
        $stempel = (string) ($timestamp ?? now()->getTimestamp());

        return [
            'timestamp' => $stempel,
            'signature' => hash_hmac('sha256', $stempel.'.'.$body, $secret),
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
        $tolak = $this->tujuan->reject($client->push_url);
        if ($tolak !== null) {
            throw new RuntimeException($tolak);
        }

        $tanda = self::sign($client->signing_secret, $body);

        // Redirect tidak diikuti: tujuan yang sudah lolos PushDestination bisa mengalihkan ke
        // jaringan privat, dan pengalihan itu tidak pernah diperiksa. Jawaban 3xx dihitung gagal.
        return Http::acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(3)
            ->timeout(10)
            ->withBody($body, 'application/json')
            ->withHeaders([
                'X-CoreERP-Event-Timestamp' => $tanda['timestamp'],
                'X-CoreERP-Event-Signature' => $tanda['signature'],
                'X-CoreERP-Client-Id' => $client->id,
            ])
            ->post($client->push_url);
    }
}
