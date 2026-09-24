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
    public function __construct(private readonly PushDestination $destination) {}

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
     * @throws ConnectionException Tujuan tidak terjangkau, nama host-nya tidak dapat diselesaikan, atau
     *                             melewati batas waktu.
     */
    public function send(IntegrationClient $client, string $body): Response
    {
        if ($client->delivery_mode !== IntegrationClient::PUSH || $client->push_url === null || $client->signing_secret === null) {
            throw new RuntimeException('Klien integrasi ini tidak memakai mode push.');
        }
        $destination = $this->destination->inspect($client->push_url);
        if ($destination['unresolved']) {
            // Sama dengan cURL yang gagal meresolusi nama host: bisa gangguan DNS sesaat, jadi dicoba
            // lagi, bukan ditolak permanen.
            throw new ConnectionException((string) $destination['rejection']);
        }
        if ($destination['rejection'] !== null) {
            throw new RuntimeException($destination['rejection']);
        }

        $tanda = self::sign($client->signing_secret, $body);

        // Redirect tidak diikuti: tujuan yang sudah lolos PushDestination bisa mengalihkan ke
        // jaringan privat, dan pengalihan itu tidak pernah diperiksa. Jawaban 3xx dihitung gagal.
        // Di SaaS, cURL dipatok ke alamat yang baru saja diperiksa dan tidak meresolusi lagi.
        $options = ['allow_redirects' => false];
        if ($destination['resolve'] !== []) {
            $options['curl'] = [CURLOPT_RESOLVE => $destination['resolve']];
        }

        return Http::acceptJson()
            ->withOptions($options)
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
