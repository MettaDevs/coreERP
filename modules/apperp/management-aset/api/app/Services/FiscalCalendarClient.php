<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Kalender tahun buku milik Core.
 *
 * Bulan awal tahun buku sengaja tidak disalin ke database app: tenant dapat mengubah
 * kalendernya, dan salinan lokal akan menyimpang tanpa ada yang menyadarinya.
 */
final class FiscalCalendarClient
{
    /**
     * Tahun buku dan periode yang memuat satu tanggal.
     *
     * @return array{calendar: array<string,mixed>, year: array<string,mixed>, period: array<string,mixed>}|null
     *                                                                                                           `null` bila legal entity belum punya kalender atau tanggalnya belum tercakup;
     *                                                                                                           pemanggil memutuskan sendiri apakah itu kesalahan.
     */
    public function resolve(string $tenantId, string $legalEntityId, string $date): ?array
    {
        $url = rtrim((string) config('services.coreerp.url'), '/');
        $token = (string) config('services.coreerp.service_token');
        if ($url === '' || $token === '') {
            throw new RuntimeException('Layanan kalender tahun buku belum dikonfigurasi.');
        }

        try {
            $response = Http::baseUrl($url)->acceptJson()->connectTimeout(2)->timeout(5)
                ->retry(2, 200, fn (\Throwable $error): bool => $error instanceof ConnectionException, throw: false)
                ->withHeaders([
                    'X-CoreERP-App-Id' => (string) config('services.coreerp.app_id'),
                    'X-CoreERP-Service-Token' => $token,
                    'X-CoreERP-Tenant-Id' => $tenantId,
                ])
                ->get('/api/internal/v1/fiscal-periods', ['legal_entity_id' => $legalEntityId, 'date' => $date]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Kalender tahun buku belum dapat dihubungi.', previous: $exception);
        }

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new RuntimeException('Kalender tahun buku belum dapat dibaca.');
        }

        return $response->json('data');
    }
}
