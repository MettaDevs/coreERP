<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CoreDirectoryClient
{
    /** @return list<array{membership_id:string,name:string,email?:string}> */
    public function members(string $tenantId, string $query): array
    {
        return $this->get($tenantId, 'members', ['q' => $query]);
    }

    /** @return list<array{id:string,name:string}> */
    public function operatingUnits(string $tenantId): array
    {
        return $this->get($tenantId, 'operating-units');
    }

    /** @return array{membership_id:string,name:string,email?:string} */
    public function member(string $tenantId, string $membershipId): array
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
            ->get('/api/internal/v1/members/'.$membershipId);

        if (! $response->successful() || ! is_array($response->json('data')) || ! is_string($response->json('data.membership_id'))) {
            throw new RuntimeException('Anggota Core tidak tersedia.');
        }

        return $response->json('data');
    }

    /** @return list<array<string,mixed>> */
    private function get(string $tenantId, string $path, array $query = []): array
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
            ->get('/api/internal/v1/'.$path, $query);

        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new RuntimeException('Data Core belum dapat dimuat.');
        }

        return $response->json('data');
    }
}
