<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory as WordFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Laporan lintas app di Core: katalog dari manifest, layout milik tenant, ekspor yang
 * dikerjakan job, dan dataset yang diminta ke app atas nama pengguna. App dan engine
 * PDF dipalsukan; yang diuji adalah kontrak di antara keduanya.
 */
class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    /** @var list<array<string, mixed>> */
    private array $datasetRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'sync',
            'reporting.disk' => 'reporting-test',
            'reporting.renderer_url' => 'http://renderer.test',
            'coreerp.app_context_signing_key' => str_repeat('k', 40),
        ]);
        Storage::fake('reporting-test');
        $this->seed(AppCatalogSeeder::class);
        $this->registerReport();
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant laporan',
            'app_ids' => ['management-aset'], 'email' => 'owner@laporan.test', 'password' => 'password',
        ]);
        $this->membership = $this->owner->activeMembership();
        $this->bootstrapRuntime();
        $this->fakeApp();
    }

    public function test_catalog_lists_manifest_reports_with_run_permission(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'management-aset.work-order')
            ->assertJsonPath('data.0.app_name', 'Management Aset')
            ->assertJsonPath('data.0.can_run', true)
            ->assertJsonPath('data.0.builtin_layouts.0.ref', 'bawaan:standar');

        // Anggota tanpa role tidak dapat membuka app-nya sama sekali, jadi laporannya pun
        // tidak ditawarkan; permintaan langsung tetap ditolak.
        $member = $this->memberWithoutRoles();
        $this->actingAs($member)->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($member)->postJson('/api/v1/reports/management-aset.work-order/exports', ['format' => 'pdf'])
            ->assertForbidden();
    }

    public function test_export_asks_the_app_for_the_dataset_as_the_user_then_renders_to_pdf(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/management-aset.work-order/exports', [
                'format' => 'pdf',
                'parameters' => ['id' => '01J0000000000000000000WO01', 'ignored' => 'x'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.layout_ref', 'bawaan:standar')
            ->json('data');

        // Queue sync: job selesai di dalam request yang sama.
        $export = DB::table('report_exports')->where('id', $response['id'])->first();
        $this->assertSame('done', $export->status, $export->failure_message ?? '');
        $this->assertSame('PMHA-000001.pdf', $export->file_name);
        Storage::disk('reporting-test')->assertExists($export->file_path);

        // Dataset diminta dengan token konteks pengguna, bukan token service, dan hanya
        // parameter yang dideklarasikan manifest yang diteruskan.
        $this->assertCount(1, $this->datasetRequests);
        $this->assertSame(['id' => '01J0000000000000000000WO01'], $this->datasetRequests[0]['parameter']);
        $this->assertSame($this->membership->tenant_id, $this->datasetRequests[0]['token']['tenant_id']);
        $this->assertContains('management-aset.entitas-aset.read', $this->datasetRequests[0]['token']['permissions']);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'renderer.test/forms/libreoffice/convert'));

        $this->actingAs($this->owner)->get('/api/v1/report-exports/'.$response['id'].'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // Pengguna lain pada tenant yang sama tidak melihat ekspor ini.
        $this->actingAs($this->memberWithoutRoles())->getJson('/api/v1/report-exports/'.$response['id'])->assertNotFound();
    }

    public function test_word_export_fills_fields_and_repeats_table_rows(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/management-aset.work-order/exports', ['format' => 'docx', 'parameters' => ['id' => 'wo']])
            ->assertStatus(202)->json('data');
        $export = DB::table('report_exports')->where('id', $response['id'])->first();
        $this->assertSame('done', $export->status, $export->failure_message ?? '');

        $xml = $this->documentXml(Storage::disk('reporting-test')->get($export->file_path));
        $this->assertStringContainsString('PMHA-000001', $xml);
        $this->assertStringContainsString('Forklift', $xml);
        $this->assertStringContainsString('Genset', $xml);
        $this->assertStringNotContainsString('${', $xml);
    }

    public function test_uploaded_excel_layout_becomes_the_legal_entity_default_and_renders(): void
    {
        $upload = UploadedFile::fake()->createWithContent('daftar.xlsx', $this->spreadsheetTemplate());
        $layout = $this->actingAs($this->owner)
            ->post('/api/v1/reports/management-aset.work-order/layouts', ['file' => $upload, 'name' => 'Ringkas', 'scope' => 'tenant'])
            ->assertCreated()
            ->assertJsonPath('data.format', 'xlsx')
            ->assertJsonPath('meta.unknown_placeholders', ['baris.kolom_salah'])
            ->json('data');

        $this->actingAs($this->owner)
            ->putJson('/api/v1/reports/management-aset.work-order/layout-default', ['layout_ref' => $layout['id'], 'scope' => 'tenant'])
            ->assertOk()
            ->assertJsonPath('meta.default_ref', $layout['id']);

        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/management-aset.work-order/exports', ['format' => 'xlsx', 'parameters' => ['id' => 'wo']])
            ->assertStatus(202)
            ->assertJsonPath('data.layout_ref', $layout['id'])
            ->json('data');
        $export = DB::table('report_exports')->where('id', $response['id'])->first();
        $this->assertSame('done', $export->status, $export->failure_message ?? '');

        $sheet = SpreadsheetFactory::load(Storage::disk('reporting-test')->path($export->file_path))->getActiveSheet();
        $this->assertSame('Nomor: PMHA-000001', $sheet->getCell('A1')->getValue());
        $this->assertSame('Forklift', $sheet->getCell('A3')->getValue());
        $this->assertSame('Genset', $sheet->getCell('A4')->getValue());
        $this->assertSame(1.5, $sheet->getCell('B3')->getValue());
        $this->assertSame('=SUM(B3:B4)', $sheet->getCell('B5')->getValue());

        // Anggota biasa boleh mencetak tetapi tidak boleh mengelola layout.
        $this->actingAs($this->memberWithoutRoles())
            ->post('/api/v1/reports/management-aset.work-order/layouts', ['file' => $upload, 'name' => 'X', 'scope' => 'tenant'])
            ->assertForbidden();
    }

    public function test_macro_layouts_are_rejected_and_app_refusals_become_failed_exports(): void
    {
        $macro = UploadedFile::fake()->createWithContent('jahat.docx', $this->docxWithMacro());
        $this->actingAs($this->owner)
            ->post('/api/v1/reports/management-aset.work-order/layouts', ['file' => $macro, 'name' => 'Jahat', 'scope' => 'tenant'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/management-aset.work-order/exports', ['format' => 'docx', 'parameters' => ['id' => 'di-luar-scope']])
            ->assertStatus(202)->json('data');
        $export = DB::table('report_exports')->where('id', $response['id'])->first();
        $this->assertSame('failed', $export->status);
        $this->assertStringContainsString('di luar unit kerja', $export->failure_message);
    }

    public function test_expired_exports_are_purged_when_the_list_is_read(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/management-aset.work-order/exports', ['format' => 'docx', 'parameters' => ['id' => 'wo']])
            ->json('data');
        $path = DB::table('report_exports')->where('id', $response['id'])->value('file_path');
        Storage::disk('reporting-test')->assertExists($path);

        DB::table('report_exports')->where('id', $response['id'])->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($this->owner)->getJson('/api/v1/report-exports')->assertOk()->assertJsonCount(0, 'data');
        Storage::disk('reporting-test')->assertMissing($path);
    }

    public function test_print_identity_feeds_kop_placeholders_and_logos_into_documents(): void
    {
        $legalEntity = $this->legalEntity('CV Surya Jaya');

        // Alamat dan kontak hidup di buku alamat organisasi, bukan di identitas cetak;
        // identitas hanya membacanya, dan permintaan yang mencoba menaruhnya di sini diabaikan.
        $this->actingAs($this->owner)
            ->postJson("/api/v1/organizations/{$legalEntity}/locations", [
                'name' => 'Kantor pusat', 'purpose' => 'business', 'country_region_code' => 'ID',
                'street' => 'Jl. I Gusti Ngurah Rai', 'district' => 'Mengwitani', 'city' => 'Badung', 'province' => 'Bali', 'postal_code' => '80351',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.formatted', "Jl. I Gusti Ngurah Rai\nMengwitani\nBadung, Bali 80351");
        $this->actingAs($this->owner)
            ->postJson("/api/v1/organizations/{$legalEntity}/contacts", ['type' => 'phone', 'value' => '(0361) 829769'])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true);
        $this->actingAs($this->owner)
            ->postJson("/api/v1/organizations/{$legalEntity}/contacts", ['type' => 'email', 'value' => 'puskesmasmengwisatu@gmail.com'])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->putJson("/api/v1/organizations/{$legalEntity}/print-identity", [
                'parent_lines' => ['PEMERINTAH KABUPATEN BADUNG', 'DINAS KESEHATAN'],
                'address_lines' => ['Alamat yang seharusnya diabaikan'],
                'phone' => '000',
                'footer_text' => 'Dokumen ini sah tanpa tanda tangan basah.',
            ])
            ->assertOk()
            ->assertJsonPath('data.parent_lines.1', 'DINAS KESEHATAN')
            ->assertJsonPath('data.display_name', 'CV Surya Jaya')
            ->assertJsonPath('data.address_lines.0', 'Jl. I Gusti Ngurah Rai')
            ->assertJsonPath('data.phone', '(0361) 829769')
            ->assertJsonPath('data.email', 'puskesmasmengwisatu@gmail.com');

        $logo = UploadedFile::fake()->image('lambang.png', 120, 120);
        $this->actingAs($this->owner)
            ->post("/api/v1/organizations/{$legalEntity}/print-identity/logos", ['file' => $logo, 'position' => 'kiri'])
            ->assertCreated()
            ->assertJsonPath('data.logos.0.position', 'kiri');
        $this->actingAs($this->owner)
            ->post("/api/v1/organizations/{$legalEntity}/print-identity/logos", ['file' => UploadedFile::fake()->image('instansi.png', 80, 80), 'position' => 'kanan'])
            ->assertCreated()
            ->assertJsonCount(2, 'data.logos');

        // Anggota biasa boleh melihat, tidak boleh mengubah; SVG ditolak.
        $this->actingAs($this->memberWithoutRoles())->getJson("/api/v1/organizations/{$legalEntity}/print-identity")->assertOk();
        $this->actingAs($this->memberWithoutRoles())->putJson("/api/v1/organizations/{$legalEntity}/print-identity", ['phone' => 'x'])->assertForbidden();
        $this->actingAs($this->owner)
            ->post("/api/v1/organizations/{$legalEntity}/print-identity/logos", ['file' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'), 'position' => 'kiri'])
            ->assertStatus(422);

        // Layout Word dengan kop: teks dan dua logo terisi, placeholder kop tanpa data kosong.
        // Ditaruh langsung pada salinan layout bawaan yang di-cache Core per versi app;
        // fake HTTP di setUp sudah memegang pola URL yang sama dan menang lebih dulu.
        $version = DB::table('apps')->where('id', 'management-aset')->value('version');
        $releaseKey = substr(sha1('local/api@sha256:'.str_repeat('a', 64)), 0, 12);
        Storage::disk('reporting-test')->put("reporting/builtin/management-aset/{$version}-{$releaseKey}/management-aset.work-order-standar.docx", $this->docxTemplateWithKop());
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/management-aset.work-order/exports', ['format' => 'docx', 'parameters' => ['id' => 'wo']])
            ->assertStatus(202)->json('data');
        $export = DB::table('report_exports')->where('id', $response['id'])->first();
        $this->assertSame('done', $export->status, $export->failure_message ?? '');

        $docx = Storage::disk('reporting-test')->get($export->file_path);
        $xml = $this->documentXml($docx);
        $this->assertStringContainsString('DINAS KESEHATAN', $xml);
        $this->assertStringContainsString('CV Surya Jaya', $xml);
        $this->assertStringContainsString('(0361) 829769', $xml);
        $this->assertStringNotContainsString('${', $xml);
        $this->assertSame(2, $this->mediaCount($docx), 'Dua logo harus tertanam sebagai gambar.');

        // Placeholder kop ikut tercatat sebagai dikenal saat unggah layout.
        $this->actingAs($this->owner)->getJson('/api/v1/reports/management-aset.work-order/fields')
            ->assertOk()
            ->assertJsonFragment(['key' => 'kop.logo_kanan']);
    }

    private function registerReport(): void
    {
        DB::table('app_reports')->insert([
            'id' => (string) Str::ulid(),
            'app_id' => 'management-aset',
            'code' => 'management-aset.work-order',
            'name' => 'Work order',
            'description' => 'Satu work order.',
            'permission' => 'management-aset.entitas-aset.read',
            'parameters' => json_encode(['id']),
            'builtin_layouts' => json_encode([['key' => 'standar', 'name' => 'Work order standar (Word)', 'description' => null, 'format' => 'docx']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bootstrapRuntime(): void
    {
        $manifest = tempnam(sys_get_temp_dir(), 'coreerp-manifest-');
        File::put($manifest, "id: management-aset\nversion: 0.1.0\n");
        $this->artisan('app:bootstrap-local-runtime', [
            'manifest' => $manifest,
            '--api-image' => 'local/api@sha256:'.str_repeat('a', 64),
            '--ui-image' => 'local/ui@sha256:'.str_repeat('b', 64),
            '--api-service' => 'management-aset-api',
            '--ui-service' => 'management-aset-ui',
            '--database-service' => 'management-aset-db',
        ])->assertSuccessful();
    }

    private function fakeApp(): void
    {
        Http::fake([
            'management-aset-api/api/internal/v1/laporan/work-order' => Http::response(['data' => [
                'kode' => 'work-order', 'nama' => 'Work order',
                'fields' => [
                    ['key' => 'kode', 'label' => 'Nomor', 'table' => null],
                    ['key' => 'baris.asset_nama', 'label' => 'Aset', 'table' => 'baris'],
                    ['key' => 'baris.estimasi_jam', 'label' => 'Jam', 'table' => 'baris'],
                ],
                'parameters' => ['id'],
                'builtin_layouts' => [['key' => 'standar', 'name' => 'Work order standar (Word)', 'description' => null, 'format' => 'docx']],
            ]]),
            'management-aset-api/api/internal/v1/laporan/work-order/layouts/standar' => Http::response($this->docxTemplate(), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]),
            'management-aset-api/api/internal/v1/laporan/work-order/dataset' => function (ClientRequest $request) {
                $token = json_decode(base64_decode(strtr(explode('.', (string) $request->header('Authorization')[0])[1] ?? '', '-_', '+/')), true);
                $body = json_decode($request->body(), true);
                $parameter = is_array($body['parameter'] ?? null) ? $body['parameter'] : [];
                $this->datasetRequests[] = ['parameter' => $parameter, 'token' => $token];
                if (($parameter['id'] ?? null) === 'di-luar-scope') {
                    return Http::response(['message' => 'Work order tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.'], 422);
                }

                return Http::response(['data' => [
                    'fields' => ['kode' => 'PMHA-000001'],
                    'tables' => ['baris' => [
                        ['asset_nama' => 'Forklift', 'estimasi_jam' => 1.5],
                        ['asset_nama' => 'Genset', 'estimasi_jam' => 2],
                    ]],
                    'file_name' => 'PMHA-000001',
                ]]);
            },
            'renderer.test/*' => Http::response('%PDF-1.4 palsu', 200, ['Content-Type' => 'application/pdf']),
        ]);
    }

    private function memberWithoutRoles(): User
    {
        $user = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $user->id, 'system_role' => 'member', 'status' => 'active',
        ]);

        return $user;
    }

    private function docxTemplate(): string
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addText('Work order ${kode}');
        $table = $section->addTable();
        $table->addRow();
        $table->addCell(4000)->addText('${baris.asset_nama}');
        $table->addCell(2000)->addText('${baris.estimasi_jam}');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        WordFactory::createWriter($word, 'Word2007')->save($path);

        return (string) file_get_contents($path);
    }

    private function spreadsheetTemplate(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Nomor: ${kode}');
        $sheet->setCellValue('A2', 'Aset');
        $sheet->setCellValue('B2', 'Jam');
        $sheet->setCellValue('A3', '${baris.asset_nama}');
        $sheet->setCellValue('B3', '${baris.estimasi_jam}');
        $sheet->setCellValue('C3', '${baris.kolom_salah}');
        $sheet->setCellValue('B4', '=SUM(B3:B3)');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return (string) file_get_contents($path);
    }

    private function docxWithMacro(): string
    {
        $word = new PhpWord;
        $word->addSection()->addText('${kode}');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        WordFactory::createWriter($word, 'Word2007')->save($path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('word/vbaProject.bin', 'x');
        $zip->close();

        return (string) file_get_contents($path);
    }

    /** Legal entity milik tenant test; registrasi bisnis belum membuatnya. */
    private function legalEntity(string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $this->membership->tenant_id, 'name' => $name,
            'classification' => 'legal_entity', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'organization_id' => $id, 'company_code' => 'CVSJ', 'country_code' => 'ID',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function docxTemplateWithKop(): string
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $kop = $section->addTable();
        $kop->addRow();
        $kop->addCell(2000)->addText('${kop.logo_kiri}');
        $cell = $kop->addCell(5000);
        $cell->addText('${kop.induk}');
        $cell->addText('${kop.nama}');
        $cell->addText('${kop.alamat_baris} ${kop.telepon} ${kop.laman}');
        $kop->addCell(2000)->addText('${kop.logo_kanan}');
        $section->addText('Work order ${kode}');
        $section->addFooter()->addText('${kop.footer}');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        WordFactory::createWriter($word, 'Word2007')->save($path);

        return (string) file_get_contents($path);
    }

    private function mediaCount(string $docx): int
    {
        $path = tempnam(sys_get_temp_dir(), 'out').'.docx';
        file_put_contents($path, $docx);
        $zip = new \ZipArchive;
        $zip->open($path);
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'word/media/')) {
                $count++;
            }
        }
        $zip->close();

        return $count;
    }

    private function documentXml(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'out').'.docx';
        file_put_contents($path, $docx);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }
}
