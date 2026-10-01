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
 * Daftar perolehan aset (Excel), satu baris per aset yang diperoleh, lalu total nilai perolehan.
 */
final class AssetAcquisitionListReportLayout extends BuiltinLayoutBuilder
{
    private const COLUMNS = [
        ['No.', 'nomor', 6],
        ['Kode aset', 'kode', 16],
        ['Nama aset', 'nama', 28],
        ['Group aset', 'group', 18],
        ['Jenis aset', 'jenis', 18],
        ['Lokasi', 'lokasi', 20],
        ['Tanggal perolehan', 'tanggal_perolehan', 14],
        ['Tanggal mulai dipakai', 'tanggal_mulai_dipakai', 14],
        ['Cara perolehan', 'cara_perolehan', 18],
        ['Dokumen asal', 'dokumen_asal', 18],
        ['Status aset', 'status', 14],
        ['Nilai perolehan', 'nilai_perolehan', 20],
    ];

    public function reportCode(): string
    {
        return 'laporan-perolehan-aset';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Perolehan aset');
        $this->sheetLetterhead($sheet, count(self::COLUMNS));

        $sheet->setCellValue('A5', 'Daftar perolehan aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        foreach ([
            'A6' => 'Dari', 'B6' => '${filter_dari}', 'C6' => 'Sampai', 'D6' => '${filter_sampai}', 'E6' => 'Dicetak', 'F6' => '${dicetak_pada}',
            'A7' => 'Group aset', 'B7' => '${filter_group}', 'C7' => 'Kelompok harta fiskal', 'D7' => '${filter_golongan}', 'E7' => 'Jenis aset', 'F7' => '${filter_jenis}',
            'A8' => 'Aset', 'B8' => '${filter_aset}', 'C8' => 'Lokasi', 'D8' => '${filter_lokasi}', 'E8' => 'Jumlah aset', 'F8' => '${jumlah_aset}',
            'A9' => 'Kondisi', 'B9' => '${filter_kondisi}', 'C9' => 'Filter tambahan', 'D9' => '${filter_tambahan}',
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
        $sheet->getStyle('A10:L10')->getAlignment()->setWrapText(true);

        $sheet->setCellValue('A12', 'Total');
        $sheet->mergeCells('A12:K12');
        $sheet->setCellValue('L12', '${total_nilai_perolehan}');
        $total = $sheet->getStyle('A12:L12');
        $total->getFont()->setBold(true);
        $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $total->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
