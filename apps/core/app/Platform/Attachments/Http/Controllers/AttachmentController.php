<?php

declare(strict_types=1);

namespace App\Platform\Attachments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Attachments\Http\Middleware\ResolveAttachmentContext;
use App\Platform\Attachments\Models\DocumentAttachment;
use App\Platform\Attachments\Support\AttachmentContentMismatch;
use App\Platform\Identity\Models\User;
use App\Support\Modules\Contracts\AttachmentRecordType;
use App\Support\Modules\Contracts\RowVersion;
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
use Throwable;

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
    public function index(Request $request, string $recordType, string $recordId): JsonResponse
    {
        $tenantId = $this->currentMembership($request)->tenant_id;
        $type = $this->recordType($request);
        $this->requireReadable($type, $tenantId, $recordId);

        $attachments = DocumentAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('record_type', $type->recordType())
            ->where('record_id', $recordId)
            ->orderByRaw('line_number nulls first')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $names = $this->userNames($attachments);

        return response()->json([
            'data' => $attachments->map(fn (DocumentAttachment $attachment): array => $this->present($attachment, $names))->values(),
            'meta' => [
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
        $attachment = $this->save($tenantId, $type, $recordId, $lineNumber, $file);

        return response()->json(['data' => $this->present($attachment, $this->userNames(collect([$attachment])))], 201);
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
     * Berkas ditulis lebih dulu, baru barisnya. Baris yang gagal disimpan membuang berkasnya lagi, supaya tidak
     * ada berkas tanpa baris; yang dibuang berkas yang belum pernah menjadi lampiran siapa pun.
     */
    private function save(string $tenantId, AttachmentRecordType $type, string $recordId, ?int $lineNumber, UploadedFile $file): DocumentAttachment
    {
        $id = (string) Str::ulid();
        $path = 'attachments/'.$tenantId.'/'.$id;
        $source = (string) $file->getRealPath();
        $hash = hash_file('sha256', $source);
        $stream = fopen($source, 'rb');
        if ($hash === false || $stream === false) {
            throw new RuntimeException('Berkas unggahan tidak dapat dibaca.');
        }

        $disk = $this->disk();
        $written = $disk->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (! $written) {
            throw new RuntimeException('Berkas lampiran gagal disimpan ke penyimpanan.');
        }

        try {
            $attachment = new DocumentAttachment;
            $attachment->forceFill([
                'id' => $id,
                'tenant_id' => $tenantId,
                'record_type' => $type->recordType(),
                'record_id' => $recordId,
                'line_number' => $lineNumber,
                'file_name' => $this->fileName($file->getClientOriginalName()),
                'mime_type' => Str::limit((string) $file->getMimeType(), 150, ''),
                'size_bytes' => (int) $file->getSize(),
                'storage_path' => $path,
                'content_hash' => $hash,
                'data_class' => $type->dataClass()->value,
            ])->save();
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        return $attachment->refresh();
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
     * @param  Collection<int, DocumentAttachment>  $attachments
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

    /** Nama berkas tanpa folder dan karakter kendali, dipotong ke panjang kolomnya. */
    private function fileName(string $clientName): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $clientName))));

        return Str::limit($name === '' ? 'lampiran' : $name, 250, '');
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
