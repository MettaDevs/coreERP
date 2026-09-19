<?php

namespace Modules\Apperp\ManagementAset\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Membangun ulang layout bawaan di `resources/laporan/` dari definisi di kelas ini.
 *
 * Layout bawaan adalah berkas Office yang ikut release. Ia dibangkitkan dari kode,
 * bukan dirawat tangan di Word, supaya perubahannya terbaca di review dan hasilnya sama
 * di mesin siapa pun. Berkas hasilnya tetap di-commit: runtime membaca berkas, bukan
 * menjalankan command ini.
 *
 *     php artisan laporan:bangun-layout-bawaan
 */
class BangunLayoutLaporanBawaan extends Command
{
    protected $signature = 'laporan:bangun-layout-bawaan';

    protected $description = 'Bangun ulang berkas layout bawaan Word/Excel di resources/laporan.';

    public function handle(): int
    {
        // Jalur dihitung dari folder module, bukan dari `resource_path()`. Alasannya sama
        // seperti pada `Reporting\Layouts\BuiltinLayout`: di dalam runtime Core,
        // `resource_path()` menunjuk `resources/` milik **Core**, jadi perintah ini akan
        // menulis layout module ke folder Core — berhasil tanpa keluhan, lalu berkas yang
        // sungguh dibaca saat mencetak tetap yang lama.
        $laporan = dirname(__DIR__, 3).'/resources/laporan';

        $this->workOrderDocx($laporan.'/work-order/standar.docx');
        $this->workOrderListXlsx($laporan.'/daftar-work-order/standar.xlsx');
        $this->assetMaintenanceReportXlsx($laporan.'/laporan-pemeliharaan-aset/standar.xlsx');
        $this->info('Layout bawaan dibangun ulang.');

        return self::SUCCESS;
    }

    private function workOrderDocx(string $path): void
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);
        $word->addTableStyle('grid', new TableStyle(['borderSize' => 4, 'borderColor' => '999999', 'cellMargin' => 60]), ['bgColor' => 'E7E6E6']);

        $section = $word->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 1000, 'marginRight' => 1000]);
        $this->kop($section);
        $section->addText('WORK ORDER', ['bold' => true, 'size' => 18]);
        $section->addText('${kode}', ['bold' => true, 'size' => 13]);
        $section->addText('Status: ${status}    Dicetak: ${dicetak_pada}', ['color' => '555555', 'size' => 9]);
        $section->addTextBreak();

        $info = $section->addTable(['cellMargin' => 40]);
        foreach ([
            ['Tipe work order', '${tipe_work_order}', 'Tingkat layanan', '${tingkat_layanan}'],
            ['Penanggung jawab', '${penanggung_jawab}', 'Jumlah baris', '${jumlah_baris}'],
            ['Diharapkan mulai', '${diharapkan_mulai}', 'Diharapkan selesai', '${diharapkan_selesai}'],
            ['Dijadwalkan mulai', '${dijadwalkan_mulai}', 'Dijadwalkan selesai', '${dijadwalkan_selesai}'],
            ['Aktual mulai', '${aktual_mulai}', 'Aktual selesai', '${aktual_selesai}'],
            ['Total estimasi jam', '${total_estimasi_jam}', 'Total aktual jam', '${total_aktual_jam}'],
        ] as [$labelA, $valueA, $labelB, $valueB]) {
            $info->addRow();
            $info->addCell(2000)->addText($labelA, ['color' => '555555']);
            $info->addCell(3000)->addText($valueA);
            $info->addCell(2000)->addText($labelB, ['color' => '555555']);
            $info->addCell(3000)->addText($valueB);
        }
        $section->addTextBreak();
        $section->addText('Keterangan', ['bold' => true]);
        $section->addText('${keterangan}');
        $section->addTextBreak();

        $section->addText('Baris pekerjaan', ['bold' => true, 'size' => 12]);
        $lines = $section->addTable('grid');
        $lines->addRow(null, ['tblHeader' => true]);
        foreach (['No', 'Aset', 'Lokasi', 'Pekerjaan', 'Keahlian', 'Ditugaskan ke', 'Estimasi jam', 'Hasil'] as $index => $heading) {
            $lines->addCell([500, 2200, 1500, 1800, 1200, 1300, 900, 900][$index])->addText($heading, ['bold' => true]);
        }
        $lines->addRow();
        $lines->addCell(500)->addText('${baris.nomor}');
        $lines->addCell(2200)->addText('${baris.asset_kode} ${baris.asset_nama}');
        $lines->addCell(1500)->addText('${baris.lokasi}');
        $lines->addCell(1800)->addText('${baris.jenis_pekerjaan} ${baris.varian}');
        $lines->addCell(1200)->addText('${baris.bidang_keahlian}');
        $lines->addCell(1300)->addText('${baris.ditugaskan_ke}');
        $lines->addCell(900)->addText('${baris.estimasi_jam}', null, ['alignment' => Jc::END]);
        $lines->addCell(900)->addText('${baris.hasil}');
        $section->addTextBreak();

        $section->addText('Checklist', ['bold' => true, 'size' => 12]);
        $checklist = $section->addTable('grid');
        $checklist->addRow(null, ['tblHeader' => true]);
        foreach (['Baris', 'No', 'Pemeriksaan', 'Satuan', 'Wajib', 'Nilai', 'Catatan teknisi'] as $index => $heading) {
            $checklist->addCell([600, 600, 3200, 900, 700, 1500, 2800][$index])->addText($heading, ['bold' => true]);
        }
        $checklist->addRow();
        $checklist->addCell(600)->addText('${checklist.baris}');
        $checklist->addCell(600)->addText('${checklist.nomor}');
        $checklist->addCell(3200)->addText('${checklist.nama}');
        $checklist->addCell(900)->addText('${checklist.satuan}');
        $checklist->addCell(700)->addText('${checklist.wajib}');
        $checklist->addCell(1500)->addText('${checklist.nilai}');
        $checklist->addCell(2800)->addText('${checklist.catatan}');
        $section->addTextBreak(2);

        $sign = $section->addTable(['cellMargin' => 40]);
        $sign->addRow();
        foreach (['Disiapkan oleh', 'Dikerjakan oleh', 'Disetujui oleh'] as $role) {
            $cell = $sign->addCell(3300);
            $cell->addText($role, ['color' => '555555']);
            $cell->addTextBreak(3);
            $cell->addText('______________________');
        }
        $section->addFooter()->addText('${kop.footer}', ['size' => 8, 'color' => '555555'], ['alignment' => Jc::CENTER]);

        $this->ensureDirectory($path);
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $this->line("  ditulis: {$path}");
    }

    /**
     * Blok kop yang sama untuk semua layout Word bawaan: logo kiri, identitas di tengah,
     * logo kanan. Isinya dari identitas cetak Core (`${kop.*}`); satu logo swasta, dua logo
     * instansi, atau tanpa logo sama-sama rapi karena sel kosong tetap tiga kolom.
     */
    private function kop(Section $section): void
    {
        $kop = $section->addTable(['cellMargin' => 40, 'alignment' => Jc::CENTER]);
        $kop->addRow();
        $kop->addCell(1800, ['valign' => 'center'])->addText('${kop.logo_kiri}', null, ['alignment' => Jc::CENTER]);
        $tengah = $kop->addCell(6400, ['valign' => 'center']);
        $tengah->addText('${kop.induk}', ['size' => 10], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $tengah->addText('${kop.nama}', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $tengah->addText('${kop.alamat_baris}', ['size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $tengah->addText('Telp. ${kop.telepon}  ${kop.email}  ${kop.laman}', ['size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $kop->addCell(1800, ['valign' => 'center'])->addText('${kop.logo_kanan}', null, ['alignment' => Jc::CENTER]);
        $section->addText('', null, ['borderBottomSize' => 12, 'borderBottomColor' => '000000', 'spaceAfter' => 120]);
    }

    private function workOrderListXlsx(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Work order');

        // Kop: logo kiri, identitas di tengah (baris 1-3), logo kanan. Semua dari
        // identitas cetak Core; sel yang datanya kosong dikosongkan saat render.
        $sheet->setCellValue('A1', '${kop.logo_kiri}');
        $sheet->mergeCells('A1:A3');
        $sheet->setCellValue('B1', '${kop.induk}');
        $sheet->mergeCells('B1:M1');
        $sheet->setCellValue('B2', '${kop.nama}');
        $sheet->mergeCells('B2:M2');
        $sheet->setCellValue('B3', '${kop.alamat_baris}  ${kop.telepon}  ${kop.email}');
        $sheet->mergeCells('B3:M3');
        $sheet->setCellValue('N1', '${kop.logo_kanan}');
        $sheet->mergeCells('N1:N3');
        $sheet->getStyle('B1:M3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(13);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(24);
        $sheet->getRowDimension(3)->setRowHeight(22);
        $sheet->getStyle('A3:N3')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);

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
        foreach ($headings as $index => $heading) {
            $sheet->setCellValue([$index + 1, 9], $heading);
            $sheet->setCellValue([$index + 1, 10], '${baris.'.$macros[$index].'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(in_array($macros[$index], ['keterangan'], true) ? 40 : 18);
        }
        $header = $sheet->getStyle('A9:N9');
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7E6E6');
        $header->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F10:H10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->freezePane('A10');
        $sheet->setAutoFilter('A9:N9');

        $this->ensureDirectory($path);
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->line("  ditulis: {$path}");
    }

    private function assetMaintenanceReportXlsx(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pemeliharaan aset');

        // Kop surat Core: kolom A sampai N (14 kolom)
        $sheet->setCellValue('A1', '${kop.logo_kiri}');
        $sheet->mergeCells('A1:A3');
        $sheet->setCellValue('B1', '${kop.induk}');
        $sheet->mergeCells('B1:M1');
        $sheet->setCellValue('B2', '${kop.nama}');
        $sheet->mergeCells('B2:M2');
        $sheet->setCellValue('B3', '${kop.alamat_baris}  ${kop.telepon}  ${kop.email}');
        $sheet->mergeCells('B3:M3');
        $sheet->setCellValue('N1', '${kop.logo_kanan}');
        $sheet->mergeCells('N1:N3');
        $sheet->getStyle('B1:M3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(13);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(24);
        $sheet->getRowDimension(3)->setRowHeight(22);
        $sheet->getStyle('A3:N3')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);

        // Judul & Filter
        $sheet->setCellValue('A5', 'Laporan pemeliharaan aset');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A6', 'Group aset');
        $sheet->setCellValue('B6', '${filter_group_aset}');
        $sheet->setCellValue('D6', 'Golongan aset');
        $sheet->setCellValue('E6', '${filter_golongan_aset}');
        $sheet->setCellValue('G6', 'Jenis aset');
        $sheet->setCellValue('H6', '${filter_jenis_aset}');

        $sheet->setCellValue('A7', 'Nama aset');
        $sheet->setCellValue('B7', '${filter_nama_aset}');
        $sheet->setCellValue('D7', 'Dari');
        $sheet->setCellValue('E7', '${filter_dari}');
        $sheet->setCellValue('G7', 'Sampai');
        $sheet->setCellValue('H7', '${filter_sampai}');

        $sheet->setCellValue('A8', 'Jumlah pekerjaan');
        $sheet->setCellValue('B8', '${jumlah_pekerjaan}');
        $sheet->setCellValue('D8', 'Dicetak');
        $sheet->setCellValue('E8', '${dicetak_pada}');

        $headings = [
            'No', 'No. Bukti', 'Tgl Work Order', 'Kode Aset', 'Item Aset', 'Spesifikasi',
            'Satuan', 'Jumlah', 'Item Checklist', 'Analisa Perbaikan', 'Jenis Pemeliharaan',
            'Unit Organisasi', 'PIC', 'Status',
        ];
        $macros = [
            'nomor', 'no_bukti', 'tanggal', 'asset_kode', 'asset_nama', 'spesifikasi',
            'satuan', 'jumlah', 'checklist', 'analisa_perbaikan', 'jenis_pemeliharaan',
            'unit_organisasi', 'pic', 'status',
        ];

        $columnWidths = [
            'nomor' => 6,
            'no_bukti' => 16,
            'tanggal' => 16,
            'asset_kode' => 16,
            'asset_nama' => 22,
            'spesifikasi' => 22,
            'satuan' => 10,
            'jumlah' => 10,
            'checklist' => 26,
            'analisa_perbaikan' => 26,
            'jenis_pemeliharaan' => 20,
            'unit_organisasi' => 18,
            'pic' => 18,
            'status' => 14,
        ];

        foreach ($headings as $index => $heading) {
            $sheet->setCellValue([$index + 1, 10], $heading);
            $sheet->setCellValue([$index + 1, 11], '${baris.'.$macros[$index].'}');
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($columnWidths[$macros[$index]]);
        }

        $header = $sheet->getStyle('A10:N10');
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7E6E6');
        $header->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('G11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('H11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->freezePane('A11');
        $sheet->setAutoFilter('A10:N10');

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);

        $this->ensureDirectory($path);
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->line("  ditulis: {$path}");
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
