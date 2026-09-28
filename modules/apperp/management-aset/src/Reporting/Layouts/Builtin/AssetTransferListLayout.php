<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Layouts\Builtin;

use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Daftar mutasi aset, satu baris per aset yang berpindah, untuk diolah di Excel. */
final class AssetTransferListLayout extends BuiltinLayoutBuilder
{
    public function reportCode(): string
    {
        return 'daftar-mutasi-aset';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $headings = [
            'Tanggal mutasi', 'No. bukti', 'Status', 'Kode aset', 'Nama aset', 'Nomor seri',
            'Lokasi asal', 'Lokasi tujuan', 'Unit asal', 'Unit tujuan', 'Diserahkan oleh',
            'PIC penerima', 'Kondisi', 'Alasan mutasi', 'Keterangan',
        ];
        $macros = [
            'tanggal', 'kode', 'status', 'aset_kode', 'aset_nama', 'serial_number',
            'asal_lokasi', 'tujuan_lokasi', 'asal_unit_kerja', 'tujuan_unit_kerja', 'diserahkan_oleh',
            'diterima_oleh', 'kondisi', 'alasan', 'keterangan',
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mutasi aset');
        $this->sheetLetterhead($sheet, count($headings));

        $sheet->setCellValue('A5', 'Daftar mutasi aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A6', 'Filter status');
        $sheet->setCellValue('B6', '${filter_status}');
        $sheet->setCellValue('C6', 'Dari');
        $sheet->setCellValue('D6', '${filter_dari}');
        $sheet->setCellValue('E6', 'Sampai');
        $sheet->setCellValue('F6', '${filter_sampai}');
        $sheet->setCellValue('A7', 'Jumlah aset berpindah');
        $sheet->setCellValue('B7', '${jumlah_baris}');
        $sheet->setCellValue('C7', 'Dicetak');
        $sheet->setCellValue('D7', '${dicetak_pada}');

        foreach ($headings as $index => $heading) {
            $sheet->setCellValue([$index + 1, 9], $heading);
            $sheet->setCellValue([$index + 1, 10], '${baris.'.$macros[$index].'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(
                in_array($macros[$index], ['aset_nama', 'alasan', 'keterangan'], true) ? 36 : 18,
            );
        }
        $this->sheetTableHeader($sheet, 9, count($headings));

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
