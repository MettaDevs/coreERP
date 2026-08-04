<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NumberSequenceClient
{
    /**
     * Menerbitkan satu nomor untuk reference milik app ini. Format, status, dan counter
     * adalah milik Control Plane; app hanya menerima nomor yang sudah jadi.
     */
    public function issue(string $reference, string $tenantId, string $idempotencyKey, ?string $legalEntityId = null): string
    {
        $url = rtrim((string) config('services.coreerp.url'), '/');
        $appId = (string) config('services.coreerp.app_id');
        $token = (string) config('services.coreerp.service_token');

        if ($url === '' || $token === '') {
            throw new RuntimeException('Layanan nomor belum dikonfigurasi.');
        }

        try {
            $response = Http::baseUrl($url)
                ->acceptJson()
                // Without an explicit timeout this inherits Guzzle's 30 second default, held open inside a
                // synchronous request handler: one slow Core response would tie up a worker per waiting user.
                ->connectTimeout(2)
                ->timeout(5)
                // Retrying is safe because the idempotency key is stable, so Core returns the same number rather
                // than issuing a second one. Only connection-level failures are retried; a 4xx is a real answer.
                ->retry(2, 200, fn (\Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
                ->withHeaders([
                    'X-CoreERP-App-Id' => $appId,
                    'X-CoreERP-Service-Token' => $token,
                    'X-CoreERP-Tenant-Id' => $tenantId,
                ])
                ->post('/api/internal/v1/number-sequences/'.$reference.'/issue', [
                    'idempotency_key' => $idempotencyKey,
                    'legal_entity_id' => $legalEntityId,
                ])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            throw new RuntimeException('Nomor belum dapat diterbitkan.', previous: $exception);
        }

        $number = $response->json('data.number');
        if (! is_string($number) || $number === '') {
            throw new RuntimeException('Layanan nomor mengembalikan data yang tidak valid.');
        }

        return $number;
    }
}
