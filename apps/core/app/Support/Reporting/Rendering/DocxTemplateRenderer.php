<?php

namespace App\Support\Reporting\Rendering;

use App\Support\Reporting\ReportData;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use Throwable;

/**
 * Mengisi layout Word.
 *
 * Aturan layout yang harus dipahami pembuat template:
 * - `${kode}` diganti nilai tunggal dari dataset.
 * - Baris tabel Word yang memuat `${baris.asset_kode}` digandakan sebanyak baris
 *   tabel `baris` pada dataset; setiap placeholder berawalan `baris.` pada baris itu
 *   diisi per baris. Placeholder tabel di luar baris tabel Word tidak dapat digandakan
 *   dan dilaporkan sebagai kesalahan layout, bukan dicetak apa adanya.
 * - Placeholder yang tidak dikenal dikosongkan, bukan dibiarkan, supaya dokumen yang
 *   sampai ke vendor tidak pernah memuat `${...}`.
 */
final class DocxTemplateRenderer
{
    public function render(string $templatePath, ReportData $data): RenderedFile
    {
        Settings::setOutputEscapingEnabled(true);
        try {
            $template = new TemplateProcessor($templatePath);
        } catch (Throwable $exception) {
            throw new RenderException('Layout Word tidak dapat dibuka: '.$exception->getMessage(), previous: $exception);
        }

        $variables = $template->getVariables();
        foreach ($data->tables as $table => $rows) {
            $macros = array_values(array_filter($variables, fn (string $name): bool => str_starts_with($name, $table.'.')));
            if ($macros === []) {
                continue;
            }
            $values = array_map(
                fn (array $row): array => $this->rowValues($macros, $table, $row),
                $rows === [] ? [[]] : array_values($rows),
            );
            try {
                $template->cloneRowAndSetValues($macros[0], $values);
            } catch (Throwable $exception) {
                throw new RenderException(
                    "Placeholder `\${{$macros[0]}}` harus berada di dalam baris tabel Word agar dapat diulang per baris.",
                    previous: $exception,
                );
            }
        }

        // Gambar lebih dulu: placeholder gambar yang tidak punya berkas dikosongkan di
        // langkah berikutnya bersama placeholder lain, jadi kop tanpa logo kanan tetap rapi.
        $variables = $template->getVariables();
        foreach ($data->images as $key => $image) {
            if (in_array($key, $variables, true) && is_file($image['path'])) {
                $template->setImageValue($key, [
                    'path' => $image['path'],
                    'width' => $this->pixels($image['width_mm']),
                    'ratio' => true,
                ]);
            }
        }
        foreach ($data->fields as $key => $value) {
            $template->setValue($key, $this->text($value));
        }
        foreach ($template->getVariables() as $leftover) {
            $template->setValue($leftover, '');
        }

        $output = $this->tempPath('docx');
        $template->saveAs($output);

        return new RenderedFile($output, 'docx');
    }

    /**
     * @param  list<string>  $macros
     * @param  array<string, string|int|float|null>  $row
     * @return array<string, string>
     */
    private function rowValues(array $macros, string $table, array $row): array
    {
        $values = [];
        foreach ($macros as $macro) {
            $column = substr($macro, strlen($table) + 1);
            $values[$macro] = $this->text($row[$column] ?? null);
        }

        return $values;
    }

    private function text(string|int|float|null $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /** Lebar gambar dalam piksel Word (96 dpi) dari milimeter. */
    private function pixels(int $millimetres): int
    {
        return (int) round($millimetres * 96 / 25.4);
    }

    private function tempPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'laporan-');
        if ($path === false) {
            throw new RenderException('Direktori sementara tidak dapat ditulis.');
        }
        @unlink($path);

        return $path.'.'.$extension;
    }
}
