<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Layouts\Builtin;

use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Rekonsiliasi aset ke buku besar (Excel), satu baris per group aset dan akun posting group. Tidak ada baris
 * total: harga perolehan dan akumulasi tidak dapat dijumlahkan menjadi satu angka. Catatan di bawah judul
 * menyebut batas laporan ini.
 */
final class AssetLedgerReconciliationReportLayout extends BuiltinLayoutBuilder
{
    private const COLUMNS = [
        ['No.', 'nomor', 6],
        ['Kode group', 'kode_group', 14],
        ['Group aset', 'group', 20],
        ['Akun posting group', 'akun', 24],
        ['Nomor akun', 'kode_akun', 14],
        ['Nama akun', 'nama_akun', 28],
        ['Saldo normal', 'sisi', 10],
        ['Saldo register aset', 'saldo_register', 18],
        ['Sudah dibukukan aplikasi finance', 'sudah_dibukukan', 18],
        ['Dicatat manual', 'dicatat_manual', 18],
        ['Menunggu aplikasi finance', 'menunggu', 18],
        ['Tertahan', 'tertahan', 18],
        ['Ditolak aplikasi finance', 'ditolak', 18],
        ['Belum dikirim', 'belum_diterbitkan', 18],
        ['Belum ada di buku besar', 'selisih', 18],
    ];

    public function reportCode(): string
    {
        return 'laporan-rekonsiliasi-aset-buku-besar';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekonsiliasi aset');
        $this->sheetLetterhead($sheet, count(self::COLUMNS));

        $sheet->setCellValue('A5', 'Rekonsiliasi aset ke buku besar');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        foreach ([
            'A6' => 'Per tanggal', 'B6' => '${per_tanggal}', 'C6' => 'Dicetak', 'D6' => '${dicetak_pada}', 'E6' => 'Jumlah baris', 'F6' => '${jumlah_baris}',
            'A7' => 'Group aset', 'B7' => '${filter_group}', 'C7' => 'Jenis aset', 'D7' => '${filter_jenis}', 'E7' => 'Aset', 'F7' => '${filter_aset}',
            'A8' => 'Lokasi', 'B8' => '${filter_lokasi}', 'C8' => 'Kondisi', 'D8' => '${filter_kondisi}', 'E8' => 'Filter tambahan', 'F8' => '${filter_tambahan}',
        ] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        foreach (['B6:B8', 'D6:D8', 'F6:F8'] as $range) {
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }
        $sheet->setCellValue('A9', 'Saldo register dibandingkan dengan jurnal yang sudah dikirim modul aset ke aplikasi finance. Saldo buku besar sendiri dicocokkan di aplikasi finance dengan kolom "Sudah dibukukan" dan "Dicatat manual".');
        $sheet->mergeCells('A9:O9');
        $sheet->getStyle('A9')->getFont()->setItalic(true);

        foreach (self::COLUMNS as $index => [$heading, $macro, $width]) {
            $sheet->setCellValue([$index + 1, 10], $heading);
            $sheet->setCellValue([$index + 1, 11], '${baris.'.$macro.'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
        $this->sheetTableHeader($sheet, 10, count(self::COLUMNS));
        $sheet->getStyle('A10:O10')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(10)->setRowHeight(45);

        $this->saveSpreadsheet($spreadsheet, $path);
    }
}
