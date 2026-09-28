<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Layouts\Builtin;

use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayoutBuilder;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/** Satu work order lengkap dengan baris pekerjaan dan checklist-nya (Word). */
final class WorkOrderDocumentLayout extends BuiltinLayoutBuilder
{
    public function reportCode(): string
    {
        return 'work-order';
    }

    public function build(BuiltinLayout $layout, string $path): void
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);
        $word->addTableStyle('grid', new TableStyle(['borderSize' => 4, 'borderColor' => '999999', 'cellMargin' => 60]), ['bgColor' => 'E7E6E6']);

        $section = $word->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 1000, 'marginRight' => 1000]);
        $this->wordLetterhead($section);
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
        $lines->addCell(2200)->addText('${baris.aset_kode} ${baris.aset_nama}');
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

        $signatures = $section->addTable(['cellMargin' => 40]);
        $signatures->addRow();
        foreach (['Disiapkan oleh', 'Dikerjakan oleh', 'Disetujui oleh'] as $role) {
            $cell = $signatures->addCell(3300);
            $cell->addText($role, ['color' => '555555']);
            $cell->addTextBreak(3);
            $cell->addText('______________________');
        }
        $section->addFooter()->addText('${kop.footer}', ['size' => 8, 'color' => '555555'], ['alignment' => Jc::CENTER]);

        $this->saveWord($word, $path);
    }
}
