<?php

namespace App\Foundation\FinancePosting\Http\Controllers\Internal;

use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Foundation\FinancePosting\Support\PostingAcknowledger;
use App\Http\Controllers\Controller;
use App\Platform\Integration\Http\Middleware\AuthenticateIntegrationClient;
use App\Platform\Integration\Models\IntegrationClient;
use App\Platform\Organization\Models\LegalEntity;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feed posting finance untuk pembaca mode `pull` (TODO 6.4 dan 6.5, K-23).
 *
 * Satu endpoint untuk semua jenis posting. Yang disajikan hanya `pending`, urut tanggal akuntansi
 * lalu jam terbit, dan disajikan ulang pada setiap tarikan sampai di-ack. Tidak ada kursor: posting
 * yang sudah di-ack keluar dari daftar dengan sendirinya, dan posting yang terbit di tengah tarikan
 * muncul pada tarikan berikutnya di tempat yang sesuai urutannya.
 */
final class FinancePostingFeedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $filter = $request->validate([
            'status' => ['nullable', 'in:'.FinancePosting::PENDING],
            'posting_type' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*(\.\*)?$/'],
            'legal_entity' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], ['posting_type.regex' => 'posting_type berupa jenis lengkap (asset.acquisition) atau awalan dengan .* (asset.*).']);
        $limit = (int) ($filter['limit'] ?? 100);

        $query = FinancePosting::query()
            ->readyForDelivery()
            ->where('tenant_id', $client->tenant_id)
            ->where('status', FinancePosting::PENDING);
        FinancePosting::restrictToClient($query, $client);

        $type = $filter['posting_type'] ?? null;
        if ($type !== null) {
            str_ends_with($type, '.*')
                ? $query->where('posting_type', 'like', addcslashes(substr($type, 0, -1), '\\%_').'%')
                : $query->where('posting_type', $type);
        }
        if (($filter['legal_entity'] ?? null) !== null) {
            $legalEntity = LegalEntity::query()
                ->where('tenant_id', $client->tenant_id)
                ->where(fn (QueryBuilder $inner) => $inner->where('company_code', $filter['legal_entity'])->orWhere('organization_id', $filter['legal_entity']))
                ->value('organization_id');
            $query->where('legal_entity_id', $legalEntity ?? '');
        }

        $postings = $query->orderBy('posting_date')->orderBy('published_at')->orderBy('id')->limit($limit + 1)->get();
        $stillExists = $postings->count() > $limit;
        $postings = $postings->take($limit)->values();

        if ($postings->isNotEmpty()) {
            FinancePosting::query()->whereIn('id', $postings->pluck('id'))->increment('served_count', 1, ['last_served_at' => now()]);
        }
        $client->forceFill(['last_pulled_at' => now()])->save();

        return response()->json([
            'data' => $postings->map(fn (FinancePosting $posting): array => $posting->servedPayload())->values(),
            'meta' => ['count' => $postings->count(), 'has_more' => $stillExists],
        ]);
    }

    public function ack(Request $request, string $posting_id, PostingAcknowledger $ack): JsonResponse
    {
        $client = $this->client($request);
        $query = FinancePosting::query()->where('tenant_id', $client->tenant_id)->where('posting_id', $posting_id);
        FinancePosting::restrictToClient($query, $client);
        $posting = $query->first();
        if ($posting === null) {
            return response()->json(['message' => 'Posting tidak ditemukan.'], 404);
        }

        $data = $request->validate(PostingAcknowledger::rules(), [
            'external_reference.required_if' => 'Ack posted wajib membawa external_reference (nomor voucher atau faktur).',
            'reason_code.required_if' => 'Ack rejected wajib membawa reason_code.',
            'reason.required_if' => 'Ack rejected wajib membawa reason.',
        ]);
        $result = $ack->acknowledge($posting->id, $client, PostingAcknowledger::shape($data));
        $posting = $result['posting'];
        $content = ['data' => [
            'posting_id' => $posting->posting_id,
            'status' => $posting->status,
            'external_reference' => $posting->external_reference,
            'reason_code' => $posting->reason_code,
            'reason' => $posting->reason,
            'acknowledged_at' => $posting->acknowledged_at?->toIso8601String(),
        ]];

        if ($result['result'] === PostingAcknowledger::CONFLICT) {
            return response()->json([
                'message' => sprintf('Posting %s berstatus %s; ack ini bertentangan dengannya.', $posting->posting_id, $posting->status),
                ...$content,
            ], 409);
        }

        return response()->json($content);
    }

    private function client(Request $request): IntegrationClient
    {
        return IntegrationClient::query()->findOrFail((string) $request->attributes->get(AuthenticateIntegrationClient::ATTRIBUTE));
    }
}
