<?php

namespace Modules\Apperp\ManagementAset\Reporting\Layouts\Builtin;

use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

final class PurchaseRequestDocumentLayout extends BuiltinLayoutBuilder
{
    public function reportCode(): string
    {
        return 'purchase-request';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);
        $section = $word->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 800, 'marginRight' => 800]);
        $this->wordLetterhead($section);
        $section->addText('PERMINTAAN PEMBELIAN ASET', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
        foreach (['No. permintaan: ${code}', 'Tanggal: ${requested_on}', 'Unit peminta: ${unit_name}', 'Peminta: ${requester_name}', 'Status: ${status}', 'Keterangan: ${description}'] as $text) {
            $section->addText($text);
        }
        $section->addTextBreak();
        $table = $section->addTable(['borderSize' => 4, 'borderColor' => '999999', 'cellMargin' => 70]);
        $table->addRow(null, ['tblHeader' => true]);
        foreach (['No.', 'No. perencanaan', 'Barang', 'Satuan', 'Jumlah', 'Spesifikasi'] as $index => $heading) {
            $table->addCell([500, 1700, 2200, 800, 800, 2700][$index])->addText($heading, ['bold' => true]);
        }
        $table->addRow();
        foreach (['number', 'planning_code', 'name', 'unit', 'quantity', 'specification'] as $index => $key) {
            $table->addCell([500, 1700, 2200, 800, 800, 2700][$index])->addText('${lines.'.$key.'}');
        }
        $section->addTextBreak(2);
        $signatures = $section->addTable();
        $signatures->addRow();
        foreach (['Dibuat oleh', 'Diperiksa oleh', 'Disetujui oleh'] as $label) {
            $cell = $signatures->addCell(3200);
            $cell->addText($label, null, ['alignment' => Jc::CENTER]);
            $cell->addTextBreak(3);
            $cell->addText('(................................)', null, ['alignment' => Jc::CENTER]);
        }
        $this->saveWord($word, $path);
    }
}
