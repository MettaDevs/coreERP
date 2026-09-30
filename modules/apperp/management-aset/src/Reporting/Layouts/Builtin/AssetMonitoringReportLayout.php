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

/**
 * Laporan monitoring aset, satu baris per aset yang diperiksa (Excel).
 *
 * Susunannya sama dengan laporan pemusnahan: kop, judul, filter, tabel, lalu baris total. Sel uang
 * dan tanggal dibiarkan berformat General; Core menulis angka dan tanggal asli beserta format bawaan
 * tipenya, jadi kolom nilai dapat dijumlah di Excel.
 */
final class AssetMonitoringReportLayout extends BuiltinLayoutBuilder
{
    /** Judul kolom, placeholder barisnya, dan lebar kolomnya. */
    private const COLUMNS = [
        ['No.', 'nomor', 6],
        ['Tanggal monitoring', 'tgl_monitoring', 14],
        ['No. bukti', 'no_bukti', 16],
        ['Lokasi diperiksa', 'lokasi', 22],
        ['Kode aset', 'asset_kode', 16],
        ['Nama aset', 'asset_nama', 28],
        ['Spesifikasi', 'spesifikasi', 26],
        ['Status di sistem', 'kondisi_sistem', 16],
        ['Lokasi tercatat', 'lokasi_tercatat', 22],
        ['Keberadaan fisik', 'kondisi_fisik', 14],
        ['Kondisi fisik', 'kondisi_aset', 16],
        ['Status monitoring', 'status_monitoring', 16],
        ['Keterangan', 'keterangan', 30],
        ['Nilai perolehan', 'nilai_perolehan', 18],
        ['Akumulasi penyusutan', 'akumulasi_penyusutan', 18],
        ['Nilai buku akhir', 'nilai_buku_akhir', 18],
        ['Penanggung jawab', 'penanggung_jawab', 22],
        ['Unit organisasi', 'unit_organisasi', 22],
    ];

    public function reportCode(): string
    {
        return 'laporan-monitoring-aset';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Monitoring aset');
        $this->sheetLetterhead($sheet, count(self::COLUMNS));

        $sheet->setCellValue('A5', 'Laporan monitoring aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        foreach ([
            'A6' => 'Dari', 'B6' => '${filter_dari}', 'C6' => 'Sampai', 'D6' => '${filter_sampai}', 'E6' => 'Dicetak', 'F6' => '${dicetak_pada}',
            'A7' => 'Group aset', 'B7' => '${filter_group}', 'C7' => 'Kelompok harta fiskal', 'D7' => '${filter_golongan}', 'E7' => 'Jenis aset', 'F7' => '${filter_jenis}',
            'A8' => 'Aset', 'B8' => '${filter_aset}', 'C8' => 'Kondisi fisik', 'D8' => '${filter_kondisi}', 'E8' => 'Lokasi', 'F8' => '${filter_lokasi}',
            'A9' => 'Penanggung jawab', 'B9' => '${filter_penanggung_jawab}', 'C9' => 'Unit organisasi', 'D9' => '${filter_unit}', 'E9' => 'Aset diperiksa / tidak sesuai', 'F9' => '${jumlah_aset} / ${jumlah_tidak_sesuai}',
        ] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        foreach (['B6:B9', 'D6:D9', 'F6:F9'] as $range) {
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        $last = Coordinate::stringFromColumnIndex(count(self::COLUMNS));
        foreach (self::COLUMNS as $index => [$heading, $macro, $width]) {
            $sheet->setCellValue([$index + 1, 11], $heading);
            $sheet->setCellValue([$index + 1, 12], '${baris.'.$macro.'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
        $this->sheetTableHeader($sheet, 11, count(self::COLUMNS));
        $sheet->getStyle("A11:{$last}11")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(11)->setRowHeight(32);

        // Baris total di bawah baris template ikut bergeser saat baris data disisipkan.
        $sheet->setCellValue('A13', 'Total');
        $sheet->mergeCells('A13:M13');
        $sheet->setCellValue('N13', '${total_nilai_perolehan}');
        $sheet->setCellValue('P13', '${total_nilai_buku}');
        $total = $sheet->getStyle("A13:{$last}13");
        $total->getFont()->setBold(true);
        $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $total->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
