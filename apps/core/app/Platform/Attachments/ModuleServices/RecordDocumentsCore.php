<?php

declare(strict_types=1);

namespace App\Platform\Attachments\ModuleServices;

use App\Platform\Attachments\Models\DocumentAttachment;
use App\Platform\Attachments\Support\CoreAttachmentStorage;
use App\Platform\Modules\Contracts\AttachmentFlowTarget;
use App\Platform\Modules\Contracts\AttachmentRecordType;
use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use App\Platform\Modules\Contracts\RecordDocuments;
use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecordDocumentsCore implements RecordDocuments
{
    public function __construct(
        private readonly AttachmentRecordTypes $types,
        private readonly TenantContext $tenant,
        private readonly CoreAttachmentStorage $storage,
    ) {}

    public function limits(): array
    {
        $extensions = config('coreerp.attachments.extensions');

        return [
            'max_kb' => (int) config('coreerp.attachments.max_kb'),
            'extensions' => is_array($extensions) ? array_values(array_map(strval(...), $extensions)) : [],
            'max_files' => (int) config('coreerp.attachments.max_files'),
            'max_total_kb' => (int) config('coreerp.attachments.max_total_kb'),
        ];
    }

    public function validateUploads(mixed $files): array
    {
        $limits = $this->limits();
        $extensions = implode(',', $limits['extensions']);
        $data = Validator::make(['attachments' => $files], [
            'attachments' => ['nullable', 'array', 'max:'.$limits['max_files']],
            'attachments.*' => ['required', 'file', 'max:'.$limits['max_kb'], 'extensions:'.$extensions, 'mimes:'.$extensions],
        ], [
            'attachments.array' => 'Pilih berkas yang akan dilampirkan.',
            'attachments.max' => 'Pilih paling banyak '.$limits['max_files'].' berkas sekaligus.',
            'attachments.*.required' => 'Pilih berkas yang akan dilampirkan.',
            'attachments.*.file' => 'Berkas gagal terunggah. Coba lagi, atau pilih berkas yang lebih kecil.',
            'attachments.*.uploaded' => 'Berkas gagal terunggah. Coba lagi, atau pilih berkas yang lebih kecil.',
            'attachments.*.max' => 'Berkas terlalu besar. Ukuran paling besar '.$limits['max_kb'].' KB.',
            'attachments.*.extensions' => 'Jenis berkas ini tidak dapat dilampirkan. Yang dapat dilampirkan: '.$extensions.'.',
            'attachments.*.mimes' => 'Isi berkas tidak sesuai dengan jenisnya. Yang dapat dilampirkan: '.$extensions.'.',
        ])->validate();

        /** @var list<UploadedFile> $uploads */
        $uploads = array_values($data['attachments'] ?? []);
        $totalBytes = array_sum(array_map(static fn (UploadedFile $file): int => (int) $file->getSize(), $uploads));
        if ($totalBytes > $limits['max_total_kb'] * 1024) {
            throw ValidationException::withMessages([
                'attachments' => 'Ukuran seluruh lampiran paling besar '.$limits['max_total_kb'].' KB. Kurangi berkas yang dipilih.',
            ]);
        }

        return $uploads;
    }

    public function append(string $recordType, string $recordId, array $files): void
    {
        $type = $this->requireType($recordType);
        $tenantId = $this->tenant->tenantId();
        abort_unless(strlen($recordId) <= 64 && $type->canRead($tenantId, $recordId), 404, 'Data ini tidak ditemukan.');
        abort_unless($type->canChange($tenantId, $recordId), 403, 'Kamu tidak punya hak mengubah data ini, jadi belum dapat melampirkan berkas.');
        $uploads = $this->validateUploads($files);
        $append = function () use ($type, $tenantId, $recordId, $uploads): void {
            foreach ($uploads as $file) {
                $this->storage->save($tenantId, $type, $recordId, null, $file);
            }
        };
        if (DB::transactionLevel() > 0) {
            $append();
        } else {
            DB::transaction($append);
        }
    }

    public function flow(string $sourceType, string $sourceId, string $targetType): void
    {
        $source = $this->requireType($sourceType);
        $target = $this->requireType($targetType);
        $tenantId = $this->tenant->tenantId();
        abort_unless(strlen($sourceId) <= 64 && $source->canRead($tenantId, $sourceId), 404, 'Data ini tidak ditemukan.');
        abort_unless($source->moduleId() !== null && $source->moduleId() === $target->moduleId()
            && $target instanceof AttachmentFlowTarget, 422, 'Lampiran tidak dapat diteruskan ke data ini.');

        DB::transaction(function () use ($source, $sourceId, $target, $tenantId): void {
            // Kunci berkas asal menyerialkan penerusan ulang agar tidak membuat referensi ganda.
            $attachments = DocumentAttachment::query()->where('tenant_id', $tenantId)
                ->where('record_type', $source->recordType())->where('record_id', $sourceId)
                ->where('kind', 'document')->orderBy('id')->lockForUpdate()->get();
            if ($attachments->isEmpty()) {
                return;
            }

            $batch = [];
            foreach ($target->inheritedAttachmentRecords($tenantId, $source->recordType(), $sourceId) as $record) {
                $batch[] = $record;
                if (count($batch) === 500) {
                    $this->inheritBatch($tenantId, $target->recordType(), $batch, $attachments);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->inheritBatch($tenantId, $target->recordType(), $batch, $attachments);
            }
        });
    }

    private function requireType(string $recordType): AttachmentRecordType
    {
        $type = $this->types->for($recordType);
        abort_unless($type instanceof AttachmentRecordType, 404, 'Data ini tidak ditemukan.');

        return $type;
    }

    /**
     * @param  list<array{record_id: string, source_line_number: ?int}>  $records
     * @param  Collection<int, DocumentAttachment>  $attachments
     */
    private function inheritBatch(string $tenantId, string $targetType, array $records, Collection $attachments): void
    {
        $existing = DocumentAttachment::withTrashed()->where('tenant_id', $tenantId)
            ->where('record_type', $targetType)->whereIn('record_id', array_column($records, 'record_id'))
            ->whereIn('storage_path', $attachments->pluck('storage_path'))->get(['record_id', 'storage_path']);
        $known = [];
        foreach ($existing as $attachment) {
            $known[$attachment->record_id][$attachment->storage_path] = true;
        }

        $rows = [];
        $now = now();
        foreach ($records as $record) {
            foreach ($attachments as $attachment) {
                if (($attachment->line_number !== null && $attachment->line_number !== $record['source_line_number'])
                    || isset($known[$record['record_id']][$attachment->storage_path])) {
                    continue;
                }
                $known[$record['record_id']][$attachment->storage_path] = true;
                $rows[] = [
                    'id' => (string) Str::ulid(), 'tenant_id' => $tenantId,
                    'record_type' => $targetType, 'record_id' => $record['record_id'],
                    'line_number' => null, 'kind' => 'document', 'file_name' => $attachment->file_name,
                    'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes,
                    'storage_path' => $attachment->storage_path, 'content_hash' => $attachment->content_hash,
                    'data_class' => $attachment->data_class, 'created_by_user_id' => $attachment->created_by_user_id,
                    'created_at' => $now, 'updated_at' => $now,
                ];
                if (count($rows) === 500) {
                    DocumentAttachment::query()->insert($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            DocumentAttachment::query()->insert($rows);
        }
    }
}
