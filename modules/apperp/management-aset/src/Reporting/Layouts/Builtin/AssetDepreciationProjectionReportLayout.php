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
 * Proyeksi penyusutan aset (Excel), satu baris per aset per periode, lalu total proyeksi penyusutan. Tabel di
 * Excel dapat diringkas per periode dengan PivotTable tanpa mengubah layout ini.
 */
final class AssetDepreciationProjectionReportLayout extends BuiltinLayoutBuilder
{
    private const COLUMNS = [
        ['No.', 'nomor', 6],
        ['Kode aset', 'kode', 16],
        ['Nama aset', 'nama', 28],
        ['Group aset', 'group', 18],
        ['Buku penyusutan', 'buku', 18],
        ['Periode', 'periode', 14],
        ['Akhir periode', 'akhir_periode', 14],
        ['Penyusutan', 'penyusutan', 18],
        ['Akumulasi penyusutan', 'akumulasi_penyusutan', 20],
        ['Nilai buku', 'nilai_buku', 20],
    ];

    public function reportCode(): string
    {
        return 'laporan-proyeksi-penyusutan-aset';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Proyeksi penyusutan');
        $this->sheetLetterhead($sheet, count(self::COLUMNS));

        $sheet->setCellValue('A5', 'Proyeksi penyusutan aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        foreach ([
            'A6' => 'Dari bulan', 'B6' => '${filter_dari}', 'C6' => 'Sampai bulan', 'D6' => '${filter_sampai}', 'E6' => 'Dicetak', 'F6' => '${dicetak_pada}',
            'A7' => 'Group aset', 'B7' => '${filter_group}', 'C7' => 'Kelompok harta fiskal', 'D7' => '${filter_golongan}', 'E7' => 'Jenis aset', 'F7' => '${filter_jenis}',
            'A8' => 'Aset', 'B8' => '${filter_aset}', 'C8' => 'Buku', 'D8' => '${filter_buku}', 'E8' => 'Jumlah buku aset', 'F8' => '${jumlah_aset}',
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
        $sheet->getStyle('A10:J10')->getAlignment()->setWrapText(true);

        $sheet->setCellValue('A12', 'Total proyeksi penyusutan');
        $sheet->mergeCells('A12:G12');
        $sheet->setCellValue('H12', '${total_penyusutan}');
        $total = $sheet->getStyle('A12:J12');
        $total->getFont()->setBold(true);
        $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $total->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
