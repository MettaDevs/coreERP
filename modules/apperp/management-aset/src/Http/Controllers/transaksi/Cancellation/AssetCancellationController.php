<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Cancellation;

use App\Platform\Modules\Contracts\InvalidPosting;
use App\Platform\Modules\Contracts\PostingFeed;
use App\Platform\Modules\Contracts\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Models\transaksi\Cancellation\AssetCancellation;
use Modules\Apperp\ManagementAset\Services\AcquisitionPosting;
use Modules\Apperp\ManagementAset\Services\AssetCancellationEngine;
use Modules\Apperp\ManagementAset\Support\PostingCheckLines;

final class AssetCancellationController
{
    public function preview(Request $request, string $id, AssetCancellationEngine $engine): JsonResponse
    {
        $resource = $request->route('resource');
        $context = app(RequestContext::class);
        abort_unless($context->hasPermission('management-aset.'.$resource.'.cancel') || $context->hasPermission('management-aset.'.$resource.'.request-cancellation'), 403);

        return DB::transaction(function () use ($request, $resource, $id, $engine): JsonResponse {
            $document = $engine->subject($resource, $id, $request);
            $request->validate(['posting_date' => ['nullable', 'date_format:Y-m-d']]);
            $originalDate = $document instanceof \Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod ? $document->period_ends_on->toDateString() : $document->tanggal->toDateString();
            if ($document instanceof \Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAset) {
                $postingId = AcquisitionPosting::postingId($id, (string) $document->cara_perolehan);
                $original = app(PostingFeed::class)->status((string) $document->tenant_id, $postingId);
                $originalDate = $original['posting_date'] ?? $originalDate;
            }
            $pending = AssetCancellation::query()->where(['resource' => $resource, 'document_id' => $id])->latest()->orderByDesc('id')->first();
            $date = $pending?->status === 'pending' ? $pending->posting_date->toDateString() : $request->input('posting_date', $originalDate);
            $blockers = $engine->blockers($resource, $document, $date);
            $journals = $blockers === [] ? $engine->previewJournals($resource, $document, $date) : [];

            return response()->json(['data' => [
                'blockers' => $blockers, 'posting_date' => $date, 'original_posting_date' => $originalDate,
                'journals' => array_map(static fn (array $posting): array => [
                    'blockers' => [], 'note' => null,
                    'posting' => ['posting_id' => $posting['posting_id'], 'status' => $posting['status'], 'posting_date' => $posting['payload']['posting_date'],
                        'currency' => $posting['payload']['currency'], 'total' => $posting['payload']['totals']['debit'],
                        'lines' => PostingCheckLines::from($posting['payload']), 'problems' => $posting['problems']],
                ], $journals),
                'version' => $document->getAttribute('version'), 'cancellation' => $pending,
            ]]);
        });
    }

    public function cancel(Request $request, string $id, AssetCancellationEngine $engine): JsonResponse
    {
        return $this->start($request, $id, $engine, false);
    }

    public function submit(Request $request, string $id, AssetCancellationEngine $engine): JsonResponse
    {
        return $this->start($request, $id, $engine, true);
    }

    private function start(Request $request, string $id, AssetCancellationEngine $engine, bool $submit): JsonResponse
    {
        try {
            $result = $engine->start($request, $request->route('resource'), $id, $submit);
        } catch (InvalidPosting $failure) {
            report($failure);

            return response()->json(['error' => ['code' => 'posting_failed', 'message' => 'Jurnal pembatalan belum dapat dibuat. Transaksi belum dibatalkan.']], 500);
        }

        return response()->json(['data' => $result], 201);
    }
}
