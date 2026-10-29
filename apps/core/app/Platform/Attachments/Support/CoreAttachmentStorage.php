<?php

declare(strict_types=1);

namespace App\Platform\Attachments\Support;

use App\Platform\Attachments\Models\DocumentAttachment;
use App\Platform\Modules\Contracts\AttachmentRecordType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CoreAttachmentStorage
{
    /**
     * Berkas ditulis lebih dulu, baru barisnya. Baris yang gagal disimpan membuang berkasnya lagi, supaya tidak
     * ada berkas tanpa baris; yang dibuang berkas yang belum pernah menjadi lampiran siapa pun.
     */
    public function save(string $tenantId, AttachmentRecordType $type, string $recordId, ?int $lineNumber, UploadedFile $file, string $kind = 'document'): DocumentAttachment
    {
        $id = (string) Str::ulid();
        $path = 'attachments/'.$tenantId.'/'.$id;
        $source = (string) $file->getRealPath();
        $hash = hash_file('sha256', $source);
        if ($hash === false) {
            throw new RuntimeException('Berkas unggahan tidak dapat dibaca.');
        }
        $stream = fopen($source, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Berkas unggahan tidak dapat dibaca.');
        }

        $disk = $this->disk();
        try {
            $written = $disk->writeStream($path, $stream);
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if (! $written) {
            $disk->delete($path);

            throw new RuntimeException('Berkas lampiran gagal disimpan ke penyimpanan.');
        }

        try {
            if (DB::transactionLevel() > 0) {
                $cleanup = static fn () => $disk->delete($path);
                DB::afterRollBack($cleanup);
                // Laravel membuang callback savepoint yang sudah commit ketika induk luarnya rollback.
                // Daftarkan juga pada setiap induk; delete berkas baru ini aman diulang.
                /** @var DatabaseTransactionsManager $transactions */
                $transactions = app('db.transactions');
                foreach ($transactions->getPendingTransactions() as $transaction) {
                    if ($transaction->connection === DB::connection()->getName()) {
                        $transaction->addCallbackForRollback($cleanup);
                    }
                }
            }
            $attachment = DB::transaction(function () use ($id, $tenantId, $type, $recordId, $lineNumber, $file, $path, $hash, $kind): DocumentAttachment {
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
                    'kind' => $kind,
                ])->save();

                return $attachment;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        return $attachment->refresh();
    }

    /** Nama berkas tanpa folder dan karakter kendali, dipotong ke panjang kolomnya. */
    private function fileName(string $clientName): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $clientName))));

        return Str::limit($name === '' ? 'lampiran' : $name, 250, '');
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('coreerp.attachments.disk'));
    }
}
