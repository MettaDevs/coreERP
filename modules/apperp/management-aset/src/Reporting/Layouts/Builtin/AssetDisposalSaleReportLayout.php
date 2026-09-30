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
 * Laporan penjualan aset, satu baris per aset yang dijual (Excel).
 *
 * Susunannya sama dengan laporan pemusnahan: kop, judul, filter, tabel, lalu baris total untuk
 * nilai penjualan, nilai buku, dan laba/rugi. Sel uang dan tanggal dibiarkan berformat General;
 * Core menulis angka dan tanggal asli beserta format bawaan tipenya.
 */
final class AssetDisposalSaleReportLayout extends BuiltinLayoutBuilder
{
    /** Judul kolom, placeholder barisnya, dan lebar kolomnya. */
    private const COLUMNS = [
        ['No.', 'nomor', 6],
        ['No. bukti', 'no_bukti', 16],
        ['Tanggal penjualan', 'tanggal_penjualan', 14],
        ['Kode aset', 'kode_aset', 16],
        ['Nama aset', 'nama_aset', 30],
        ['Spesifikasi', 'spesifikasi', 28],
        ['Buku penyusutan', 'buku', 18],
        ['Nilai penjualan', 'nilai_penjualan', 20],
        ['Nilai buku saat dijual', 'nilai_buku', 20],
        ['Laba / rugi', 'laba_rugi', 20],
        ['Keterangan', 'keterangan', 36],
    ];

    public function reportCode(): string
    {
        return 'laporan-penjualan-aset';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Penjualan aset');
        $this->sheetLetterhead($sheet, count(self::COLUMNS));

        $sheet->setCellValue('A5', 'Laporan penjualan aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        foreach ([
            'A6' => 'Dari', 'B6' => '${filter_dari}', 'C6' => 'Sampai', 'D6' => '${filter_sampai}', 'E6' => 'Dicetak', 'F6' => '${dicetak_pada}',
            'A7' => 'Group aset', 'B7' => '${filter_group}', 'C7' => 'Kelompok harta fiskal', 'D7' => '${filter_golongan}', 'E7' => 'Jenis aset', 'F7' => '${filter_jenis}',
            'A8' => 'Aset', 'B8' => '${filter_aset}', 'C8' => 'Buku', 'D8' => '${filter_buku}', 'E8' => 'Jumlah penjualan', 'F8' => '${jumlah_penjualan}',
            'A9' => 'Lokasi', 'B9' => '${filter_lokasi}', 'C9' => 'Kondisi', 'D9' => '${filter_kondisi}', 'E9' => 'Filter tambahan', 'F9' => '${filter_tambahan}',
        ] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        foreach (['B6:B9', 'D6:D9', 'F6:F9'] as $range) {
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        foreach (self::COLUMNS as $index => [$heading, $macro, $width]) {
            $sheet->setCellValue([$index + 1, 10], $heading);
            $sheet->setCellValue([$index + 1, 11], '${baris.'.$macro.'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
        $this->sheetTableHeader($sheet, 10, count(self::COLUMNS));
        $sheet->getStyle('A10:K10')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(10)->setRowHeight(32);

        // Baris total di bawah baris template ikut bergeser saat baris data disisipkan.
        $sheet->setCellValue('A12', 'Total');
        $sheet->mergeCells('A12:G12');
        $sheet->setCellValue('H12', '${total_nilai_penjualan}');
        $sheet->setCellValue('I12', '${total_nilai_buku}');
        $sheet->setCellValue('J12', '${total_laba_rugi}');
        $total = $sheet->getStyle('A12:K12');
        $total->getFont()->setBold(true);
        $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $total->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
