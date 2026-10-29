<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Illuminate\Http\UploadedFile;

/** Lampiran draf disimpan bersama induknya; aliran dokumen hanya meneruskan referensi berkas. */
interface RecordDocuments
{
    /**
     * max_kb membatasi tiap berkas; max_total_kb membatasi jumlah ukuran seluruh berkas sekali simpan.
     *
     * @return array{max_kb: int, extensions: list<string>, max_files: int, max_total_kb: int}
     */
    public function limits(): array;

    /** @return list<UploadedFile> */
    public function validateUploads(mixed $files): array;

    /** @param list<UploadedFile> $files */
    public function append(string $recordType, string $recordId, array $files): void;

    public function flow(string $sourceType, string $sourceId, string $targetType): void;
}
