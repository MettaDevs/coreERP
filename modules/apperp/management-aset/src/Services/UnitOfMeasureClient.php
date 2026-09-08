<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class UnitOfMeasureClient
{
    /** @return list<array{id:string,code:string,name:string,symbol:?string,decimal_places:int}> */
    public function active(string $tenantId): array
    {
        return $this->request($tenantId, 'get', '/api/internal/v1/units-of-measure')->json('data') ?? [];
    }

    /** @param list<string> $ids @return array<string, array{id:string,code:string,name:string,symbol:?string,decimal_places:int}> */
    public function resolve(string $tenantId, array $ids): array
    {
        $units = $this->request($tenantId, 'post', '/api/internal/v1/units-of-measure/resolve', ['unit_ids' => $ids])->json('data');
        if (! is_array($units) || count($units) !== count($ids)) {
            throw new RuntimeException('Satuan belum dapat divalidasi.');
        }

        return collect($units)->keyBy('id')->all();
    }

    private function request(string $tenantId, string $method, string $path, array $payload = []): Response
    {
        $url = rtrim((string) config('services.coreerp.url'), '/');
        $token = (string) config('services.coreerp.service_token');
        if ($url === '' || $token === '') {
            throw new RuntimeException('Layanan satuan belum dikonfigurasi.');
        }
        try {
            return Http::baseUrl($url)->acceptJson()->connectTimeout(2)->timeout(5)
                ->retry(2, 200, fn (\Throwable $error): bool => $error instanceof ConnectionException, throw: false)
                ->withHeaders(['X-CoreERP-App-Id' => (string) config('services.coreerp.app_id'), 'X-CoreERP-Service-Token' => $token, 'X-CoreERP-Tenant-Id' => $tenantId])
                ->{$method}($path, $payload)->throw();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            throw new RuntimeException('Satuan belum dapat dihubungi.', previous: $exception);
        }
    }
}
