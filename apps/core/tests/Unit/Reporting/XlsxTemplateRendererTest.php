<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Support\Reporting\Rendering\XlsxTemplateRenderer;
use App\Support\Reporting\ReportData;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * Rumus di baris template layout Excel ikut digandakan ke setiap baris hasil, seperti pengguna menarik
 * fill handle di Excel: referensi relatif bergeser, referensi absolut tetap, dan total di bawah tabel
 * mencakup semua baris.
 */
class XlsxTemplateRendererTest extends TestCase
{
    public function test_row_formulas_are_copied_to_every_generated_row(): void
    {
        $sheet = $this->render([
            ['nama' => 'Kursi', 'qty' => 2, 'harga' => 100],
            ['nama' => 'Meja', 'qty' => 3, 'harga' => 200],
            ['nama' => 'Lemari', 'qty' => 1, 'harga' => 500],
        ]);

        // Referensi relatif di dalam baris ikut bergeser.
        $this->assertSame('=D5*E5', $sheet->getCell('F5')->getValue());
        $this->assertSame('=D6*E6', $sheet->getCell('F6')->getValue());
        $this->assertSame('=D7*E7', $sheet->getCell('F7')->getValue());
        $this->assertEquals(600, $sheet->getCell('F6')->getCalculatedValue());
        // Absolut tidak bergeser, termasuk bagian absolut dari referensi campuran.
        $this->assertSame('=F7*$H$2', $sheet->getCell('G7')->getValue());
        $this->assertSame('=$D7*D$5', $sheet->getCell('K7')->getValue());
        // Referensi relatif ke luar baris template bergeser seperti salinan Excel.
        $this->assertSame('=F6*H3', $sheet->getCell('H6')->getValue());
        // Rentang di dalam baris tidak diperlebar menjadi seluruh tabel.
        $this->assertSame('=SUM(D5:E5)', $sheet->getCell('I5')->getValue());
        $this->assertSame('=SUM(D7:E7)', $sheet->getCell('I7')->getValue());
        $this->assertSame('=SUM($F$5:F5)', $sheet->getCell('J5')->getValue());
        $this->assertSame('=SUM($F$5:F7)', $sheet->getCell('J7')->getValue());
        // Total di bawah tabel tetap mencakup semua baris.
        $this->assertSame('=SUM(F5:F7)', $sheet->getCell('F8')->getValue());
        $this->assertEquals(1300, $sheet->getCell('F8')->getCalculatedValue());
    }

    public function test_single_row_keeps_the_template_formulas(): void
    {
        $sheet = $this->render([['nama' => 'Kursi', 'qty' => 2, 'harga' => 100]]);

        $this->assertSame('=D5*E5', $sheet->getCell('F5')->getValue());
        $this->assertSame('=SUM(D5:E5)', $sheet->getCell('I5')->getValue());
        $this->assertSame('=SUM(F5:F5)', $sheet->getCell('F6')->getValue());
        $this->assertEquals(200, $sheet->getCell('F6')->getCalculatedValue());
    }

    public function test_empty_dataset_leaves_one_blank_row_and_the_total(): void
    {
        $sheet = $this->render([]);

        $this->assertSame('', (string) $sheet->getCell('A5')->getValue());
        $this->assertSame('=D5*E5', $sheet->getCell('F5')->getValue());
        $this->assertSame('=SUM(F5:F5)', $sheet->getCell('F6')->getValue());
        $this->assertEquals(0, $sheet->getCell('F6')->getCalculatedValue());
    }

    /**
     * @param  list<array<string, string|int|float|null>>  $rows
     */
    private function render(array $rows): Worksheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', '${judul}');
        $sheet->setCellValue('H2', 0.1);
        $sheet->setCellValue('H3', 0.2);
        $sheet->fromArray(['Barang', null, null, 'Qty', 'Harga', 'Jumlah'], null, 'A4');
        $sheet->setCellValue('A5', '${baris.nama}');
        $sheet->setCellValue('D5', '${baris.qty}');
        $sheet->setCellValue('E5', '${baris.harga}');
        $sheet->setCellValue('F5', '=D5*E5');
        $sheet->setCellValue('G5', '=F5*$H$2');
        $sheet->setCellValue('H5', '=F5*H2');
        $sheet->setCellValue('I5', '=SUM(D5:E5)');
        $sheet->setCellValue('J5', '=SUM($F$5:F5)');
        $sheet->setCellValue('K5', '=$D5*D$5');
        $sheet->setCellValue('F6', '=SUM(F5:F5)');
        $base = (string) tempnam(sys_get_temp_dir(), 'tpl');
        $template = $base.'.xlsx';
        (new XlsxWriter($spreadsheet))->save($template);

        try {
            $file = (new XlsxTemplateRenderer)->render($template, new ReportData(['judul' => 'Pesanan'], ['baris' => $rows], 'uji'));
        } finally {
            @unlink($base);
            @unlink($template);
        }
        $result = IOFactory::load($file->localPath)->getActiveSheet();
        $file->cleanup();

        return $result;
    }
}
