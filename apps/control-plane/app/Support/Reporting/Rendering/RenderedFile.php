<?php

namespace App\Support\Reporting\Rendering;

/** Berkas hasil render di direktori sementara; pemanggil memindahkannya ke penyimpanan. */
final class RenderedFile
{
    public const MIME = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        public readonly string $localPath,
        public readonly string $format,
    ) {}

    public function mime(): string
    {
        return self::MIME[$this->format] ?? 'application/octet-stream';
    }

    public function cleanup(): void
    {
        if (is_file($this->localPath)) {
            @unlink($this->localPath);
        }
    }
}
