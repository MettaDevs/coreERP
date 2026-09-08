<?php

namespace App\Support\Reporting;

use InvalidArgumentException;

/**
 * Dataset satu laporan seperti yang dikirim app: `fields` untuk nilai tunggal
 * (`${kode}`) dan `tables` untuk baris yang diulang (`${baris.asset_kode}`). Nilai
 * sudah siap tampil; pemformatan tanggal dan angka adalah urusan app pemilik data,
 * supaya semua layout satu laporan menampilkannya dengan cara yang sama.
 *
 * `images` adalah placeholder gambar — hari ini hanya logo kop dari identitas cetak —
 * yang ditambahkan Core, bukan app. Nilainya path berkas lokal dan lebar dalam mm.
 */
final class ReportData
{
    /**
     * @param  array<string, string|int|float|null>  $fields
     * @param  array<string, list<array<string, string|int|float|null>>>  $tables
     * @param  array<string, array{path: string, width_mm: int}>  $images
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $tables,
        public readonly string $fileName,
        public readonly array $images = [],
    ) {}

    /** @param array<string, mixed> $payload Isi `data` dari jawaban endpoint dataset app. */
    public static function fromArray(array $payload): self
    {
        $fields = $payload['fields'] ?? null;
        $tables = $payload['tables'] ?? null;
        $fileName = $payload['file_name'] ?? null;
        if (! is_array($fields) || ! is_array($tables) || ! is_string($fileName) || $fileName === '') {
            throw new InvalidArgumentException('Dataset dari app tidak memuat fields, tables, dan file_name.');
        }

        $scalar = fn (mixed $value): string|int|float|null => is_string($value) || is_int($value) || is_float($value) ? $value : ($value === null ? null : (string) json_encode($value));
        $cleanFields = [];
        foreach ($fields as $key => $value) {
            $cleanFields[(string) $key] = $scalar($value);
        }
        $cleanTables = [];
        foreach ($tables as $name => $rows) {
            if (! is_array($rows)) {
                throw new InvalidArgumentException("Tabel `{$name}` pada dataset bukan daftar baris.");
            }
            $cleanTables[(string) $name] = array_values(array_map(function (mixed $row) use ($scalar, $name): array {
                if (! is_array($row)) {
                    throw new InvalidArgumentException("Baris tabel `{$name}` bukan objek.");
                }
                $clean = [];
                foreach ($row as $column => $value) {
                    $clean[(string) $column] = $scalar($value);
                }

                return $clean;
            }, $rows));
        }

        return new self($cleanFields, $cleanTables, $fileName);
    }

    /**
     * Dataset yang sama dengan placeholder kop dari Core. Nilai app menang bila ada
     * kunci yang sama, karena app yang paling tahu isi dokumennya.
     *
     * @param  array<string, string|int|float|null>  $fields
     * @param  array<string, array{path: string, width_mm: int}>  $images
     */
    public function withIdentity(array $fields, array $images): self
    {
        return new self([...$fields, ...$this->fields], $this->tables, $this->fileName, [...$images, ...$this->images]);
    }

    public function rowCount(): int
    {
        return array_sum(array_map('count', $this->tables));
    }
}
