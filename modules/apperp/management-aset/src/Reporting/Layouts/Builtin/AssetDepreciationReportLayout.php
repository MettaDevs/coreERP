<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Layouts\Builtin;

use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Laporan penyusutan aset satu bulan, satu baris per buku aset (Excel).
 *
 * Sel uang, angka, persen, dan bulan dibiarkan berformat General: Core menulis nilainya
 * sebagai angka atau tanggal asli beserta format bawaan tipenya, jadi kolom uang dapat
 * dijumlah di Excel. Pembuat layout turunan yang memberi format sendiri tetap dihormati.
 */
final class AssetDepreciationReportLayout extends BuiltinLayoutBuilder
{
    /** Judul kolom, placeholder barisnya, dan lebar kolomnya. */
    private const COLUMNS = [
        ['No.', 'nomor', 6],
        ['Kode aset', 'kode', 16],
        ['Nama aset', 'nama', 30],
        ['Spesifikasi', 'spesifikasi', 28],
        ['Group aset', 'group', 18],
        ['Kelompok harta fiskal', 'golongan', 18],
        ['Jenis aset', 'jenis', 18],
        ['Buku penyusutan', 'buku', 18],
        ['Bulan perolehan', 'bulan_perolehan', 15],
        ['Umur ekonomis (tahun)', 'umur_ekonomis_tahun', 12],
        ['Umur ekonomis (bulan)', 'umur_ekonomis_bulan', 12],
        ['Umur berjalan (bulan)', 'umur_ekonomis_saat_ini', 12],
        ['Sisa umur (bulan)', 'sisa_umur_ekonomis_bulan', 12],
        ['Tarif per tahun', 'persentase_penyusutan', 11],
        ['Nilai perolehan', 'nilai_perolehan', 20],
        ['Penyusutan bulan ini', 'penyusutan_bulan_ini', 20],
        ['Penyusutan tahun berjalan', 'penyusutan_tahun_berjalan', 20],
        ['Akumulasi penyusutan', 'akumulasi_penyusutan', 20],
        ['Nilai buku akhir', 'nilai_buku_akhir', 20],
    ];

    /** Kolom total, sejajar dengan kolom uangnya di tabel (O sampai S). */
    private const TOTALS = [
        'O' => 'total_nilai_perolehan',
        'P' => 'total_penyusutan_bulan_ini',
        'Q' => 'total_penyusutan_tahun_berjalan',
        'R' => 'total_akumulasi_penyusutan',
        'S' => 'total_nilai_buku_akhir',
    ];

    public function reportCode(): string
    {
        return 'laporan-penyusutan-aset';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Penyusutan aset');
        $this->sheetLetterhead($sheet, count(self::COLUMNS));

        $sheet->setCellValue('A5', 'Laporan penyusutan aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        foreach ([
            'A6' => 'Periode', 'B6' => '${filter_periode}', 'C6' => 'Buku', 'D6' => '${filter_buku}', 'E6' => 'Dicetak', 'F6' => '${dicetak_pada}',
            'A7' => 'Group aset', 'B7' => '${filter_group}', 'C7' => 'Kelompok harta fiskal', 'D7' => '${filter_golongan}', 'E7' => 'Jenis aset', 'F7' => '${filter_jenis}',
            'A8' => 'Aset', 'B8' => '${filter_aset}', 'C8' => 'Jumlah buku aset', 'D8' => '${jumlah_aset}',
        ] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        $sheet->getStyle('B6:B8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('D6:D8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        foreach (self::COLUMNS as $index => [$heading, $macro, $width]) {
            $sheet->setCellValue([$index + 1, 10], $heading);
            $sheet->setCellValue([$index + 1, 11], '${baris.'.$macro.'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
        $this->sheetTableHeader($sheet, 10, count(self::COLUMNS));
        $sheet->getStyle('A10:S10')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(10)->setRowHeight(32);

        // Baris total di bawah baris template ikut bergeser saat baris data disisipkan.
        $sheet->setCellValue('A12', 'Total');
        $sheet->mergeCells('A12:N12');
        foreach (self::TOTALS as $column => $macro) {
            $sheet->setCellValue("{$column}12", '${'.$macro.'}');
        }
        $total = $sheet->getStyle('A12:S12');
        $total->getFont()->setBold(true);
        $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $total->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
