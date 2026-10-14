<?php

declare(strict_types=1);

namespace App\Foundation\FinancePosting\Http\Controllers\Internal;

use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Http\Controllers\Controller;
use App\Platform\Attachments\Models\DocumentAttachment;
use App\Platform\Attachments\Support\AttachmentRecordTypeRegistry;
use App\Platform\Integration\Http\Middleware\AuthenticateIntegrationClient;
use App\Platform\Integration\Models\IntegrationClient;
use App\Platform\Modules\Contracts\FinanceEvidenceRecordType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Baca bukti tanpa mengubah snapshot, status posting, atau acknowledgment. */
final class FinancePostingEvidenceController extends Controller
{
    public function index(Request $request, string $posting_id, string $receipt): JsonResponse
    {
        return response()->json(['data' => $this->attachments($request, $posting_id, $receipt)
            ->orderBy('created_at')->orderBy('id')->get()->map(fn (DocumentAttachment $file): array => [
                'id' => $file->id, 'file_name' => $file->file_name,
                'mime_type' => $file->mime_type, 'size_bytes' => $file->size_bytes,
                'kind' => $file->kind, 'line_number' => $file->line_number,
            ])->values()], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function download(Request $request, string $posting_id, string $receipt, string $attachment): StreamedResponse
    {
        $file = $this->attachments($request, $posting_id, $receipt)->whereKey($attachment)->firstOrFail();
        abort_if($file->size_bytes > 25 * 1024 * 1024, 413, 'Berkas terlalu besar untuk dibuka melalui aplikasi finance.');
        $source = Storage::disk((string) config('coreerp.attachments.disk'))->readStream($file->storage_path);
        abort_unless(is_resource($source), 404, 'Berkas belum tersedia.');
        $copy = fopen('php://temp/maxmemory:8388608', 'w+b');
        abort_unless(is_resource($copy), 503, 'Berkas belum dapat dibuka.');
        $size = stream_copy_to_stream($source, $copy, 25 * 1024 * 1024 + 1);
        fclose($source);
        rewind($copy);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $copy);
        if ($size !== $file->size_bytes || ! hash_equals($file->content_hash, hash_final($hash))) {
            fclose($copy);
            abort(503, 'Berkas belum dapat dibuka. Hubungi admin untuk memeriksa lampiran.');
        }
        rewind($copy);

        return new StreamedResponse(function () use ($copy): void {
            fpassthru($copy);
            fclose($copy);
        }, 200, [
            'Content-Type' => $file->mime_type,
            'Content-Length' => (string) $file->size_bytes,
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $file->file_name, 'document'),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return Builder<DocumentAttachment> */
    private function attachments(Request $request, string $postingId, string $receiptId): Builder
    {
        $client = IntegrationClient::query()->findOrFail($request->attributes->get(AuthenticateIntegrationClient::ATTRIBUTE));
        $query = FinancePosting::query()->where('tenant_id', $client->tenant_id)->where('posting_id', $postingId)
            ->whereIn('status', [FinancePosting::PENDING, FinancePosting::POSTED]);
        FinancePosting::restrictToClient($query, $client);
        $posting = $query->firstOrFail();
        $details = $posting->payload['details'] ?? [];
        $references = array_column($details['assets'] ?? [], 'receipt');
        $references[] = $details['receipt'] ?? null;
        $reference = collect($references)->first(fn ($item): bool => is_array($item) && ($item['id'] ?? null) === $receiptId);
        abort_unless(is_array($reference), 404);
        $type = app(AttachmentRecordTypeRegistry::class)->for($reference['attachment_record_type'] ?? '');
        abort_unless($type instanceof FinanceEvidenceRecordType
            && $type->belongsToFinanceEntity($client->tenant_id, $posting->legal_entity_id, $receiptId), 404);

        return DocumentAttachment::query()->where('tenant_id', $client->tenant_id)
            ->where('record_type', $type->recordType())->where('record_id', $receiptId)
            ->whereIn('kind', ['document', 'picture']);
    }
}
