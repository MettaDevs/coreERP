<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Layouts\Builtin;

use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * Laporan pemeliharaan aset, satu baris per aset yang dikerjakan (Excel).
 *
 * Susunannya sama dengan laporan monitoring: kop, judul, filter, lalu tabel. Kolom QA lebih dulu, dalam
 * urutan spesifikasinya; kolom tambahan menyusul di kanan. Tidak ada baris total karena laporan ini tidak
 * memuat nilai uang; jumlah work order dan aset dikerjakan ada di kepala laporan.
 */
final class AssetMaintenanceReportLayout extends BuiltinLayoutBuilder
{
    /** Judul kolom, placeholder barisnya, dan lebar kolomnya. */
    private const COLUMNS = [
        ['No.', 'nomor', 6],
        ['No. bukti', 'no_bukti', 16],
        ['Tanggal work order', 'tanggal', 14],
        ['Kode aset', 'asset_kode', 16],
        ['Item aset', 'asset_nama', 28],
        ['Spesifikasi', 'spesifikasi', 24],
        ['Satuan', 'satuan', 8],
        ['Jumlah', 'jumlah', 8],
        ['Item checklist', 'checklist', 36],
        ['Analisa perbaikan', 'analisa_perbaikan', 36],
        ['Jenis pemeliharaan', 'jenis_pemeliharaan', 18],
        ['Jenis pekerjaan', 'jenis_pekerjaan', 20],
        ['Lokasi', 'lokasi', 20],
        ['Unit organisasi', 'unit_organisasi', 22],
        ['PIC', 'pic', 22],
        ['Tingkat layanan', 'tingkat_layanan', 16],
        ['Status', 'status', 12],
        ['Catatan', 'catatan', 30],
    ];

    public function reportCode(): string
    {
        return 'laporan-pemeliharaan-aset';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pemeliharaan aset');
        $this->sheetLetterhead($sheet, count(self::COLUMNS));

        $sheet->setCellValue('A5', 'Laporan pemeliharaan aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        foreach ([
            'A6' => 'Dari', 'B6' => '${filter_dari}', 'C6' => 'Sampai', 'D6' => '${filter_sampai}', 'E6' => 'Dicetak', 'F6' => '${dicetak_pada}',
            'A7' => 'Group aset', 'B7' => '${filter_group}', 'C7' => 'Kelompok harta fiskal', 'D7' => '${filter_golongan}', 'E7' => 'Jenis aset', 'F7' => '${filter_jenis}',
            'A8' => 'Aset', 'B8' => '${filter_aset}', 'C8' => 'Lokasi', 'D8' => '${filter_lokasi}', 'E8' => 'Status', 'F8' => '${filter_status}',
            'A9' => 'Tingkat layanan', 'B9' => '${filter_tingkat_layanan}', 'C9' => 'Teknisi', 'D9' => '${filter_teknisi}', 'E9' => 'Unit organisasi', 'F9' => '${filter_unit}',
            'A10' => 'Work order / aset dikerjakan', 'B10' => '${jumlah_work_order} / ${jumlah_pekerjaan}', 'C10' => 'Filter tambahan', 'D10' => '${filter_tambahan}',
        ] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        foreach (['B6:B10', 'D6:D10', 'F6:F9'] as $range) {
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        $last = Coordinate::stringFromColumnIndex(count(self::COLUMNS));
        foreach (self::COLUMNS as $index => [$heading, $macro, $width]) {
            $sheet->setCellValue([$index + 1, 12], $heading);
            $sheet->setCellValue([$index + 1, 13], '${baris.'.$macro.'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
        $this->sheetTableHeader($sheet, 12, count(self::COLUMNS));
        $sheet->getStyle("A12:{$last}12")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(12)->setRowHeight(32);
        // Checklist dan analisa bisa panjang; dibungkus supaya terbaca tanpa melebarkan kolom.
        $sheet->getStyle('I13:J13')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
