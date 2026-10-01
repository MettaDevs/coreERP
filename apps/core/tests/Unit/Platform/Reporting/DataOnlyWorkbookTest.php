<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Reporting;

use App\Platform\Reporting\Support\Rendering\DataOnlyWorkbook;
use App\Platform\Reporting\Support\Rendering\TypedSheetWriter;
use App\Platform\Reporting\Support\ReportData;
use App\Platform\Reporting\Support\ValueFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

/**
 * "Excel (data saja)" (K-26) tanpa database: dataset menjadi lembar data bertipe, dan teks tidak pernah menjadi
 * rumus. Presisi uang dan zona waktu datang dari tenant dan pengguna; di sini keduanya argumen.
 */
class DataOnlyWorkbookTest extends TestCase
{
    public function test_typed_placeholders_become_typed_cells_and_text_stays_text(): void
    {
        $data = new ReportData(
            fields: ['judul' => 'Daftar', 'total' => '1500000.50', 'kop.nama' => 'PT Contoh'],
            tables: ['baris' => [
                ['kode' => 'AST-1', 'nilai' => '1000000.50', 'tanggal' => '2026-07-23', 'bulan' => '2026-08', 'tarif' => 12.5, 'catatan' => '=HYPERLINK("http://x")'],
                ['kode' => 'AST-2', 'nilai' => 500000, 'tanggal' => null, 'bulan' => 'bukan bulan', 'tarif' => null, 'catatan' => 'biasa'],
            ]],
            fileName: 'uji',
            formats: [
                'total' => new ValueFormat(ValueFormat::MONEY, 2, 'Rp'),
                'baris.nilai' => new ValueFormat(ValueFormat::MONEY, 2, 'Rp'),
                'baris.tanggal' => new ValueFormat(ValueFormat::DATE),
                'baris.bulan' => new ValueFormat(ValueFormat::MONTH),
                'baris.tarif' => new ValueFormat(ValueFormat::PERCENT),
            ],
        );
        $definitions = [
            ['key' => 'judul', 'label' => 'Judul', 'table' => null],
            ['key' => 'total', 'label' => 'Total nilai', 'table' => null, 'type' => 'money'],
            ['key' => 'kop.nama', 'label' => 'Nama pada kop', 'table' => null],
            ['key' => 'baris.kode', 'label' => 'Kode aset', 'table' => 'baris'],
            ['key' => 'baris.nilai', 'label' => 'Nilai perolehan', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.tanggal', 'label' => 'Tanggal', 'table' => 'baris', 'type' => 'date'],
            ['key' => 'baris.bulan', 'label' => 'Bulan', 'table' => 'baris', 'type' => 'month'],
            ['key' => 'baris.tarif', 'label' => 'Tarif', 'table' => 'baris', 'type' => 'percent'],
            ['key' => 'baris.catatan', 'label' => 'Catatan', 'table' => 'baris'],
        ];

        $file = (new DataOnlyWorkbook)->render($data, $definitions);
        $book = IOFactory::load($file->localPath);
        $file->cleanup();

        $this->assertSame(['Data', 'Keterangan'], $book->getSheetNames());
        $sheet = $book->getSheetByName('Data');
        $this->assertNotNull($sheet);
        $this->assertSame(['Kode aset', 'Nilai perolehan', 'Tanggal', 'Bulan', 'Tarif', 'Catatan'], $sheet->rangeToArray('A1:F1')[0]);

        $this->assertSame(1000000.5, $sheet->getCell('B2')->getValue());
        $this->assertSame('"Rp "#,##0.00;-"Rp "#,##0.00', $sheet->getCell('B2')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('dd/mm/yyyy', $sheet->getCell('C2')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertIsNumeric($sheet->getCell('C2')->getValue());
        $this->assertSame('[$-421]mmmm yyyy', $sheet->getCell('D2')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame(0.125, $sheet->getCell('E2')->getValue());
        // Teks berawalan `=` tetap teks: data pengguna tidak boleh berjalan sebagai rumus di Excel.
        $this->assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell('F2')->getDataType());
        $this->assertSame('=HYPERLINK("http://x")', (string) $sheet->getCell('F2')->getValue());
        // Nilai bertipe yang kosong tetap kosong; yang tidak terbaca sebagai tipenya ditulis apa adanya.
        $this->assertNull($sheet->getCell('C3')->getValue());
        $this->assertSame('bukan bulan', (string) $sheet->getCell('D3')->getValue());

        $info = $book->getSheetByName('Keterangan');
        $this->assertNotNull($info);
        $this->assertSame([['Keterangan', 'Nilai'], ['Judul', 'Daftar'], ['Total nilai', 1500000.5]], array_map(
            static fn (array $row): array => [(string) $row[0], is_object($row[1]) ? (string) $row[1] : $row[1]],
            $info->rangeToArray('A1:B3', null, true, false),
        ));
        $this->assertSame(3, $info->getHighestRow(), 'Kop bukan bagian data.');
    }

    public function test_csv_keeps_numbers_raw_and_neutralises_formula_like_text(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'csv-');
        $writer = new TypedSheetWriter('csv', $path);
        $writer->header(['Kode', 'Nilai', 'Tanggal', 'Catatan']);
        $writer->row(['AST-1', '1000000.50', '2026-07-23', '=1+1'], [null, new ValueFormat(ValueFormat::MONEY, 2, 'Rp'), new ValueFormat(ValueFormat::DATE), null]);
        $writer->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        $lines = explode("\n", trim(ltrim($content, "\xEF\xBB\xBF")));
        $this->assertSame('Kode,Nilai,Tanggal,Catatan', $lines[0]);
        $this->assertSame("AST-1,1000000.5,23/07/2026,'=1+1", $lines[1]);
    }
}
