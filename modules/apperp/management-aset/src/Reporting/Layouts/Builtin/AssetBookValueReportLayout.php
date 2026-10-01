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
 * Mutasi nilai buku aset (Excel), satu baris per buku aset: harga perolehan, akumulasi penyusutan, dan nilai
 * buku dari saldo awal sampai saldo akhir, lalu baris total. Kolom uang dibiarkan General; Core menulis angka
 * asli beserta format bawaan tipenya.
 */
final class AssetBookValueReportLayout extends BuiltinLayoutBuilder
{
    /** Judul kolom, placeholder barisnya, dan lebar kolomnya. Kolom uang punya total di bawah tabel. */
    private const COLUMNS = [
        ['No.', 'nomor', 6, false],
        ['Kode aset', 'kode', 16, false],
        ['Nama aset', 'nama', 28, false],
        ['Group aset', 'group', 18, false],
        ['Buku penyusutan', 'buku', 18, false],
        ['Tanggal perolehan', 'tanggal_perolehan', 14, false],
        ['Harga perolehan awal', 'harga_perolehan_awal', 18, true],
        ['Perolehan', 'perolehan', 18, true],
        ['Reklasifikasi harga perolehan', 'reklasifikasi_harga_perolehan', 18, true],
        ['Pelepasan harga perolehan', 'pelepasan_harga_perolehan', 18, true],
        ['Harga perolehan akhir', 'harga_perolehan_akhir', 18, true],
        ['Akumulasi penyusutan awal', 'akumulasi_awal', 18, true],
        ['Penyusutan', 'penyusutan', 18, true],
        ['Reklasifikasi akumulasi', 'reklasifikasi_akumulasi', 18, true],
        ['Pelepasan akumulasi', 'pelepasan_akumulasi', 18, true],
        ['Akumulasi penyusutan akhir', 'akumulasi_akhir', 18, true],
        ['Nilai buku awal', 'nilai_buku_awal', 18, true],
        ['Penurunan nilai', 'penurunan_nilai', 18, true],
        ['Kenaikan nilai', 'kenaikan_nilai', 18, true],
        ['Reklasifikasi masuk', 'reklasifikasi_masuk', 18, true],
        ['Reklasifikasi keluar', 'reklasifikasi_keluar', 18, true],
        ['Pelepasan', 'pelepasan', 18, true],
        ['Nilai buku akhir', 'nilai_buku_akhir', 18, true],
    ];

    public function reportCode(): string
    {
        return 'laporan-nilai-buku-aset';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mutasi nilai buku');
        $columns = count(self::COLUMNS);
        $last = Coordinate::stringFromColumnIndex($columns);
        $this->sheetLetterhead($sheet, $columns);

        $sheet->setCellValue('A5', 'Laporan mutasi nilai buku aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        foreach ([
            'A6' => 'Dari', 'B6' => '${filter_dari}', 'C6' => 'Sampai', 'D6' => '${filter_sampai}', 'E6' => 'Dicetak', 'F6' => '${dicetak_pada}',
            'A7' => 'Group aset', 'B7' => '${filter_group}', 'C7' => 'Kelompok harta fiskal', 'D7' => '${filter_golongan}', 'E7' => 'Jenis aset', 'F7' => '${filter_jenis}',
            'A8' => 'Aset', 'B8' => '${filter_aset}', 'C8' => 'Buku', 'D8' => '${filter_buku}', 'E8' => 'Jumlah buku aset', 'F8' => '${jumlah_aset}',
            'A9' => 'Lokasi', 'B9' => '${filter_lokasi}', 'C9' => 'Kondisi', 'D9' => '${filter_kondisi}', 'E9' => 'Filter tambahan', 'F9' => '${filter_tambahan}',
        ] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        foreach (['B6:B9', 'D6:D9', 'F6:F9'] as $range) {
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        foreach (self::COLUMNS as $index => [$heading, $macro, $width, $money]) {
            $sheet->setCellValue([$index + 1, 10], $heading);
            $sheet->setCellValue([$index + 1, 11], '${baris.'.$macro.'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
            if ($money) {
                $sheet->setCellValue([$index + 1, 12], '${total_'.$macro.'}');
            }
        }
        $this->sheetTableHeader($sheet, 10, $columns);
        $sheet->getStyle("A10:{$last}10")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(10)->setRowHeight(45);

        // Baris total di bawah baris template ikut bergeser saat baris data disisipkan.
        $sheet->setCellValue('A12', 'Total');
        $sheet->mergeCells('A12:F12');
        $total = $sheet->getStyle("A12:{$last}12");
        $total->getFont()->setBold(true);
        $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $total->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
