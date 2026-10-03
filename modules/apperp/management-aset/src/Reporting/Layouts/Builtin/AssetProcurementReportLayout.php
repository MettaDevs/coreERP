<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Layouts\Builtin;

use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * Laporan pengadaan aset (Excel), satu baris per barang yang direncanakan atau diminta, lalu total nilai.
 *
 * Kolom tersusun menurut alurnya: rencana, permintaan, penerimaan, lalu unit dan status. Total hanya untuk
 * nilai; menjumlah jumlah barang lintas satuan tidak bermakna.
 */
final class AssetProcurementReportLayout extends BuiltinLayoutBuilder
{
    /** Judul kolom, placeholder barisnya, dan lebar kolomnya. */
    private const COLUMNS = [
        ['No.', 'nomor', 6],
        ['No. rencana', 'no_rencana', 16],
        ['Tanggal rencana', 'tanggal_rencana', 13],
        ['Tahun anggaran', 'tahun_anggaran', 10],
        ['Sumber dana', 'sumber_dana', 16],
        ['Item aset', 'item_aset', 26],
        ['Spesifikasi', 'spesifikasi', 28],
        ['Satuan', 'satuan', 9],
        ['Jumlah rencana', 'jumlah_rencana', 10],
        ['Nilai rencana', 'nilai_rencana', 18],
        ['No. permintaan', 'no_permintaan', 18],
        ['Tanggal permintaan', 'tanggal_permintaan', 13],
        ['Jumlah diminta', 'jumlah_diminta', 10],
        ['Belum diminta', 'belum_diminta', 10],
        ['No. penerimaan', 'no_penerimaan', 18],
        ['Tanggal penerimaan terakhir', 'tanggal_penerimaan', 13],
        ['Vendor', 'vendor', 22],
        ['Jumlah diterima', 'jumlah_diterima', 10],
        ['Nilai diterima', 'nilai_diterima', 18],
        ['Belum diterima', 'belum_diterima', 10],
        ['Unit organisasi', 'unit_organisasi', 22],
        ['Status', 'status', 16],
    ];

    public function reportCode(): string
    {
        return 'laporan-pengadaan-aset';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pengadaan aset');
        $this->sheetLetterhead($sheet, count(self::COLUMNS));

        $sheet->setCellValue('A5', 'Laporan pengadaan aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        foreach ([
            'A6' => 'Dari', 'B6' => '${filter_dari}', 'C6' => 'Sampai', 'D6' => '${filter_sampai}', 'E6' => 'Dicetak', 'F6' => '${dicetak_pada}',
            'A7' => 'Jenis aset', 'B7' => '${filter_jenis}', 'C7' => 'Unit organisasi', 'D7' => '${filter_unit}', 'E7' => 'Status', 'F7' => '${filter_status}',
            'A8' => 'Jumlah baris', 'B8' => '${jumlah_baris}', 'C8' => 'Filter tambahan', 'D8' => '${filter_tambahan}',
        ] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        foreach (['B6:B8', 'D6:D8', 'F6:F7'] as $range) {
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        $last = Coordinate::stringFromColumnIndex(count(self::COLUMNS));
        foreach (self::COLUMNS as $index => [$heading, $macro, $width]) {
            $sheet->setCellValue([$index + 1, 10], $heading);
            $sheet->setCellValue([$index + 1, 11], '${baris.'.$macro.'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
        $this->sheetTableHeader($sheet, 10, count(self::COLUMNS));
        $sheet->getStyle("A10:{$last}10")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(10)->setRowHeight(32);
        // Spesifikasi dan daftar nomor dokumen bisa panjang; dibungkus supaya terbaca tanpa melebarkan kolom.
        $sheet->getStyle("A11:{$last}11")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        // Total di bawah kolom nilai rencana (J) dan nilai diterima (S).
        $sheet->setCellValue('A13', 'Total');
        $sheet->mergeCells('A13:I13');
        $sheet->setCellValue('J13', '${total_nilai_rencana}');
        $sheet->setCellValue('S13', '${total_nilai_diterima}');
        $total = $sheet->getStyle("A13:{$last}13");
        $total->getFont()->setBold(true);
        $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $total->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
