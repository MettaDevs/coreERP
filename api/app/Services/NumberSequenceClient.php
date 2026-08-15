<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NumberSequenceClient
{
    /**
     * Menerbitkan satu nomor untuk reference milik app ini. Format, status, dan counter
     * adalah milik Control Plane; app hanya menerima nomor yang sudah jadi.
     *
     * @throws NumberSequenceException
     */
    public function issue(string $reference, string $tenantId, string $idempotencyKey, ?string $legalEntityId = null): string
    {
        $url = rtrim((string) config('services.coreerp.url'), '/');
        $appId = (string) config('services.coreerp.app_id');
        $token = (string) config('services.coreerp.service_token');

        if ($url === '' || $token === '') {
            throw $this->fail('number_sequence_not_configured', 'Layanan nomor belum dikonfigurasi.', $reference, $appId, $tenantId);
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
        } catch (ConnectionException $exception) {
            throw $this->fail('number_sequence_unreachable', 'Layanan nomor belum dapat dihubungi.', $reference, $appId, $tenantId, null, $exception);
        } catch (RequestException $exception) {
            [$code, $message] = $this->classify($exception->response->status(), $reference);
            throw $this->fail($code, $message, $reference, $appId, $tenantId, $exception->response->status(), $exception);
        }

        $number = $response->json('data.number');
        if (! is_string($number) || $number === '') {
            throw $this->fail('number_sequence_invalid_response', 'Layanan nomor mengembalikan data yang tidak valid.', $reference, $appId, $tenantId, $response->status());
        }

        return $number;
    }

    /**
     * Menerjemahkan jawaban Core menjadi sebab yang dapat ditindaklanjuti.
     *
     * Core menjawab, jadi jaringannya sehat. Yang membedakan adalah apakah app ini tidak
     * dipercaya, referencenya belum ada, permintaannya ditolak, atau Core sendiri sedang
     * bermasalah — empat hal dengan penanganan yang sama sekali berbeda.
     *
     * @return array{string, string}
     */
    private function classify(int $status, string $reference): array
    {
        return match (true) {
            // Bukan masalah tenant maupun pengguna: kredensial service app ini ditolak Core.
            // Lazimnya token service tidak sinkron dengan yang tercatat di Control Plane.
            in_array($status, [401, 403], true) => [
                'number_sequence_forbidden',
                'Aplikasi ini belum dipercaya Core untuk menerbitkan nomor.',
            ],
            $status === 404 => [
                'number_sequence_reference_unknown',
                'Reference nomor "'.$reference.'" belum terdaftar di Core.',
            ],
            $status === 429 => [
                'number_sequence_throttled',
                'Permintaan nomor terlalu sering. Coba lagi sebentar lagi.',
            ],
            $status >= 500 => [
                'number_sequence_unavailable',
                'Nomor belum dapat diterbitkan.',
            ],
            default => [
                'number_sequence_rejected',
                'Permintaan nomor ditolak Core.',
            ],
        };
    }

    /**
     * Mencatat sebabnya sebelum melemparkannya.
     *
     * Konteks di sini sengaja lengkap: tanpa status HTTP dan reference, menelusuri
     * kegagalan berarti membandingkan digest kredensial satu per satu. Token tidak pernah
     * ikut dicatat, termasuk potongannya.
     */
    private function fail(
        string $code,
        string $message,
        string $reference,
        string $appId,
        string $tenantId,
        ?int $status = null,
        ?\Throwable $previous = null,
    ): NumberSequenceException {
        Log::error('Penerbitan nomor gagal: '.$code, [
            'error_code' => $code,
            'reference' => $reference,
            'app_id' => $appId,
            'tenant_id' => $tenantId,
            'core_http_status' => $status,
        ]);

        return new NumberSequenceException($code, $message, previous: $previous);
    }
}
