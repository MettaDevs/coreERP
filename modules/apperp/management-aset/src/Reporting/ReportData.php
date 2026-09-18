<?php

namespace Modules\Apperp\ManagementAset\Reporting;

/**
 * Hasil dataset satu laporan, dalam bentuk yang dimengerti semua renderer.
 *
 * `fields` adalah nilai tunggal yang muncul sekali pada dokumen (`${kode}`); `tables`
 * adalah kumpulan baris yang diulang (`${baris.aset_kode}`). Nilai sudah berupa teks
 * atau angka siap tampil — pemformatan tanggal dan angka adalah urusan dataset, bukan
 * layout, supaya semua layout satu laporan menampilkan tanggal dengan cara yang sama.
 */
final class ReportData
{
    /**
     * @param  array<string, string|int|float|null>  $fields
     * @param  array<string, list<array<string, string|int|float|null>>>  $tables
     * @param  string  $fileName  Nama berkas tanpa ekstensi, misalnya `WO-000012`.
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $tables,
        public readonly string $fileName,
    ) {}

    /** Jumlah baris seluruh tabel; dipakai untuk batas ekspor dan progres. */
    public function rowCount(): int
    {
        return array_sum(array_map('count', $this->tables));
    }
}
