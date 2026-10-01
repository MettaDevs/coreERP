<?php

namespace App\Platform\Reporting\Support\Rendering;

use App\Platform\Reporting\Support\LayoutFile;
use App\Platform\Reporting\Support\ReportData;

/**
 * Dari dataset dan layout ke berkas dengan format yang diminta: layout diisi renderer
 * yang sesuai formatnya, lalu diubah ke PDF bila perlu. Pengetahuan tentang "layout
 * Word bisa jadi PDF atau Word" hanya ada di {@see LayoutFile::outputFormats()}.
 */
final class RenderPipeline
{
    public function __construct(
        private readonly DocxTemplateRenderer $docx,
        private readonly XlsxTemplateRenderer $xlsx,
        private readonly PdfConverter $pdf,
    ) {}

    public function render(LayoutFile $layout, ReportData $data, string $format): RenderedFile
    {
        if (! in_array($format, $layout->outputFormats(), true)) {
            throw new RenderException("Layout {$layout->format} tidak dapat menghasilkan format {$format}.");
        }

        $filled = match ($layout->format) {
            'docx' => $this->docx->render($layout->localPath, $data),
            'xlsx' => $this->xlsx->render($layout->localPath, $data),
            default => throw new RenderException("Format layout `{$layout->format}` tidak didukung."),
        };
        if ($format === $filled->format) {
            return $filled;
        }

        try {
            return $this->pdf->convert($filled);
        } finally {
            $filled->cleanup();
        }
    }
}
