<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Layouts;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Pembangun layout bawaan satu laporan.
 *
 * Satu laporan, satu berkas di folder `Builtin/`, supaya dua orang yang mengerjakan dua
 * laporan berbeda tidak menyunting berkas yang sama. Sebelumnya semua layout ditulis di satu
 * perintah artisan, dan setiap PR laporan menambah method serta satu baris pemanggilan di
 * kelas itu — enam PR laporan aset pertama saling konflik di sana setiap kali salah satunya
 * masuk.
 *
 * Yang memang milik bersama tinggal di kelas ini: kop, kepala tabel, dan cara menyimpan.
 * Dengan begitu PR laporan baru cukup menambah berkasnya sendiri dan tidak perlu menyunting
 * kelas ini; menambah potongan bersama di sini justru mengembalikan titik bentroknya.
 */
abstract class BuiltinLayoutBuilder
{
    /** Kode laporan pemilik layout ini, sama dengan `ReportDefinition::code()`. */
    abstract public function reportCode(): string;

    /**
     * Menulis satu layout bawaan laporan ini ke `$path`.
     *
     * Laporan yang punya lebih dari satu layout bawaan membedakannya lewat `$layout->key`.
     */
    abstract public function build(BuiltinLayout $layout, string $path): void;

    /**
     * Blok kop yang sama untuk semua layout Word bawaan: logo kiri, identitas di tengah,
     * logo kanan. Isinya dari identitas cetak Core (`${kop.*}`); satu logo swasta, dua logo
     * instansi, atau tanpa logo sama-sama rapi karena sel kosong tetap tiga kolom.
     */
    protected function wordLetterhead(Section $section): void
    {
        $letterhead = $section->addTable(['cellMargin' => 40, 'alignment' => Jc::CENTER]);
        $letterhead->addRow();
        $letterhead->addCell(1800, ['valign' => 'center'])->addText('${kop.logo_kiri}', null, ['alignment' => Jc::CENTER]);
        $middle = $letterhead->addCell(6400, ['valign' => 'center']);
        $middle->addText('${kop.induk}', ['size' => 10], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $middle->addText('${kop.nama}', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $middle->addText('${kop.alamat_baris}', ['size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $middle->addText('Telp. ${kop.telepon}  ${kop.email}  ${kop.laman}', ['size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $letterhead->addCell(1800, ['valign' => 'center'])->addText('${kop.logo_kanan}', null, ['alignment' => Jc::CENTER]);
        $section->addText('', null, ['borderBottomSize' => 12, 'borderBottomColor' => '000000', 'spaceAfter' => 120]);
    }

    /**
     * Kop lembar Excel di baris 1–3: logo kiri di kolom A, identitas di tengah, logo kanan
     * di kolom terakhir tabel. Semua dari identitas cetak Core; sel yang datanya kosong
     * dikosongkan saat render.
     *
     * @param  int  $columns  Jumlah kolom tabel di bawahnya, supaya kop selebar tabel.
     */
    protected function sheetLetterhead(Worksheet $sheet, int $columns): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        $middleEnd = Coordinate::stringFromColumnIndex(max(2, $columns - 1));

        $sheet->setCellValue('A1', '${kop.logo_kiri}');
        $sheet->mergeCells('A1:A3');
        $sheet->setCellValue('B1', '${kop.induk}');
        $sheet->mergeCells("B1:{$middleEnd}1");
        $sheet->setCellValue('B2', '${kop.nama}');
        $sheet->mergeCells("B2:{$middleEnd}2");
        $sheet->setCellValue('B3', '${kop.alamat_baris}  ${kop.telepon}  ${kop.email}');
        $sheet->mergeCells("B3:{$middleEnd}3");
        $sheet->setCellValue("{$last}1", '${kop.logo_kanan}');
        $sheet->mergeCells("{$last}1:{$last}3");
        $sheet->getStyle("B1:{$middleEnd}3")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(13);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(24);
        $sheet->getRowDimension(3)->setRowHeight(22);
        $sheet->getStyle("A3:{$last}3")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
    }

    /**
     * Gaya baris judul kolom tabel Excel. Baris di bawahnya dibekukan dan judulnya diberi
     * penyaring, supaya daftar panjang tetap terbaca saat digulir dan dapat disaring di Excel.
     */
    protected function sheetTableHeader(Worksheet $sheet, int $row, int $columns): void
    {
        $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex($columns).$row;
        $header = $sheet->getStyle($range);
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7E6E6');
        $header->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A'.($row + 1));
        $sheet->setAutoFilter($range);
    }

    protected function saveWord(PhpWord $word, string $path): void
    {
        $this->ensureDirectory($path);
        IOFactory::createWriter($word, 'Word2007')->save($path);
    }

    protected function saveSpreadsheet(Spreadsheet $spreadsheet, string $path): void
    {
        $this->ensureDirectory($path);
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
