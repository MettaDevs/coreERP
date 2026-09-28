<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Layouts\Builtin;

use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Berita acara serah terima aset (Word).
 *
 * Bentuknya mengikuti berita acara yang dipakai di Indonesia, bukan Dynamics: kop,
 * kalimat pembuka, identitas kedua pihak berdampingan, tabel barang, kalimat penutup,
 * lalu dua blok tanda tangan. Urutan itu bukan selera — tanda tangan harus berada
 * sesudah kalimat penutup supaya yang ditandatangani adalah seluruh isi halaman.
 */
final class AssetHandoverLayout extends BuiltinLayoutBuilder
{
    public function reportCode(): string
    {
        return 'berita-acara-serah-terima';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);
        $word->addTableStyle('grid', new TableStyle(['borderSize' => 4, 'borderColor' => '999999', 'cellMargin' => 60]), ['bgColor' => 'E7E6E6']);

        $section = $word->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 1000, 'marginRight' => 1000]);
        $this->wordLetterhead($section);
        $section->addText('BERITA ACARA SERAH TERIMA ASET', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $section->addText('Nomor: ${kode}', ['size' => 11], ['alignment' => Jc::CENTER]);
        $section->addTextBreak();

        $section->addText('Pada hari ini, ${tanggal}, telah dilakukan serah terima aset dengan alasan ${alasan}, dengan rincian sebagai berikut:');
        $section->addTextBreak();

        // Kedua pihak berdampingan supaya terbaca sebagai satu peristiwa, bukan dua daftar.
        $parties = $section->addTable(['cellMargin' => 60]);
        $parties->addRow();
        $left = $parties->addCell(4800);
        $left->addText('YANG MENYERAHKAN', ['bold' => true, 'size' => 9]);
        $left->addText('Nama     : ${diserahkan_oleh}');
        $left->addText('Jabatan  : ');
        $left->addText('Unit     : ');
        $right = $parties->addCell(4800);
        $right->addText('YANG MENERIMA', ['bold' => true, 'size' => 9]);
        $right->addText('Nama     : ${diterima_oleh}');
        $right->addText('Jabatan  : ');
        $right->addText('Unit     : ');
        $section->addTextBreak();

        $section->addText('Lokasi tujuan: ${tujuan_lokasi}    Unit kerja tujuan: ${tujuan_unit_kerja}    Jumlah aset: ${jumlah_aset}', ['size' => 9, 'color' => '555555']);
        $section->addTextBreak();

        $items = $section->addTable('grid');
        $items->addRow(null, ['tblHeader' => true]);
        // Kolom "Unit asal" ikut dicetak, bukan hanya lokasi: yang dipersoalkan saat
        // audit serah terima adalah dari unit mana barang itu berpindah, dan lokasi fisik
        // tidak selalu menjawabnya — satu ruangan dapat dipakai dua unit.
        $widths = [450, 1400, 2200, 1300, 1600, 1500, 1050, 1700];
        foreach (['No', 'Kode aset', 'Nama aset', 'Nomor seri', 'Lokasi asal', 'Unit asal', 'Kondisi', 'Catatan'] as $index => $heading) {
            $items->addCell($widths[$index])->addText($heading, ['bold' => true]);
        }
        $items->addRow();
        foreach ([
            '${baris.nomor}', '${baris.aset_kode}', '${baris.aset_nama}', '${baris.serial_number}',
            '${baris.asal_lokasi}', '${baris.asal_unit_kerja}', '${baris.kondisi}', '${baris.catatan}',
        ] as $index => $macro) {
            $items->addCell($widths[$index])->addText($macro);
        }
        $section->addTextBreak();

        $section->addText('Keterangan', ['bold' => true]);
        $section->addText('${keterangan}');
        $section->addTextBreak();
        $section->addText('Demikian berita acara serah terima aset ini dibuat dengan sebenarnya untuk dipergunakan sebagaimana mestinya.');
        $section->addTextBreak(2);

        $signatures = $section->addTable(['cellMargin' => 40]);
        $signatures->addRow();
        foreach ([['Yang menyerahkan', '${diserahkan_oleh}'], ['Yang menerima', '${diterima_oleh}']] as [$role, $name]) {
            $cell = $signatures->addCell(4800);
            $cell->addText($role, ['color' => '555555'], ['alignment' => Jc::CENTER]);
            $cell->addTextBreak(4);
            $cell->addText('( '.$name.' )', null, ['alignment' => Jc::CENTER]);
        }
        $section->addFooter()->addText('${kop.footer}', ['size' => 8, 'color' => '555555'], ['alignment' => Jc::CENTER]);

        $this->saveWord($word, $path);
    }
}
