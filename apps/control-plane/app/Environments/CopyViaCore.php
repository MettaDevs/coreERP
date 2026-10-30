<?php

declare(strict_types=1);

namespace ControlPlane\Environments;

use ControlPlane\Models\Environment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class CopyViaCore
{
    public function __invoke(Environment $source, string $name, int $requestedBy): Environment
    {
        try {
            $response = Http::withToken((string) config('core.token'))->acceptJson()
                ->timeout(max(1, (int) config('core.timeout')))
                ->post(rtrim((string) config('core.base_url'), '/').'/api/internal/v1/environments/'.$source->id.'/copy', [
                    'name' => $name, 'requested_by' => $requestedBy,
                ]);
        } catch (ConnectionException $error) {
            throw new EnvironmentRejected('Jawaban Core belum diterima. Periksa daftar lingkungan dan riwayat sebelum mengirim ulang.', previous: $error);
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        if ($response->failed()) {
            throw new EnvironmentRejected(is_string($payload['message'] ?? null) ? $payload['message'] : 'Core menolak penyalinan.');
        }
        $id = $payload['target_environment_id'] ?? null;
        if ($response->status() !== 202 || ($payload['queued'] ?? null) !== true
            || ($payload['source_environment_id'] ?? null) !== $source->id
            || ($payload['tenant_id'] ?? null) !== $source->tenant_id || ! is_string($id) || $id === '') {
            throw new EnvironmentRejected('Jawaban antrean penyalinan tidak lengkap. Periksa daftar lingkungan sebelum mencoba lagi.');
        }
        $target = Environment::query()->whereKey($id)->where('tenant_id', $source->tenant_id)
            ->where('kind', 'sandbox')->where('source_environment_id', $source->id)->whereNull('deleted_at')->first();
        if (! $target instanceof Environment) {
            throw new EnvironmentRejected('Sandbox hasil permintaan belum dapat dibaca. Muat ulang daftar lingkungan.');
        }

        return $target;
    }
}
