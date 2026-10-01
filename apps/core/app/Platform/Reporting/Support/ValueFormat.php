<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Support;

use App\Foundation\Currency\Support\MoneyPrecision;
use App\Platform\Identity\Support\UserClock;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Cara menampilkan satu nilai dataset laporan yang menyatakan tipenya.
 *
 * Dataset module mengirim nilai mentah — uang sebagai angka, tanggal `2026-07-23` — dan
 * menyebut tipenya pada `fields()`. Core yang memformat, per keluaran: Word, PDF, dan layar
 * pratinjau mendapat teks ("Rp 20.000.000,00", "23/07/2026"), sedangkan Excel mendapat
 * angka atau tanggal asli beserta format selnya, supaya kolom uang dapat dijumlah dan
 * kolom tanggal dapat diurutkan. Padanannya `AutoFormatType` pada kolom laporan Business
 * Central: laporan hanya menyatakan jenis nilainya, platform yang memutuskan presisi dan
 * simbolnya dari setelan mata uang.
 *
 * Sebelum ini setiap definisi laporan memformat sendiri, dan dalam satu module saja sudah
 * ada dua aturan rupiah yang berbeda. Uang yang dikirim sebagai teks juga membuat kolomnya
 * tidak dapat dijumlah di Excel, padahal renderer Excel sengaja menulis angka sebagai angka.
 *
 * Waktu (`datetime`) dikirim module dalam UTC dan ditampilkan menurut zona waktu pengguna yang
 * mencetak, beserta nama zonanya: "28/09/2026 14:05 WITA". Tanggal tanpa jam (`date`) tidak punya
 * zona dan tidak digeser, sama seperti field tanggal di F&O.
 *
 * Nilai yang tidak dapat dibaca sebagai tipenya ditampilkan apa adanya, bukan dikosongkan:
 * data yang salah bentuk tetap terlihat di dokumen dan dapat ditelusuri.
 */
final class ValueFormat
{
    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const PERCENT = 'percent';

    public const DATE = 'date';

    public const MONTH = 'month';

    public const DATETIME = 'datetime';

    public const TYPES = [self::MONEY, self::NUMBER, self::PERCENT, self::DATE, self::MONTH, self::DATETIME];

    /** Bahasa nama bulan; seluruh layar dan dokumen CoreERP berbahasa Indonesia. */
    private const LOCALE = 'id';

    private const DATE_PATTERN = '/^(\d{4})-(\d{2})(?:-(\d{2}))?/';

    /** Tanggal dan jam, dipisah spasi atau `T`; offset di belakangnya boleh ada. */
    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/';

    /**
     * @param  int  $decimals  Khusus `money`: presisi nilai mata uangnya.
     * @param  string  $symbol  Khusus `money`: simbol mata uangnya, misalnya `Rp`.
     * @param  string  $timezone  Khusus `datetime`: zona waktu pengguna yang mencetak, nama IANA.
     */
    public function __construct(
        public readonly string $type,
        public readonly int $decimals = 0,
        public readonly string $symbol = '',
        public readonly string $timezone = 'UTC',
    ) {}

    /** Teks untuk Word, PDF, dan layar pratinjau. */
    public function text(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($this->type) {
            self::MONEY => is_numeric($value) ? $this->money($value) : (string) $value,
            self::NUMBER => is_numeric($value) ? $this->number($value) : (string) $value,
            self::PERCENT => is_numeric($value) ? $this->number($value).'%' : (string) $value,
            self::DATE => $this->date($value)?->format('d/m/Y') ?? (string) $value,
            self::MONTH => $this->date($value)?->settings(['locale' => self::LOCALE])->translatedFormat('F Y') ?? (string) $value,
            self::DATETIME => $this->dateTimeText($value),
            default => (string) $value,
        };
    }

    /**
     * Isi sel Excel: nilai asli beserta kode format selnya.
     *
     * Null berarti nilainya kosong atau tidak dapat dibaca sebagai tipenya, dan sel ditulis
     * sebagai teks dari {@see text()}.
     *
     * @return array{0: float, 1: string}|null
     */
    public function cell(string|int|float|null $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($this->type, [self::MONEY, self::NUMBER, self::PERCENT], true)) {
            if (! is_numeric($value)) {
                return null;
            }

            // Angka biasa tidak diberi format: Excel menampilkannya dengan pemisah menurut
            // bahasa komputer pembacanya, dan format sel pilihan pembuat layout tetap berlaku.
            return match ($this->type) {
                self::MONEY => [(float) $value, $this->moneyFormatCode()],
                self::NUMBER => [(float) $value, NumberFormat::FORMAT_GENERAL],
                default => [(float) $value / 100, '0.00%'],
            };
        }

        if ($this->type === self::DATETIME) {
            $moment = $this->moment($value);

            // Excel tidak mengenal zona. Selnya berisi jam menurut zona pengguna, dan nama zonanya
            // ikut di format sel sebagai teks, supaya kolomnya tetap dapat diurutkan.
            return $moment === null ? null : [
                (float) ExcelDate::dateTimeToExcel($moment),
                'dd/mm/yyyy hh:mm "'.UserClock::zoneLabel($moment).'"',
            ];
        }

        $date = $this->date($value);
        if ($date === null) {
            return null;
        }

        // `[$-421]` adalah kode bahasa Indonesia, supaya nama bulan di Excel tetap "Agustus"
        // pada komputer berbahasa Inggris.
        return $this->type === self::MONTH
            ? [(float) ExcelDate::dateTimeToExcel($date->startOfMonth()), '[$-421]mmmm yyyy']
            : [(float) ExcelDate::dateTimeToExcel($date), 'dd/mm/yyyy'];
    }

    private function money(string|int|float $value): string
    {
        $prefix = $this->symbol === '' ? '' : $this->symbol.' ';

        return $this->grouped(MoneyPrecision::round($value, $this->decimals), $prefix);
    }

    /** Angka dan persen sampai dua desimal, tanpa nol di belakang koma: 4, 2,5, 1.234,75. */
    private function number(string|int|float $value): string
    {
        return $this->grouped(rtrim(rtrim(MoneyPrecision::round($value, 2), '0'), '.'));
    }

    /** Desimal bertitik (`-1234567.50`) menjadi tulisan Indonesia (`-Rp 1.234.567,50`). */
    private function grouped(string $decimal, string $prefix = ''): string
    {
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-')), 2, '');
        $whole = ltrim(strrev(chunk_split(strrev($whole), 3, '.')), '.');

        return ($negative ? '-' : '').$prefix.$whole.($fraction === '' ? '' : ','.$fraction);
    }

    /** Tanggal `Y-m-d` (jam di belakangnya diabaikan) atau bulan `Y-m`. */
    private function date(string|int|float $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::DATE_PATTERN, $value, $match) !== 1) {
            return null;
        }
        $day = isset($match[3]) ? (int) $match[3] : 1;
        if (! checkdate((int) $match[2], $day, (int) $match[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $match[1], (int) $match[2], $day);
    }

    private function dateTimeText(string|int|float $value): string
    {
        $moment = $this->moment($value);

        return $moment === null ? (string) $value : $moment->format('d/m/Y H:i').' '.UserClock::zoneLabel($moment);
    }

    /**
     * Waktu UTC (`2026-09-27 17:30:00`, atau ISO 8601 seperti `2026-09-27T17:30:00Z`) sebagai jam
     * menurut zona pengguna. Nilai tanpa offset dibaca sebagai UTC, zona tempat Core dan module
     * menyimpan waktu.
     */
    private function moment(string|int|float $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::DATETIME_PATTERN, $value) !== 1) {
            return null;
        }

        // Bentuknya sudah cocok, tetapi isinya bisa mustahil (bulan 13, jam 25). `date_parse` melaporkannya
        // tanpa melempar; nilai seperti itu ditulis apa adanya, sama seperti teks yang bukan waktu.
        $parsed = date_parse($value);
        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->setTimezone($this->timezone);
    }

    private function moneyFormatCode(): string
    {
        $number = '#,##0'.($this->decimals > 0 ? '.'.str_repeat('0', $this->decimals) : '');
        $prefix = $this->symbol === '' ? '' : '"'.$this->symbol.' "';

        return $prefix.$number.';-'.$prefix.$number;
    }
}
