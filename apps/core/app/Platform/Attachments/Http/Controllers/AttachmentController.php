<?php

declare(strict_types=1);

namespace App\Platform\Attachments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Attachments\Http\Middleware\ResolveAttachmentContext;
use App\Platform\Attachments\Models\DocumentAttachment;
use App\Platform\Attachments\Models\DocumentNote;
use App\Platform\Attachments\Support\AttachmentContentMismatch;
use App\Platform\Attachments\Support\CoreAttachmentStorage;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\AttachmentRecordType;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lampiran dokumen (gap 7, K-09): unggah, daftar, unduh, dan arsip lampiran satu record.
 *
 * Rutenya melewati {@see ResolveAttachmentContext}, yang menemukan jenis record induk dan memasang konteks
 * module pemiliknya. Hak atas lampiran selalu hak atas record induknya, dijawab pemilik tabel lewat
 * {@see AttachmentRecordType}: boleh membuka record berarti boleh melihat dan mengunduh lampirannya, boleh
 * mengubah record berarti boleh melampirkan dan mengarsipkan. Record yang tidak boleh dibuka dijawab 404,
 * sama dengan record yang tidak ada, supaya keberadaannya tidak terbaca.
 *
 * Dipakai layar Shell dengan sesi login, bukan sistem lain; tidak ada kontrak `internal/v1`.
 */
final class AttachmentController extends Controller
{
    public function notes(Request $request, string $recordType, string $recordId): JsonResponse
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $this->requireReadable($type, $tenantId, $recordId);
        $notes = DocumentNote::query()->where('tenant_id', $tenantId)
            ->where('record_type', $type->recordType())->where('record_id', $recordId)
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $names = $this->userNames($notes);

        return response()->json([
            'data' => $notes->map(fn (DocumentNote $note): array => $this->presentNote($note, $names))->values(),
            'meta' => ['can_change' => $type->canChange($tenantId, $recordId)],
        ]);
    }

    public function storeNote(Request $request, string $recordType, string $recordId): JsonResponse
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $this->requireReadable($type, $tenantId, $recordId);
        abort_unless($type->canChange($tenantId, $recordId), 403, 'Kamu tidak punya hak mengubah data ini, jadi tidak dapat menambah catatan.');
        $data = $request->validate(['content' => ['required', 'string', 'max:10000']], [
            'content.required' => 'Isi catatan terlebih dahulu.',
            'content.max' => 'Catatan paling panjang 10.000 karakter.',
        ]);
        $note = DocumentNote::create([
            'tenant_id' => $tenantId, 'record_type' => $type->recordType(), 'record_id' => $recordId,
            'content' => $data['content'], 'data_class' => $type->dataClass()->value,
        ])->refresh();

        return response()->json(['data' => $this->presentNote($note, $this->userNames(collect([$note])))], 201);
    }

    public function updateNote(Request $request, string $recordType, string $recordId, string $note): JsonResponse
    {
        return $this->changeNote($request, $recordId, $note, false);
    }

    public function destroyNote(Request $request, string $recordType, string $recordId, string $note): JsonResponse
    {
        return $this->changeNote($request, $recordId, $note, true);
    }

    private function changeNote(Request $request, string $recordId, string $noteId, bool $archive): JsonResponse
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $this->requireReadable($type, $tenantId, $recordId);
        $query = DocumentNote::query()->where('tenant_id', $tenantId)
            ->where('record_type', $type->recordType())->where('record_id', $recordId)->whereKey($noteId);
        $note = (clone $query)->firstOrFail();
        abort_unless($type->canChange($tenantId, $recordId), 403, 'Kamu tidak punya hak mengubah catatan pada data ini.');
        $version = RowVersion::expected($request);
        $data = $archive ? ['deleted_at' => now()] : $request->validate([
            'content' => ['required', 'string', 'max:10000'],
        ], ['content.required' => 'Isi catatan terlebih dahulu.', 'content.max' => 'Catatan paling panjang 10.000 karakter.']);
        DB::transaction(function () use ($query, $version, $data): void {
            RowVersion::claim($query, $version);
            (clone $query)->update([...$data, 'updated_at' => now()]);
        });

        return $archive ? response()->json(status: 204)
            : response()->json(['data' => $this->presentNote($note->refresh(), $this->userNames(collect([$note])))]);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function presentNote(DocumentNote $note, array $names): array
    {
        return [
            'id' => $note->id, 'content' => $note->content, 'version' => $note->version,
            'created_by_name' => $names[$note->created_by_user_id] ?? null,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request, string $recordType, string $recordId): JsonResponse
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $this->requireReadable($type, $tenantId, $recordId);

        $query = DocumentAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('record_type', $type->recordType())
            ->where('record_id', $recordId)
            ->where('kind', 'document');
        $total = (clone $query)->count();
        $batch = IncrementalList::query(
            $request,
            $query->select('document_attachments.*')->selectRaw('COALESCE(line_number, -1) AS attachment_order')->toBase(),
            ['order' => 'attachment_order'],
            ['file_name'],
            'order',
            numericColumns: ['attachment_order'],
        );
        $attachments = DocumentAttachment::hydrate($batch['data']->map(fn (object $row): array => (array) $row)->all());
        $names = $this->userNames($attachments);

        return response()->json([
            'data' => $attachments->map(fn (DocumentAttachment $attachment): array => $this->present($attachment, $names))->values(),
            'next_cursor' => $batch['next_cursor'],
            'meta' => [
                'total' => $total,
                'can_change' => $type->canChange($tenantId, $recordId),
                'max_kb' => $this->maxKb(),
                'extensions' => $this->extensions(),
            ],
        ]);
    }

    public function store(Request $request, string $recordType, string $recordId): JsonResponse
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $this->requireReadable($type, $tenantId, $recordId);
        abort_unless($type->canChange($tenantId, $recordId), 403, 'Kamu tidak punya hak mengubah data ini, jadi belum dapat melampirkan berkas.');

        $extensions = implode(',', $this->extensions());
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$this->maxKb(), 'extensions:'.$extensions, 'mimes:'.$extensions],
            'line_number' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ], [
            'file.required' => 'Pilih berkas yang akan dilampirkan.',
            'file.file' => 'Berkas gagal terunggah. Coba lagi, atau pilih berkas yang lebih kecil.',
            'file.uploaded' => 'Berkas gagal terunggah. Coba lagi, atau pilih berkas yang lebih kecil.',
            'file.max' => 'Berkas terlalu besar. Ukuran paling besar '.$this->maxKb().' KB.',
            'file.extensions' => 'Jenis berkas ini tidak dapat dilampirkan. Yang dapat dilampirkan: '.$extensions.'.',
            'file.mimes' => 'Isi berkas tidak sesuai dengan jenisnya. Yang dapat dilampirkan: '.$extensions.'.',
            'line_number.integer' => 'Nomor baris harus angka.',
            'line_number.min' => 'Nomor baris harus angka.',
            'line_number.max' => 'Nomor baris harus angka.',
        ]);

        $lineNumber = isset($data['line_number']) ? (int) $data['line_number'] : null;
        if ($lineNumber !== null && ! $type->hasLine($tenantId, $recordId, $lineNumber)) {
            throw ValidationException::withMessages(['line_number' => 'Baris dokumen ini tidak ditemukan.']);
        }

        /** @var UploadedFile $file */
        $file = $data['file'];
        $attachment = app(CoreAttachmentStorage::class)->save($tenantId, $type, $recordId, $lineNumber, $file);

        return response()->json(['data' => $this->present($attachment, $this->userNames(collect([$attachment])))], 201);
    }

    public function pictures(Request $request, string $recordType, string $recordId): JsonResponse
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $this->requireReadable($type, $tenantId, $recordId);
        $pictures = DocumentAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('record_type', $type->recordType())
            ->where('record_id', $recordId)
            ->where('kind', 'picture')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $names = $this->userNames($pictures);

        return response()->json([
            'data' => $pictures->map(fn (DocumentAttachment $picture): array => $this->present($picture, $names))->values(),
            'meta' => [
                'can_change' => $type->canChange($tenantId, $recordId),
                'max_kb' => $this->maxKb(),
                'extensions' => ['jpg', 'jpeg', 'png'],
            ],
        ]);
    }

    public function storePicture(Request $request, string $recordType, string $recordId): JsonResponse
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $this->requireReadable($type, $tenantId, $recordId);
        abort_unless($type->canChange($tenantId, $recordId), 403, 'Kamu tidak punya hak mengubah data ini, jadi belum dapat menambah foto.');
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:'.$this->maxKb(), 'extensions:jpg,jpeg,png', 'mimes:jpg,jpeg,png'],
            'line_number' => ['prohibited'],
        ], [
            'file.required' => 'Pilih foto yang akan diunggah.',
            'file.file' => 'Foto gagal terunggah. Coba lagi.',
            'file.uploaded' => 'Foto gagal terunggah. Coba lagi, atau pilih foto yang lebih kecil.',
            'file.image' => 'Pilih foto JPG, JPEG, atau PNG.',
            'file.max' => 'Foto terlalu besar. Ukuran paling besar '.$this->maxKb().' KB.',
            'file.extensions' => 'Pilih foto JPG, JPEG, atau PNG.',
            'file.mimes' => 'Isi foto tidak sesuai dengan jenisnya. Pilih foto JPG, JPEG, atau PNG.',
            'line_number.prohibited' => 'Foto menempel pada data ini, bukan pada baris dokumennya.',
        ]);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $picture = app(CoreAttachmentStorage::class)->save($tenantId, $type, $recordId, null, $file, 'picture');

        return response()->json(['data' => $this->present($picture, $this->userNames(collect([$picture])))], 201);
    }

    public function download(Request $request, string $attachment): Response
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $record = $this->attachment($request);
        $this->requireReadable($type, $tenantId, $record->record_id);

        $content = $this->verifiedContent($record);
        if ($content === null) {
            return response()->json(['error' => [
                'code' => 'attachment_corrupted',
                'message' => 'Berkas lampiran ini tidak dapat dikirim karena isinya sudah tidak sama dengan saat diunggah. '
                    .'Kejadian ini sudah dilaporkan; hubungi admin untuk memulihkan berkasnya.',
            ]], 500);
        }

        return new StreamedResponse(function () use ($content): void {
            fpassthru($content);
            fclose($content);
        }, 200, [
            'Content-Type' => $record->mime_type,
            'Content-Length' => (string) $record->size_bytes,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $record->file_name,
                $this->asciiFileName($record->file_name),
            ),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $attachment): JsonResponse
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $record = $this->attachment($request);
        $this->requireReadable($type, $tenantId, $record->record_id);
        abort_unless($type->canChange($tenantId, $record->record_id), 403, 'Kamu tidak punya hak mengubah data ini, jadi tidak dapat mengarsipkan lampirannya.');
        $version = RowVersion::expected($request);

        // Arsip, bukan hapus: barisnya tetap ada dan berkasnya tetap di disk.
        DB::transaction(function () use ($record, $tenantId, $version): void {
            $query = DocumentAttachment::query()->where('tenant_id', $tenantId)->whereKey($record->id);
            RowVersion::claim($query, $version);
            (clone $query)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(status: 204);
    }

    /**
     * Isi berkas yang hash-nya masih sama dengan saat diunggah, disalin ke aliran sementara supaya disk hanya
     * dibaca sekali. Hash berbeda atau berkas hilang dilaporkan dan dijawab null; berkasnya tidak dikirim.
     *
     * @return resource|null
     */
    private function verifiedContent(DocumentAttachment $attachment)
    {
        $source = $this->disk()->readStream($attachment->storage_path);
        if (! is_resource($source)) {
            report(AttachmentContentMismatch::for($attachment->id, $attachment->content_hash, null));

            return null;
        }

        $copy = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
        if ($copy === false) {
            throw new RuntimeException('Aliran sementara untuk berkas lampiran tidak dapat dibuka.');
        }
        stream_copy_to_stream($source, $copy);
        fclose($source);
        rewind($copy);
        $context = hash_init('sha256');
        hash_update_stream($context, $copy);
        $actual = hash_final($context);

        if (! hash_equals($attachment->content_hash, $actual)) {
            fclose($copy);
            report(AttachmentContentMismatch::for($attachment->id, $attachment->content_hash, $actual));

            return null;
        }

        rewind($copy);

        return $copy;
    }

    private function requireReadable(AttachmentRecordType $type, string $tenantId, string $recordId): void
    {
        abort_unless(strlen($recordId) <= 64 && $type->canRead($tenantId, $recordId), 404, 'Data ini tidak ditemukan.');
    }

    private function recordType(Request $request): AttachmentRecordType
    {
        $type = $request->attributes->get(ResolveAttachmentContext::RECORD_TYPE);
        if (! $type instanceof AttachmentRecordType) {
            throw new RuntimeException('Rute lampiran dipanggil tanpa middleware ResolveAttachmentContext.');
        }

        return $type;
    }

    private function attachment(Request $request): DocumentAttachment
    {
        $attachment = $request->attributes->get(ResolveAttachmentContext::ATTACHMENT);
        if (! $attachment instanceof DocumentAttachment) {
            throw new RuntimeException('Rute lampiran dipanggil tanpa middleware ResolveAttachmentContext.');
        }

        return $attachment;
    }

    /**
     * @param  Collection<int, DocumentAttachment>|Collection<int, DocumentNote>  $attachments
     * @return array<int, string>
     */
    private function userNames(Collection $attachments): array
    {
        $ids = $attachments->pluck('created_by_user_id')->filter()->unique()->values();

        // Lewat model User: tabel `users` bisa berada di database pusat, bukan database tenant.
        /** @var array<int, string> */
        return $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function present(DocumentAttachment $attachment, array $names): array
    {
        $userId = $attachment->created_by_user_id;

        return [
            'id' => $attachment->id,
            'version' => $attachment->version,
            'record_type' => $attachment->record_type,
            'record_id' => $attachment->record_id,
            'line_number' => $attachment->line_number,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'data_class' => $attachment->data_class,
            'created_by_user_id' => $userId,
            'created_by_name' => $userId === null ? null : ($names[$userId] ?? null),
            'created_at' => $attachment->created_at?->toIso8601String(),
        ];
    }

    /** Nama cadangan untuk peramban lama: hanya ASCII, tanpa `/`, `\`, dan `%`. */
    private function asciiFileName(string $fileName): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]|[\/\\\\%"]/', '_', Str::ascii($fileName));

        return $ascii === '' ? 'lampiran' : $ascii;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('coreerp.attachments.disk'));
    }

    private function maxKb(): int
    {
        return (int) config('coreerp.attachments.max_kb');
    }

    /** @return list<string> */
    private function extensions(): array
    {
        $extensions = config('coreerp.attachments.extensions');

        return is_array($extensions) ? array_values(array_map(strval(...), $extensions)) : [];
    }
}
