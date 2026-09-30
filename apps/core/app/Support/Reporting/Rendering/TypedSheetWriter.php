<?php

declare(strict_types=1);

namespace App\Support\Reporting\Rendering;

use App\Support\Reporting\ValueFormat;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Penulis lembar data tanpa layout: "Excel (data saja)" (K-26) dan ekspor daftar di layar (K-27).
 *
 * **Kenapa OpenSpout, bukan PhpSpreadsheet.** PhpSpreadsheet menyusun seluruh buku kerja di memori sebelum
 * menulisnya — sekitar 1 KB per sel — sehingga 50.000 baris kali delapan kolom sudah ratusan MB. OpenSpout
 * menulis baris demi baris ke berkas, jadi memorinya datar berapa pun jumlah barisnya. Layout ber-template
 * tetap memakai PhpSpreadsheet; yang ditulis di sini hanya data, tanpa template.
 *
 * **Nilai bertipe menjadi sel bertipe.** Kolom yang menyatakan tipe (`money`, `date`, …) ditulis lewat
 * {@see ValueFormat::cell()}, sumber yang sama dengan layout Excel: angka asli dengan format sel, tanggal
 * sebagai tanggal Excel. Di CSV angka ditulis mentah dan tanggal ditulis seperti di layar.
 *
 * **Teks tidak pernah menjadi rumus.** Nilai yang diawali `=` ditulis sebagai teks di xlsx; di CSV nilai
 * teks yang diawali `=`, `+`, `-`, atau `@` diberi awalan petik supaya spreadsheet tidak menjalankannya.
 */
final class TypedSheetWriter
{
    /** Baris per lembar Excel, termasuk baris judul. */
    public const XLSX_MAX_ROWS = 1048576;

    private XlsxWriter|CsvWriter $writer;

    private bool $firstSheet = true;

    /** @var array<string, Style> */
    private array $styles = [];

    private Style $headerStyle;

    public function __construct(public readonly string $format, string $path)
    {
        if (! in_array($format, ['xlsx', 'csv'], true)) {
            throw new RenderException("Format lembar data `{$format}` tidak didukung.");
        }
        $this->writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $this->writer->openToFile($path);
        $this->headerStyle = (new Style)->withFontBold(true);
    }

    /** Lembar baru bernama; lembar pertama memakai lembar bawaan buku kerja. CSV hanya punya satu lembar. */
    public function sheet(string $name): void
    {
        if (! $this->writer instanceof XlsxWriter) {
            return;
        }
        $sheet = $this->firstSheet ? $this->writer->getCurrentSheet() : $this->writer->addNewSheetAndMakeItCurrent();
        $this->firstSheet = false;
        // Nama lembar Excel paling panjang 31 karakter dan tidak boleh memuat tanda tertentu.
        $sheet->setName(mb_substr((string) preg_replace('/[\\\\\/?*\[\]:]/', ' ', $name), 0, 31));
    }

    /** @param list<string> $labels */
    public function header(array $labels): void
    {
        $this->writer->addRow(new Row(array_map(
            fn (string $label): Cell => new StringCell($label, $this->writer instanceof XlsxWriter ? $this->headerStyle : null),
            $labels,
        )));
    }

    /**
     * @param  list<string|int|float|null>  $values
     * @param  list<?ValueFormat>  $formats  Per kolom, sejajar dengan `$values`.
     */
    public function row(array $values, array $formats): void
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $cells[] = $this->writer instanceof XlsxWriter
                ? $this->xlsxCell($value, $formats[$index] ?? null)
                : $this->csvCell($value, $formats[$index] ?? null);
        }
        $this->writer->addRow(new Row($cells));
    }

    public function close(): void
    {
        $this->writer->close();
    }

    private function xlsxCell(string|int|float|null $value, ?ValueFormat $format): Cell
    {
        $typed = $format?->cell($value);
        if ($typed !== null) {
            return new NumericCell($typed[0], $this->style($typed[1]));
        }
        if ($value === null || $value === '') {
            return new EmptyCell(null);
        }
        if ($format !== null) {
            // Nilai bertipe yang tidak terbaca sebagai tipenya ditulis seperti di layar, bukan dikosongkan.
            return new StringCell($format->text($value));
        }

        return is_string($value) ? new StringCell($value) : new NumericCell($value);
    }

    private function csvCell(string|int|float|null $value, ?ValueFormat $format): Cell
    {
        if ($value === null || $value === '') {
            return new EmptyCell(null);
        }
        if ($format !== null && in_array($format->type, [ValueFormat::MONEY, ValueFormat::NUMBER, ValueFormat::PERCENT], true) && is_numeric($value)) {
            return new NumericCell(is_string($value) ? (float) $value : $value);
        }
        $text = $format !== null ? $format->text($value) : (string) $value;
        if (is_string($value) && preg_match('/^[=+\-@]/', $text) === 1) {
            $text = "'".$text;
        }

        return new StringCell($text);
    }

    private function style(string $formatCode): Style
    {
        return $this->styles[$formatCode] ??= (new Style)->withFormat($formatCode);
    }
}
