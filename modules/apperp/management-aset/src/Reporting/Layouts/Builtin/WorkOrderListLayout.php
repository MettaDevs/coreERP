<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Layouts\Builtin;

use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/** Daftar work order, satu baris per work order, untuk diolah di Excel. */
final class WorkOrderListLayout extends BuiltinLayoutBuilder
{
    public function reportCode(): string
    {
        return 'daftar-work-order';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $headings = [
            'Nomor', 'Status', 'Tipe work order', 'Tingkat layanan', 'Keterangan', 'Jumlah baris',
            'Estimasi jam', 'Aktual jam', 'Diharapkan mulai', 'Dijadwalkan mulai', 'Dijadwalkan selesai',
            'Aktual mulai', 'Aktual selesai', 'Dibuat pada',
        ];
        $macros = [
            'kode', 'status', 'tipe_work_order', 'tingkat_layanan', 'keterangan', 'jumlah_baris',
            'estimasi_jam', 'aktual_jam', 'diharapkan_mulai', 'dijadwalkan_mulai', 'dijadwalkan_selesai',
            'aktual_mulai', 'aktual_selesai', 'dibuat_pada',
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Work order');
        $this->sheetLetterhead($sheet, count($headings));

        $sheet->setCellValue('A5', 'Daftar work order');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A6', 'Filter status');
        $sheet->setCellValue('B6', '${filter_status}');
        $sheet->setCellValue('C6', 'Dari');
        $sheet->setCellValue('D6', '${filter_dari}');
        $sheet->setCellValue('E6', 'Sampai');
        $sheet->setCellValue('F6', '${filter_sampai}');
        $sheet->setCellValue('A7', 'Jumlah');
        $sheet->setCellValue('B7', '${jumlah_work_order}');
        $sheet->setCellValue('C7', 'Dicetak');
        $sheet->setCellValue('D7', '${dicetak_pada}');

        foreach ($headings as $index => $heading) {
            $sheet->setCellValue([$index + 1, 9], $heading);
            $sheet->setCellValue([$index + 1, 10], '${baris.'.$macros[$index].'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(in_array($macros[$index], ['keterangan'], true) ? 40 : 18);
        }
        $this->sheetTableHeader($sheet, 9, count($headings));
        $sheet->getStyle('F10:H10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
