<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WorkflowClient
{
    // Korelasi memakai id dokumen: satu dokumen dekomisioning adalah satu rantai kerja,
    // dan Core mengembalikannya pada event keputusan berhari-hari kemudian. Tanpa ini
    // Core membangkitkan korelasinya sendiri dan sisi app kehilangan jejaknya.
    /** @param array<string, mixed> $context */
    public function submit(string $tenantId, string $key, string $documentId, string $assetId, array $context): string
    {
        $url = rtrim((string) config('services.coreerp.url'), '/');
        $token = (string) config('services.coreerp.service_token');
        if ($url === '' || $token === '') {
            throw new RuntimeException('Layanan persetujuan belum dikonfigurasi.');
        }

        try {
            $response = Http::baseUrl($url)->acceptJson()->connectTimeout(2)->timeout(5)
                ->retry(2, 200, fn (\Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
                ->withHeaders([
                    'X-CoreERP-App-Id' => (string) config('services.coreerp.app_id'),
                    'X-CoreERP-Service-Token' => $token, 'X-CoreERP-Tenant-Id' => $tenantId,
                    'Idempotency-Key' => $key, 'X-Correlation-Id' => $documentId,
                ])->post('/api/internal/v1/workflow-instances', [
                    'workflow_type' => 'management-aset.dekomisioning-aset-verification',
                    'source_document_type' => 'dekomisioning-aset', 'source_document_id' => $documentId,
                    'decision_context' => ['document_id' => $documentId, 'asset_id' => $assetId] + $context,
                ])->throw();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            throw new RuntimeException('Permintaan persetujuan belum dapat dikirim.', previous: $exception);
        }

        $id = $response->json('data.id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Layanan persetujuan mengembalikan data yang tidak valid.');
        }

        return $id;
    }
}
