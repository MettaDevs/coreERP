<?php

declare(strict_types=1);

namespace ControlPlane\Environments;

use ControlPlane\Models\Environment;
use ControlPlane\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

final class CreateEnvironment
{
    public function __invoke(Tenant $tenant, string $kind, string $name, ?Carbon $expiresAt, int $requestedBy, string $hosting = 'provider'): Environment
    {
        try {
            $response = Http::withToken((string) config('core.token'))->acceptJson()
                ->timeout(max(1, (int) config('core.timeout')))
                ->post(rtrim((string) config('core.base_url'), '/').'/api/internal/v1/environments', [
                    'tenant_id' => $tenant->id, 'kind' => $kind, 'name' => $name,
                    'expires_at' => $expiresAt?->toDateString(), 'requested_by' => $requestedBy, 'hosting' => $hosting,
                ]);
        } catch (ConnectionException $error) {
            throw new EnvironmentRejected('Hasil pembuatan lingkungan belum dapat dipastikan. Periksa daftar lingkungan sebelum mencoba lagi.', previous: $error);
        }
        if ($response->failed()) {
            $errors = $response->json('errors');
            $message = is_array($errors) ? collect($errors)->flatten()->first(fn ($value) => is_string($value) && $value !== '') : $response->json('message');
            throw new EnvironmentRejected(is_string($message) && $message !== '' ? $message : 'Core menolak pembuatan lingkungan (HTTP '.$response->status().').');
        }
        $id = $response->json('environment_id');
        if ($response->status() !== 201 || ! is_string($id) || $id === '' || $response->json('tenant_id') !== $tenant->id) {
            throw new EnvironmentRejected('Jawaban pembuatan lingkungan tidak lengkap. Periksa daftar lingkungan sebelum mencoba lagi.');
        }
        $environment = Environment::query()->whereKey($id)->where('tenant_id', $tenant->id)->first();
        if (! $environment instanceof Environment) {
            throw new EnvironmentRejected('Lingkungan hasil pembuatan belum dapat dibaca. Periksa daftar lingkungan sebelum mencoba lagi.');
        }

        return $environment;
    }
}
