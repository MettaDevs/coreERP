<?php

namespace App\Support\Reporting;

/**
 * Layout yang sudah diselesaikan menjadi berkas lokal siap dibaca renderer. Untuk layout
 * unggahan, berkas ini salinan sementara dari disk penyimpanan; pemanggil yang membuatnya
 * bertanggung jawab menghapusnya lewat {@see cleanup()}.
 */
final class LayoutFile
{
    public function __construct(
        public readonly string $ref,
        public readonly string $name,
        public readonly string $format,
        public readonly string $localPath,
        private readonly bool $temporary,
    ) {}

    /** @return list<string> Format keluaran yang dapat dihasilkan layout ini. */
    public function outputFormats(): array
    {
        return self::outputFormatsFor($this->format);
    }

    /** @return list<string> */
    public static function outputFormatsFor(string $layoutFormat): array
    {
        return match ($layoutFormat) {
            'docx' => ['pdf', 'docx'],
            'xlsx' => ['xlsx', 'pdf'],
            default => [],
        };
    }

    public function isTemporary(): bool
    {
        return $this->temporary;
    }

    public function cleanup(): void
    {
        if ($this->temporary && is_file($this->localPath)) {
            @unlink($this->localPath);
        }
    }
}
