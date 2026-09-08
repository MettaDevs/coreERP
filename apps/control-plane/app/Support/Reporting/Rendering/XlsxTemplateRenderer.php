<?php

namespace App\Support\Reporting\Rendering;

use App\Support\Reporting\ReportData;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Throwable;

/**
 * Mengisi layout Excel.
 *
 * Aturannya sejalan dengan layout Word: `${kode}` untuk nilai tunggal, dan satu baris
 * lembar yang memuat `${baris.asset_kode}` menjadi baris template yang digandakan per
 * baris dataset. Gaya sel baris template disalin ke setiap baris hasil, dan rumus di
 * bawahnya (misalnya `=SUM`) bergeser mengikuti sisipan baris — PhpSpreadsheet yang
 * menggeser referensinya, seperti Excel sendiri saat baris disisipkan.
 *
 * Sel yang seluruh isinya satu placeholder bernilai angka ditulis sebagai angka, supaya
 * kolom jam dan jumlah dapat dijumlahkan pengguna di Excel.
 */
final class XlsxTemplateRenderer
{
    private const MACRO_PATTERN = '/\$\{([A-Za-z0-9_.]+)\}/';

    private const WHOLE_CELL_PATTERN = '/^\s*\$\{([A-Za-z0-9_.]+)\}\s*$/';

    public function render(string $templatePath, ReportData $data): RenderedFile
    {
        try {
            $spreadsheet = IOFactory::load($templatePath);
        } catch (Throwable $exception) {
            throw new RenderException('Layout Excel tidak dapat dibuka: '.$exception->getMessage(), previous: $exception);
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $this->expandTables($sheet, $data);
            $this->placeImages($sheet, $data);
            $this->fillFields($sheet, $data);
        }

        $output = $this->tempPath('xlsx');
        (new XlsxWriter($spreadsheet))->save($output);
        $spreadsheet->disconnectWorksheets();

        return new RenderedFile($output, 'xlsx');
    }

    private function expandTables(Worksheet $sheet, ReportData $data): void
    {
        // Baris template dicari lebih dulu lalu diproses dari bawah ke atas, supaya
        // penyisipan baris tidak menggeser nomor baris template yang belum diproses.
        $templates = [];
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($row = 1; $row <= $highestRow; $row++) {
            foreach ($data->tables as $table => $rows) {
                if ($this->rowMentions($sheet, $row, $highestColumn, $table)) {
                    $templates[] = [$row, $table];
                    break;
                }
            }
        }

        foreach (array_reverse($templates) as [$row, $table]) {
            $rows = array_values($data->tables[$table]);
            $count = count($rows);
            if ($count > 1) {
                $sheet->insertNewRowBefore($row + 1, $count - 1);
            }
            $cells = [];
            for ($column = 1; $column <= $highestColumn; $column++) {
                $cells[$column] = $sheet->getCell([$column, $row])->getValue();
            }
            if ($count === 0) {
                $this->writeRow($sheet, $row, $cells, $table, []);

                continue;
            }
            foreach ($rows as $index => $values) {
                $target = $row + $index;
                if ($index > 0) {
                    $sheet->duplicateStyle($sheet->getStyle("A{$row}"), "A{$target}");
                    for ($column = 1; $column <= $highestColumn; $column++) {
                        $from = Coordinate::stringFromColumnIndex($column);
                        $sheet->duplicateStyle($sheet->getStyle("{$from}{$row}"), "{$from}{$target}");
                    }
                }
                $this->writeRow($sheet, $target, $cells, $table, $values);
            }
            if ($count > 1) {
                $this->extendRangesEndingAt($sheet, $row, $row + $count - 1);
            }
        }
    }

    /**
     * Rumus seperti `=SUM(B3:B3)` yang ditulis pembuat template pada satu baris template
     * harus mencakup semua baris hasil. PhpSpreadsheet (dan Excel) tidak memperluas
     * rentang yang berakhir tepat di baris tempat penyisipan dimulai, jadi rentang yang
     * berakhir di baris template diperpanjang sampai baris hasil terakhir di sini.
     */
    private function extendRangesEndingAt(Worksheet $sheet, int $templateRow, int $lastRow): void
    {
        foreach ($sheet->getRowIterator() as $rowIterator) {
            $iterator = $rowIterator->getCellIterator();
            $iterator->setIterateOnlyExistingCells(true);
            foreach ($iterator as $cell) {
                $value = $cell->getValue();
                if (! is_string($value) || ! str_starts_with($value, '=')) {
                    continue;
                }
                $adjusted = preg_replace_callback(
                    '/(\$?[A-Z]{1,3}\$?)(\d+):(\$?[A-Z]{1,3}\$?)(\d+)/',
                    function (array $match) use ($templateRow, $lastRow): string {
                        if ((int) $match[4] === $templateRow && (int) $match[2] <= $templateRow) {
                            return $match[1].$match[2].':'.$match[3].$lastRow;
                        }

                        return $match[0];
                    },
                    $value,
                );
                if ($adjusted !== null && $adjusted !== $value) {
                    $cell->setValue($adjusted);
                }
            }
        }
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, string|int|float|null>  $values
     */
    private function writeRow(Worksheet $sheet, int $row, array $cells, string $table, array $values): void
    {
        foreach ($cells as $column => $template) {
            if (! is_string($template) || ! str_contains($template, '${')) {
                continue;
            }
            $this->writeCell($sheet, [$column, $row], $template, function (string $macro) use ($table, $values): string|int|float|null {
                if (! str_starts_with($macro, $table.'.')) {
                    return null;
                }

                return $values[substr($macro, strlen($table) + 1)] ?? '';
            });
        }
    }

    /**
     * Sel yang seluruh isinya satu placeholder gambar diganti gambar yang menempel pada
     * sel itu; teks placeholder-nya dikosongkan. Placeholder gambar tanpa berkas dibiarkan
     * untuk dikosongkan tahap berikutnya.
     */
    private function placeImages(Worksheet $sheet, ReportData $data): void
    {
        if ($data->images === []) {
            return;
        }
        foreach ($sheet->getRowIterator() as $row) {
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(true);
            foreach ($iterator as $cell) {
                $value = $cell->getValue();
                if (! is_string($value) || preg_match(self::WHOLE_CELL_PATTERN, $value, $match) !== 1) {
                    continue;
                }
                $image = $data->images[$match[1]] ?? null;
                if ($image === null || ! is_file($image['path'])) {
                    continue;
                }
                $drawing = new Drawing;
                $drawing->setPath($image['path']);
                $drawing->setCoordinates($cell->getCoordinate());
                $drawing->setWidth((int) round($image['width_mm'] * 96 / 25.4));
                $drawing->setOffsetX(2);
                $drawing->setOffsetY(2);
                $drawing->setWorksheet($sheet);
                $cell->setValueExplicit('', DataType::TYPE_STRING);
            }
        }
    }

    private function fillFields(Worksheet $sheet, ReportData $data): void
    {
        foreach ($sheet->getRowIterator() as $row) {
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(true);
            foreach ($iterator as $cell) {
                $value = $cell->getValue();
                if (! is_string($value) || ! str_contains($value, '${')) {
                    continue;
                }
                $this->writeCell($sheet, $cell->getCoordinate(), $value, fn (string $macro): string|int|float|null => $data->fields[$macro] ?? '');
            }
        }
    }

    /**
     * @param  array{int,int}|string  $coordinate
     * @param  callable(string): (string|int|float|null)  $lookup  Null berarti biarkan placeholder untuk tahap berikutnya.
     */
    private function writeCell(Worksheet $sheet, array|string $coordinate, string $template, callable $lookup): void
    {
        $cell = $sheet->getCell($coordinate);
        if (preg_match(self::WHOLE_CELL_PATTERN, $template, $whole) === 1) {
            $value = $lookup($whole[1]);
            if ($value === null) {
                return;
            }
            if (is_int($value) || is_float($value)) {
                $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
            } else {
                $cell->setValueExplicit($value, DataType::TYPE_STRING);
            }

            return;
        }

        $replaced = preg_replace_callback(self::MACRO_PATTERN, function (array $match) use ($lookup): string {
            $value = $lookup($match[1]);

            return $value === null ? $match[0] : (string) $value;
        }, $template);
        $cell->setValueExplicit($replaced ?? $template, DataType::TYPE_STRING);
    }

    private function rowMentions(Worksheet $sheet, int $row, int $highestColumn, string $table): bool
    {
        for ($column = 1; $column <= $highestColumn; $column++) {
            $value = $sheet->getCell([$column, $row])->getValue();
            if (is_string($value) && str_contains($value, '${'.$table.'.')) {
                return true;
            }
        }

        return false;
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
