<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Support\Modules\Contracts\DaftarLaporan;
use App\Support\Modules\Contracts\PenyediaLaporanModul;
use App\Support\Modules\Contracts\ReportFormatter;
use App\Support\Reporting\Rendering\DocxTemplateRenderer;
use App\Support\Reporting\Rendering\RenderException;
use App\Support\Reporting\Rendering\XlsxTemplateRenderer;
use App\Support\Reporting\ReportData;
use App\Support\Reporting\SumberLaporan;
use App\Support\Reporting\ValueFormats;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\Word2007;
use Tests\TestCase;
use ZipArchive;

/**
 * Nilai laporan bertipe diformat Core, bukan module: Word dan layar pratinjau mendapat teks
 * yang sama, Excel mendapat angka dan tanggal asli, dan presisi uang mengikuti setelan
 * mata uang tenant.
 */
class ReportValueFormatsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{key: string, label: string, table: ?string, type?: string}> */
    private const FIELDS = [
        ['key' => 'total', 'label' => 'Total', 'table' => null, 'type' => 'money'],
        ['key' => 'periode', 'label' => 'Periode', 'table' => null, 'type' => 'month'],
        ['key' => 'catatan', 'label' => 'Catatan', 'table' => null],
        ['key' => 'baris.nama', 'label' => 'Nama', 'table' => 'baris'],
        ['key' => 'baris.nilai', 'label' => 'Nilai', 'table' => 'baris', 'type' => 'money'],
        ['key' => 'baris.tanggal', 'label' => 'Tanggal', 'table' => 'baris', 'type' => 'date'],
        ['key' => 'baris.tarif', 'label' => 'Tarif', 'table' => 'baris', 'type' => 'percent'],
    ];

    private TenantMembership $membership;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant format laporan',
            'app_ids' => [], 'email' => 'owner@format-laporan.test', 'password' => 'password',
        ]);
        $this->membership = $owner->activeMembership();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_money_precision_follows_the_tenant_currency_setting(): void
    {
        $formats = fn (): array => app(ValueFormats::class)->forFields($this->tenantId(), self::FIELDS);

        // Bawaan IDR dua desimal, sampai tenant memutuskan lain di setelan mata uangnya.
        $this->assertSame('Rp 1.234.567,50', $formats()['total']->text('1234567.50'));

        DB::table('currency_precisions')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId(), 'currency_code' => 'IDR',
            'amount_decimals' => 0, 'unit_amount_decimals' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('Rp 1.234.568', $formats()['total']->text('1234567.50'));
    }

    public function test_unknown_type_is_rejected_with_a_readable_message(): void
    {
        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Tipe kolom `currency` pada `total` tidak dikenal mesin laporan.');

        app(ValueFormats::class)->forFields($this->tenantId(), [['key' => 'total', 'label' => 'Total', 'table' => null, 'type' => 'currency']]);
    }

    public function test_report_source_reads_types_from_the_report_definition(): void
    {
        app(DaftarLaporan::class)->daftarkan(new class(self::FIELDS) implements PenyediaLaporanModul
        {
            /** @param list<array{key: string, label: string, table: ?string, type?: string}> $fields */
            public function __construct(private readonly array $fields) {}

            public function idModule(): string
            {
                return 'modul-uji-format';
            }

            public function punya(string $kodeLaporan): bool
            {
                return $kodeLaporan === 'rekap';
            }

            public function definisi(string $kodeLaporan, array $konteks): array
            {
                return ['fields' => $this->fields, 'parameters' => []];
            }

            public function layoutBawaan(string $kodeLaporan, string $kunci, array $konteks): string
            {
                return '';
            }

            public function dataset(string $kodeLaporan, array $konteks, array $parameter): array
            {
                return ['fields' => ['total' => '1500.50', 'catatan' => 'apa adanya'], 'tables' => ['baris' => []], 'file_name' => 'rekap'];
            }
        });
        $report = (object) ['app_id' => 'modul-uji-format', 'code' => 'modul-uji-format.rekap', 'app_name' => 'Modul uji'];

        $data = app(SumberLaporan::class)->dataset($report, $this->membership, null, null, []);

        $this->assertSame(['total', 'periode', 'baris.nilai', 'baris.tanggal', 'baris.tarif'], array_keys($data->formats));
        // Dataset tetap mentah; yang memformat renderer, menurut keluarannya.
        $this->assertSame('1500.50', $data->fields['total']);
        $this->assertSame('Rp 1.500,50', $data->formats['total']->text($data->fields['total']));
    }

    public function test_excel_gets_real_numbers_and_dates_with_cell_formats(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', '${total}');
        $sheet->setCellValue('B1', '${periode}');
        $sheet->setCellValue('A3', '${baris.nilai}');
        $sheet->setCellValue('B3', '${baris.tanggal}');
        $sheet->setCellValue('C3', 'Nilai ${baris.nilai}');
        $sheet->setCellValue('D3', '${baris.nilai}');
        // Format yang dipilih pembuat layout tidak ditimpa.
        $sheet->getStyle('D3')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('E3', '${baris.tarif}');
        $template = $this->temporaryPath('xlsx');
        (new XlsxWriter($spreadsheet))->save($template);

        $rendered = app(XlsxTemplateRenderer::class)->render($template, $this->data());
        $this->temporaryFiles[] = $rendered->localPath;
        $result = SpreadsheetFactory::load($rendered->localPath)->getActiveSheet();

        $this->assertSame(DataType::TYPE_NUMERIC, $result->getCell('A1')->getDataType());
        $this->assertEquals(3000, $result->getCell('A1')->getValue());
        $this->assertSame('"Rp "#,##0.00;-"Rp "#,##0.00', $result->getStyle('A1')->getNumberFormat()->getFormatCode());
        $this->assertEquals(ExcelDate::dateTimeToExcel(new DateTimeImmutable('2026-08-01')), $result->getCell('B1')->getValue());
        $this->assertSame('[$-421]mmmm yyyy', $result->getStyle('B1')->getNumberFormat()->getFormatCode());

        // Baris template digandakan per baris dataset, dan setiap salinannya tetap angka.
        $this->assertEquals(1000, $result->getCell('A3')->getValue());
        $this->assertEquals(2000, $result->getCell('A4')->getValue());
        $this->assertSame('"Rp "#,##0.00;-"Rp "#,##0.00', $result->getStyle('A4')->getNumberFormat()->getFormatCode());
        $this->assertEquals(ExcelDate::dateTimeToExcel(new DateTimeImmutable('2026-07-24')), $result->getCell('B4')->getValue());
        $this->assertSame('dd/mm/yyyy', $result->getStyle('B4')->getNumberFormat()->getFormatCode());
        $this->assertSame('Nilai Rp 1.000,00', $result->getCell('C3')->getValue());
        $this->assertSame('#,##0', $result->getStyle('D3')->getNumberFormat()->getFormatCode());
        $this->assertEquals(0.25, $result->getCell('E3')->getValue());
    }

    public function test_word_and_module_preview_get_the_same_display_text(): void
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addText('Total ${total} periode ${periode} ${catatan}');
        $table = $section->addTable();
        $table->addRow();
        foreach (['${baris.nama}', '${baris.nilai}', '${baris.tanggal}', '${baris.tarif}'] as $macro) {
            $table->addCell(2000)->addText($macro);
        }
        $template = $this->temporaryPath('docx');
        (new Word2007($word))->save($template);

        $rendered = app(DocxTemplateRenderer::class)->render($template, $this->data());
        $this->temporaryFiles[] = $rendered->localPath;
        $text = $this->documentText($rendered->localPath);

        $this->assertStringContainsString('Total Rp 3.000,00 periode Agustus 2026 apa adanya', $text);
        $this->assertStringContainsString('KursiRp 1.000,0023/07/202625%', $text);
        $this->assertStringContainsString('MejaRp 2.000,0024/07/202612,5%', $text);

        // Layar pratinjau module memformat lewat kontraknya sendiri, dengan aturan yang sama.
        $preview = app(ReportFormatter::class)->display($this->tenantId(), self::FIELDS, [
            'fields' => ['total' => '3000.00', 'periode' => '2026-08', 'catatan' => 'apa adanya'],
            'tables' => ['baris' => [['nama' => 'Kursi', 'nilai' => '1000.00', 'tanggal' => '2026-07-23', 'tarif' => 25]]],
            'file_name' => 'rekap',
        ]);
        $this->assertSame(['total' => 'Rp 3.000,00', 'periode' => 'Agustus 2026', 'catatan' => 'apa adanya'], $preview['fields']);
        $this->assertSame([['nama' => 'Kursi', 'nilai' => 'Rp 1.000,00', 'tanggal' => '23/07/2026', 'tarif' => '25%']], $preview['tables']['baris']);
    }

    private function data(): ReportData
    {
        return new ReportData(
            ['total' => '3000.00', 'periode' => '2026-08', 'catatan' => 'apa adanya'],
            ['baris' => [
                ['nama' => 'Kursi', 'nilai' => '1000.00', 'tanggal' => '2026-07-23', 'tarif' => 25],
                ['nama' => 'Meja', 'nilai' => 2000, 'tanggal' => '2026-07-24', 'tarif' => 12.5],
            ]],
            'rekap',
            [],
            app(ValueFormats::class)->forFields($this->tenantId(), self::FIELDS),
        );
    }

    private function documentText(string $path): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return html_entity_decode(strip_tags($xml));
    }

    private function temporaryPath(string $extension): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'format-laporan-'.Str::random(8).'.'.$extension;
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function tenantId(): string
    {
        return (string) $this->membership->tenant_id;
    }
}
