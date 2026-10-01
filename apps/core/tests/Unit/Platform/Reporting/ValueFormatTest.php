<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Reporting;

use App\Platform\Reporting\Support\ValueFormat;
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
        yield 'angka bulat' => [new ValueFormat(ValueFormat::NUMBER), 4, '4'];
        yield 'angka pecahan' => [new ValueFormat(ValueFormat::NUMBER), 2.5, '2,5'];
        yield 'angka ribuan' => [new ValueFormat(ValueFormat::NUMBER), '1234.756', '1.234,76'];
        yield 'angka negatif' => [new ValueFormat(ValueFormat::NUMBER), -0.5, '-0,5'];
        yield 'persen bulat' => [new ValueFormat(ValueFormat::PERCENT), 25, '25%'];
        yield 'persen pecahan' => [new ValueFormat(ValueFormat::PERCENT), 12.5, '12,5%'];
        yield 'persen dua desimal' => [new ValueFormat(ValueFormat::PERCENT), '33.3333', '33,33%'];
        yield 'tanggal' => [new ValueFormat(ValueFormat::DATE), '2026-07-23', '23/07/2026'];
        yield 'tanggal dan jam' => [new ValueFormat(ValueFormat::DATE), '2026-07-23 10:15:00', '23/07/2026'];
        yield 'tanggal mustahil ditampilkan apa adanya' => [new ValueFormat(ValueFormat::DATE), '2026-02-30', '2026-02-30'];
        yield 'bulan' => [new ValueFormat(ValueFormat::MONTH), '2026-08', 'Agustus 2026'];
        yield 'bulan dari tanggal' => [new ValueFormat(ValueFormat::MONTH), '2026-07-23', 'Juli 2026'];

        // Waktu dikirim dalam UTC dan ditulis menurut zona pengguna, beserta nama zonanya (B-7). Kasus
        // klasiknya: 17.30 UTC tanggal 27 adalah 00.30 WIB tanggal 28, bukan tanggal 27.
        $wib = new ValueFormat(ValueFormat::DATETIME, timezone: 'Asia/Jakarta');
        yield 'waktu WIB melewati tengah malam' => [$wib, '2026-09-27 17:30:00', '28/09/2026 00:30 WIB'];
        yield 'waktu ISO 8601 berakhiran Z' => [$wib, '2026-09-27T17:30:00Z', '28/09/2026 00:30 WIB'];
        yield 'waktu ISO 8601 dengan mikrodetik' => [$wib, '2026-09-27T17:30:00.000000Z', '28/09/2026 00:30 WIB'];
        yield 'waktu yang membawa offset sendiri' => [$wib, '2026-09-28T01:30:00+08:00', '28/09/2026 00:30 WIB'];
        yield 'waktu WIB di Pontianak' => [new ValueFormat(ValueFormat::DATETIME, timezone: 'Asia/Pontianak'), '2026-09-28 07:05:00', '28/09/2026 14:05 WIB'];
        yield 'waktu WITA' => [new ValueFormat(ValueFormat::DATETIME, timezone: 'Asia/Makassar'), '2026-09-28 06:05:00', '28/09/2026 14:05 WITA'];
        yield 'waktu WIT' => [new ValueFormat(ValueFormat::DATETIME, timezone: 'Asia/Jayapura'), '2026-09-28 05:05:00', '28/09/2026 14:05 WIT'];
        yield 'waktu UTC' => [new ValueFormat(ValueFormat::DATETIME), '2026-09-28 06:05:00', '28/09/2026 06:05 UTC'];
        yield 'waktu zona luar Indonesia ditulis selisihnya' => [new ValueFormat(ValueFormat::DATETIME, timezone: 'Asia/Tokyo'), '2026-09-28 05:05:00', '28/09/2026 14:05 UTC+09:00'];
        yield 'selisih mengikuti waktu musim panas' => [new ValueFormat(ValueFormat::DATETIME, timezone: 'America/New_York'), '2026-07-01 16:00:00', '01/07/2026 12:00 UTC-04:00'];
        yield 'selisih musim dingin' => [new ValueFormat(ValueFormat::DATETIME, timezone: 'America/New_York'), '2026-12-01 17:00:00', '01/12/2026 12:00 UTC-05:00'];
        yield 'tanggal tanpa jam bukan waktu' => [$wib, '2026-09-28', '2026-09-28'];
        yield 'waktu tak terbaca ditampilkan apa adanya' => [$wib, 'kemarin sore', 'kemarin sore'];
        yield 'waktu kosong' => [$wib, null, ''];
        // Tanggal tidak punya zona: pengguna di zona mana pun melihat tanggal yang sama.
        yield 'tanggal tidak digeser zona' => [new ValueFormat(ValueFormat::DATE, timezone: 'Pacific/Kiritimati'), '2026-09-28', '28/09/2026'];
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
        $this->assertSame([2.5, 'General'], (new ValueFormat(ValueFormat::NUMBER))->cell('2.5'));
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

    public function test_excel_cell_writes_datetime_in_the_user_zone_and_names_the_zone(): void
    {
        // Excel tidak mengenal zona: selnya jam menurut zona pengguna, nama zonanya di format sel.
        $this->assertSame(
            [(float) ExcelDate::dateTimeToExcel(new DateTimeImmutable('2026-09-28 00:30:00')), 'dd/mm/yyyy hh:mm "WIB"'],
            (new ValueFormat(ValueFormat::DATETIME, timezone: 'Asia/Jakarta'))->cell('2026-09-27 17:30:00'),
        );
        $this->assertSame(
            [(float) ExcelDate::dateTimeToExcel(new DateTimeImmutable('2026-09-28 14:05:00')), 'dd/mm/yyyy hh:mm "UTC+09:00"'],
            (new ValueFormat(ValueFormat::DATETIME, timezone: 'Asia/Tokyo'))->cell('2026-09-28T05:05:00Z'),
        );
        $this->assertNull((new ValueFormat(ValueFormat::DATETIME, timezone: 'Asia/Jakarta'))->cell('2026-09-28'));
    }

    public function test_datetime_with_an_impossible_value_is_written_as_is(): void
    {
        // Bentuknya cocok, isinya mustahil: ditulis apa adanya, tidak digeser, tidak melempar.
        $format = new ValueFormat(ValueFormat::DATETIME, timezone: 'Asia/Jakarta');

        $this->assertSame('2026-13-40 25:61:00', $format->text('2026-13-40 25:61:00'));
    }

    public function test_excel_cell_falls_back_to_text_for_empty_or_unreadable_values(): void
    {
        $this->assertNull((new ValueFormat(ValueFormat::MONEY, 2, 'Rp'))->cell(''));
        $this->assertNull((new ValueFormat(ValueFormat::MONEY, 2, 'Rp'))->cell('belum dinilai'));
        $this->assertNull((new ValueFormat(ValueFormat::DATE))->cell('bukan tanggal'));
    }
}
