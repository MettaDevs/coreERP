<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Support\Reporting\ValueFormat;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Aturan tampil nilai laporan bertipe, tanpa database: teks untuk Word dan layar, sel untuk
 * Excel. Presisi uang datang dari setelan tenant dan diuji di test fitur; di sini ia hanya
 * argumen.
 */
class ValueFormatTest extends TestCase
{
    /** @return iterable<string, array{ValueFormat, string|int|float|null, string}> */
    public static function texts(): iterable
    {
        $idr = new ValueFormat(ValueFormat::MONEY, 2, 'Rp');

        yield 'uang dua desimal' => [$idr, 20000000, 'Rp 20.000.000,00'];
        yield 'uang dari kolom decimal database' => [$idr, '416666.67', 'Rp 416.666,67'];
        yield 'uang dibulatkan setengah menjauhi nol' => [$idr, '416666.665', 'Rp 416.666,67'];
        yield 'uang dari float hitungan' => [$idr, 5208333.333333333, 'Rp 5.208.333,33'];
        yield 'uang negatif' => [$idr, -1500.5, '-Rp 1.500,50'];
        yield 'uang di bawah seribu' => [$idr, 950, 'Rp 950,00'];
        yield 'uang tanpa desimal' => [new ValueFormat(ValueFormat::MONEY, 0, 'Rp'), '1234567.50', 'Rp 1.234.568'];
        yield 'uang tanpa simbol' => [new ValueFormat(ValueFormat::MONEY, 2), 1000, '1.000,00'];
        yield 'uang bukan angka ditampilkan apa adanya' => [$idr, 'belum dinilai', 'belum dinilai'];
        yield 'uang kosong' => [$idr, null, ''];
        yield 'persen bulat' => [new ValueFormat(ValueFormat::PERCENT), 25, '25%'];
        yield 'persen pecahan' => [new ValueFormat(ValueFormat::PERCENT), 12.5, '12,5%'];
        yield 'persen dua desimal' => [new ValueFormat(ValueFormat::PERCENT), '33.3333', '33,33%'];
        yield 'tanggal' => [new ValueFormat(ValueFormat::DATE), '2026-07-23', '23/07/2026'];
        yield 'tanggal dan jam' => [new ValueFormat(ValueFormat::DATE), '2026-07-23 10:15:00', '23/07/2026'];
        yield 'tanggal mustahil ditampilkan apa adanya' => [new ValueFormat(ValueFormat::DATE), '2026-02-30', '2026-02-30'];
        yield 'bulan' => [new ValueFormat(ValueFormat::MONTH), '2026-08', 'Agustus 2026'];
        yield 'bulan dari tanggal' => [new ValueFormat(ValueFormat::MONTH), '2026-07-23', 'Juli 2026'];
    }

    #[DataProvider('texts')]
    public function test_text_for_word_and_screen(ValueFormat $format, string|int|float|null $value, string $expected): void
    {
        $this->assertSame($expected, $format->text($value));
    }

    public function test_excel_cell_keeps_money_as_number(): void
    {
        $this->assertSame(
            [20000000.0, '"Rp "#,##0.00;-"Rp "#,##0.00'],
            (new ValueFormat(ValueFormat::MONEY, 2, 'Rp'))->cell('20000000.00'),
        );
        $this->assertSame([1500.0, '#,##0;-#,##0'], (new ValueFormat(ValueFormat::MONEY, 0))->cell(1500));
    }

    public function test_excel_cell_keeps_percent_dates_and_months_as_values(): void
    {
        $this->assertSame([0.125, '0.00%'], (new ValueFormat(ValueFormat::PERCENT))->cell(12.5));
        $this->assertSame(
            [(float) ExcelDate::dateTimeToExcel(new DateTimeImmutable('2026-07-23')), 'dd/mm/yyyy'],
            (new ValueFormat(ValueFormat::DATE))->cell('2026-07-23 10:15:00'),
        );
        // Bulan ditulis sebagai tanggal pertamanya, supaya dapat diurutkan dan disaring di
        // Excel; kode bahasanya menjaga nama bulan tetap Indonesia.
        $this->assertSame(
            [(float) ExcelDate::dateTimeToExcel(new DateTimeImmutable('2026-07-01')), '[$-421]mmmm yyyy'],
            (new ValueFormat(ValueFormat::MONTH))->cell('2026-07-23'),
        );
    }

    public function test_excel_cell_falls_back_to_text_for_empty_or_unreadable_values(): void
    {
        $this->assertNull((new ValueFormat(ValueFormat::MONEY, 2, 'Rp'))->cell(''));
        $this->assertNull((new ValueFormat(ValueFormat::MONEY, 2, 'Rp'))->cell('belum dinilai'));
        $this->assertNull((new ValueFormat(ValueFormat::DATE))->cell('bukan tanggal'));
    }
}
