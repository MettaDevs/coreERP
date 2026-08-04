<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CoreAccessClient
{
    /** @param array<string,mixed> $payload */
    public function positionAssignment(string $tenantId, array $payload): void
    {
        $response = Http::baseUrl(rtrim((string) config('services.coreerp.url'), '/'))
            ->acceptJson()
            ->connectTimeout(2)
            ->timeout(5)
            ->withHeaders([
                'X-CoreERP-App-Id' => config('services.coreerp.app_id'),
                'X-CoreERP-Service-Token' => config('services.coreerp.service_token'),
                'X-CoreERP-Tenant-Id' => $tenantId,
            ])
            ->post('/api/internal/v1/human-resources/position-assignments', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Akses otomatis belum dapat diperbarui.');
        }
    }
}
