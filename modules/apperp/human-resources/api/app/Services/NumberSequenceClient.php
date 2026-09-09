<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NumberSequenceClient
{
    public function issue(string $reference, string $tenantId, string $key): string
    {
        $response = Http::baseUrl(rtrim((string) config('services.coreerp.url'), '/'))->acceptJson()->connectTimeout(2)->timeout(5)
            ->withHeaders(['X-CoreERP-App-Id' => config('services.coreerp.app_id'), 'X-CoreERP-Service-Token' => config('services.coreerp.service_token'), 'X-CoreERP-Tenant-Id' => $tenantId])
            ->post('/api/internal/v1/number-sequences/'.$reference.'/issue', ['idempotency_key' => $key]);
        if (! $response->successful() || ! is_string($response->json('data.number'))) {
            throw new RuntimeException('Nomor belum dapat diterbitkan.');
        }

        return $response->json('data.number');
    }
}
