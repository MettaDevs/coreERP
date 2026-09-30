<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Jobs\RunReportExport;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Modules\Contracts\TenantDisiapkan;
use App\Support\Reporting\DaftarLaporanModul;
use App\Support\Retention\RetentionPolicies;
use App\Support\Retention\RetentionService;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 * Mesin laporan Core dari ujung ke ujung, dengan module aset yang sungguhan.
 *
 * Sebelum F3-12 test ini memalsukan seluruh module lewat `Http::fake()`: definisi laporan,
 * layout bawaan, dan dataset semuanya dikarang di berkas ini. Yang paling berbahaya bukan
 * datanya melainkan **katalognya** — baris `app_reports` ditulis tangan di sini dengan
 * permission `management-aset.entitas-aset.read`, sedangkan laporan work order yang
 * sungguhan menuntut `management-aset.pemeliharaan-aset.read`. Selama yang menjawab adalah
 * tiruan buatan test ini sendiri, ketidakcocokan itu tidak pernah bisa terlihat: tiruan
 * tidak pernah memeriksa izin apa pun.
 *
 * Sekarang tidak ada yang dipalsukan di antara Core dan module. Katalognya didaftarkan dari
 * manifest module lewat perintah pendaftaran yang sama dengan yang dipakai on-prem,
 * pengguna memegang izinnya lewat rantai role -> duty -> privilege -> permission yang
 * sungguhan, dan datasetnya dibaca dari work order yang benar-benar ada di database.
 *
 * Satu-satunya `Http::fake()` yang tersisa adalah untuk `core-renderer`, layanan render PDF
 * di luar deployment ini yang memang tidak ada di lingkungan test. Segala alamat lain
 * dicatat dan dijawab gagal, sehingga sebuah lompatan HTTP yang tersisa antar bagian
 * membuat test merah alih-alih lewat tanpa suara.
 */
class ReportingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Laporan yang diuji. Hanya kodenya yang disebut di sini; permission, parameter, dan
     * layout bawaannya dibaca dari manifest, karena itulah yang membuat keduanya tidak bisa
     * menyimpang lagi.
     */
    private const KODE_LAPORAN = 'management-aset.work-order';

    private User $owner;

    private TenantMembership $membership;

    private string $legalEntityId;

    private string $orgUnitId;

    private string $workOrderId;

    private string $kodeWorkOrder;

    /**
     * Alamat di luar layanan render yang sempat dihubungi selama sebuah test.
     *
     * Ini pengganti `$datasetRequests` yang dulu mengintip permintaan ke module. Yang dicatat
     * sekarang kebalikannya: bukan permintaan yang diharapkan, melainkan permintaan yang
     * seharusnya tidak pernah ada.
     *
     * @var list<string>
     */
    private array $permintaanHttpLain = [];

    /**
     * Jawaban layanan render berikutnya, dipakai berurutan sebelum jawaban PDF yang berhasil.
     *
     * @var list<mixed>
     */
    private array $jawabanRenderer = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'sync',
            'reporting.disk' => 'reporting-test',
            'reporting.renderer_url' => 'http://renderer.test',
        ]);
        Storage::fake('reporting-test');

        $this->seed(NumberSequenceProfileSeeder::class);
        $this->daftarkanKatalogDariManifest();
        // Data awal Indonesia yang dikirim lewat `TenantDisiapkan` — 363 master bernomor, sekitar
        // 3.500 statement per test — tidak dipakai laporan mana pun di sini: `buatWorkOrder()`
        // menyusun master yang dibutuhkannya sendiri. Data itu diuji sendiri di
        // `IndonesiaStarterProvisioningTest`.
        Event::fake([TenantDisiapkan::class]);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant laporan',
            'app_ids' => ['management-aset'], 'email' => 'owner@laporan.test', 'password' => 'password',
        ]);
        $this->membership = $this->owner->activeMembership();

        $this->legalEntityId = $this->buatLegalEntity('CV Surya Jaya');
        // Unit penanggung jawab cukup sebuah id: pemilik memegang kebijakan data tanpa batas
        // lingkup, dan tidak ada satu pun jalur di test ini yang membacanya dari
        // `organizations`. Membuat organisasi untuknya justru mengubah unit operasi aktif
        // pada workspace, dan dengan begitu mengubah konteks identitas cetak yang diuji
        // test paling bawah — perubahan yang tidak ada hubungannya dengan yang dibuktikan.
        $this->orgUnitId = (string) Str::ulid();
        $this->buatWorkOrder();

        $this->fakeHanyaLayananRender();
    }

    public function test_katalog_menyebut_laporan_manifest_beserta_hak_menjalankannya(): void
    {
        $definisi = $this->reportFromModuleCatalog();

        $data = $this->actingAs($this->owner)->getJson('/api/v1/reports')->assertOk()->json('data');
        $laporan = collect($data)->firstWhere('code', self::KODE_LAPORAN);

        $this->assertNotNull($laporan, sprintf(
            'Katalog laporan tidak memuat `%s`. Barisnya berasal dari definisi laporan module; '
            .'kalau ia hilang, yang hilang bukan sekadar satu entri daftar melainkan tombol Cetak pada '
            .'layar work order, dan tidak ada pesan kesalahan di mana pun yang menyebutkannya.',
            self::KODE_LAPORAN,
        ));
        $this->assertSame('Management Aset', $laporan['app_name']);
        $this->assertSame($definisi['permission'], $laporan['permission'], sprintf(
            'Permission laporan di katalog (%s) berbeda dari yang dinyatakan definisi laporan module (%s). Katalog '
            .'menentukan siapa yang melihat tombol Cetak, sedangkan module menegakkan permission-nya '
            .'sendiri saat dataset dibaca; ketika keduanya berbeda, penggunanya melihat tombol yang '
            .'selalu berujung ekspor gagal.',
            $laporan['permission'],
            $definisi['permission'],
        ));
        $this->assertSame($definisi['parameters'], $laporan['parameters']);
        $this->assertSame('bawaan:'.$definisi['builtin_layouts'][0]['key'], $laporan['builtin_layouts'][0]['ref']);
        $this->assertTrue($laporan['can_run'], sprintf(
            'Pemilik bisnis tidak dianggap berhak menjalankan laporannya sendiri. Ia menerima seluruh '
            .'duty app yang di-entitle, jadi `%s` mestinya sampai kepadanya lewat rantai '
            .'role -> duty -> privilege -> permission; kalau tidak, rantai itu putus di suatu tempat dan '
            .'seluruh module ikut tertutup untuknya, bukan hanya laporan ini.',
            $definisi['permission'],
        ));

        // Anggota tanpa role tidak dapat membuka app-nya sama sekali, jadi laporannya pun
        // tidak ditawarkan; permintaan langsung tetap ditolak.
        $member = $this->memberWithoutRoles();
        $this->actingAs($member)->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($member)->postJson('/api/v1/reports/'.self::KODE_LAPORAN.'/exports', ['format' => 'pdf'])
            ->assertForbidden();
    }

    /**
     * Kriteria selesai F3-12, diuji sebagai satu kalimat: satu work order dicetak menjadi PDF
     * tanpa satu pun permintaan HTTP antar bagian.
     *
     * Pembuktiannya bukan dengan mempercayai kode, melainkan dengan menutup kabelnya: setiap
     * alamat selain layanan render dicatat dan dijawab gagal. Kalau masih ada jalur yang
     * memanggil module lewat HTTP, ia muncul di daftar itu — dan kalaupun tidak, ekspornya
     * gagal karena jawabannya bukan 200.
     */
    public function test_mencetak_work_order_ke_pdf_selesai_tanpa_permintaan_http_antar_bagian(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/'.self::KODE_LAPORAN.'/exports', [
                'format' => 'pdf',
                'parameters' => ['id' => $this->workOrderId, 'ignored' => 'x'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.layout_ref', 'bawaan:standar')
            ->json('data');

        // Diperiksa lebih dulu daripada hasilnya. Sebuah lompatan HTTP yang tersisa juga
        // membuat ekspornya gagal — stub penampung menjawab 503 — dan kalau urutannya
        // terbalik, yang terbaca orang berikutnya hanya "status failed" tanpa sebabnya.
        $this->assertSame(
            [],
            $this->permintaanHttpLain,
            'Mencetak satu work order masih menembakkan permintaan HTTP ke bagian lain: '
            .implode(', ', $this->permintaanHttpLain).'. Kriteria selesai task ini adalah dokumen yang '
            .'jadi tanpa satu pun lompatan HTTP antar bagian; selama masih ada lompatan, Core tetap '
            .'menuntut alamat module yang dapat dihubunginya, dan pemasangan on-prem tetap harus '
            .'menyiapkan jaringan untuk sesuatu yang berjalan di proses yang sama.',
        );
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'renderer.test/forms/libreoffice/convert'));

        // Queue sync: job selesai di dalam request yang sama.
        $export = DB::table('report_exports')->where('id', $response['id'])->first();
        $this->assertSame('done', $export->status, $export->failure_message ?? '');
        $this->assertSame($this->kodeWorkOrder.'.pdf', $export->file_name, sprintf(
            'Nama berkas hasil tidak diambil dari nomor work order yang sungguhan (%s). Nama itu datang '
            .'dari dataset module; kalau ia meleset, yang dibaca bukan work order yang diminta.',
            $this->kodeWorkOrder,
        ));
        Storage::disk('reporting-test')->assertExists($export->file_path);

        // Hanya parameter yang dideklarasikan manifest yang tersimpan dan diteruskan.
        $this->assertSame(
            ['id' => $this->workOrderId],
            json_decode((string) $export->parameters, true),
            'Parameter yang tidak dideklarasikan manifest ikut tersimpan pada baris ekspor. Parameter '
            .'adalah bagian permintaan yang paling mudah dititipi nilai dari luar, dan yang menyaringnya '
            .'hanya daftar di manifest.',
        );

        $this->actingAs($this->owner)->get('/api/v1/report-exports/'.$response['id'].'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // Pengguna lain pada tenant yang sama tidak melihat ekspor ini.
        $this->actingAs($this->memberWithoutRoles())->getJson('/api/v1/report-exports/'.$response['id'])->assertNotFound();
    }

    /**
     * "Excel (data saja)" (K-26): dataset laporan apa adanya ke xlsx lewat antrean yang sama, tanpa layout,
     * tanpa kop, dan tanpa layanan PDF. Judul kolom dari label placeholder, nilai bertipe menjadi sel bertipe.
     */
    public function test_excel_data_only_writes_the_dataset_with_typed_cells_and_no_layout(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/management-aset.daftar-work-order/exports', ['data_only' => true, 'parameters' => ['status' => 'draft']])
            ->assertStatus(202)
            ->assertJsonPath('data.kind', 'data')
            ->assertJsonPath('data.format', 'xlsx')
            ->json('data');

        $export = DB::table('report_exports')->where('id', $response['id'])->first();
        $this->assertNotNull($export);
        $this->assertSame('done', $export->status, (string) $export->failure_message);
        $this->assertSame('', $export->layout_ref);
        $this->assertSame(0, $this->jumlahPanggilanRenderer(), 'Data saja tidak pernah melewati layanan PDF.');

        $path = tempnam(sys_get_temp_dir(), 'data-only-');
        file_put_contents($path, Storage::disk('reporting-test')->get($export->file_path));
        $book = SpreadsheetFactory::load($path);
        @unlink($path);
        $this->assertSame(['Data', 'Keterangan'], $book->getSheetNames());

        $data = $book->getSheetByName('Data');
        $this->assertNotNull($data);
        $header = $data->rangeToArray('A1:N1')[0];
        $this->assertSame(['Nomor work order', 'Status', 'Tipe work order'], array_slice($header, 0, 3));
        $this->assertSame($this->kodeWorkOrder, (string) $data->getCell('A2')->getValue());
        $expected = array_search('Dibuat pada', $header, true);
        $this->assertIsInt($expected);
        $cell = $data->getCell([$expected + 1, 2]);
        $this->assertIsNumeric($cell->getValue(), 'Waktu ditulis sebagai tanggal Excel, bukan teks.');
        $this->assertStringContainsString('dd/mm/yyyy hh:mm', $cell->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame(2, $data->getHighestRow(), 'Satu judul dan satu work order draf.');

        $info = $book->getSheetByName('Keterangan');
        $this->assertNotNull($info);
        $labels = array_column($info->rangeToArray('A1:B10'), 1, 0);
        $this->assertSame('1', (string) $labels['Jumlah work order']);
        $this->assertArrayNotHasKey('Nama pada kop', $labels, 'Kop bukan bagian data.');

        // Opsi terakhir mencatat pilihan "data saja", supaya dialog cetak membukanya lagi.
        $this->actingAs($this->owner)->getJson('/api/v1/reports/management-aset.daftar-work-order/options')
            ->assertJsonPath('data.last_used.format', 'data');

        // Hak menjalankan laporan tetap syaratnya.
        $this->actingAs($this->memberWithoutRoles())
            ->postJson('/api/v1/reports/management-aset.daftar-work-order/exports', ['data_only' => true])
            ->assertForbidden();
    }

    public function test_ekspor_word_mengisi_field_dan_menggandakan_baris_pekerjaan(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/'.self::KODE_LAPORAN.'/exports', ['format' => 'docx', 'parameters' => ['id' => $this->workOrderId]])
            ->assertStatus(202)->json('data');
        $export = DB::table('report_exports')->where('id', $response['id'])->first();
        $this->assertSame('done', $export->status, $export->failure_message ?? '');

        // Layout bawaan yang dipakai adalah berkas Word sungguhan milik module, bukan template
        // karangan test. Karena itu yang dibuktikan di sini sekaligus dua hal: dataset module
        // sampai ke renderer, dan placeholder pada layout release memang cocok dengan namanya.
        $xml = $this->documentXml(Storage::disk('reporting-test')->get($export->file_path));
        $this->assertStringContainsString($this->kodeWorkOrder, $xml);
        $this->assertStringContainsString('Forklift 1', $xml);
        $this->assertStringContainsString('Genset 1', $xml);
        $this->assertStringContainsString('Ganti ban', $xml);
        $this->assertStringNotContainsString('${', $xml, 'Dokumen yang sampai ke vendor masih memuat placeholder mentah.');
    }

    public function test_layout_excel_unggahan_jadi_bawaan_tenant_dan_ikut_dirender(): void
    {
        $upload = UploadedFile::fake()->createWithContent('daftar.xlsx', $this->spreadsheetTemplate());
        $layout = $this->actingAs($this->owner)
            ->post('/api/v1/reports/'.self::KODE_LAPORAN.'/layouts', ['file' => $upload, 'name' => 'Ringkas', 'scope' => 'tenant'])
            ->assertCreated()
            ->assertJsonPath('data.format', 'xlsx')
            ->assertJsonPath('meta.unknown_placeholders', ['baris.kolom_salah'])
            ->json('data');

        $this->actingAs($this->owner)
            ->putJson('/api/v1/reports/'.self::KODE_LAPORAN.'/layout-default', ['layout_ref' => $layout['id'], 'scope' => 'tenant', 'version' => 0])
            ->assertOk()
            ->assertJsonPath('meta.default_ref', $layout['id']);

        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/'.self::KODE_LAPORAN.'/exports', ['format' => 'xlsx', 'parameters' => ['id' => $this->workOrderId]])
            ->assertStatus(202)
            ->assertJsonPath('data.layout_ref', $layout['id'])
            ->json('data');
        $export = DB::table('report_exports')->where('id', $response['id'])->first();
        $this->assertSame('done', $export->status, $export->failure_message ?? '');

        $sheet = SpreadsheetFactory::load(Storage::disk('reporting-test')->path($export->file_path))->getActiveSheet();
        $this->assertSame('Nomor: '.$this->kodeWorkOrder, $sheet->getCell('A1')->getValue());
        $this->assertSame('Forklift 1', $sheet->getCell('A3')->getValue());
        $this->assertSame('Genset 1', $sheet->getCell('A4')->getValue());
        $this->assertSame(1.5, $sheet->getCell('B3')->getValue());
        $this->assertSame('=SUM(B3:B4)', $sheet->getCell('B5')->getValue(), 'Rumus di bawah baris template tidak ikut bergeser saat baris kedua disisipkan.');

        // Anggota biasa boleh mencetak tetapi tidak boleh mengelola layout.
        $this->actingAs($this->memberWithoutRoles())
            ->post('/api/v1/reports/'.self::KODE_LAPORAN.'/layouts', ['file' => $upload, 'name' => 'X', 'scope' => 'tenant'])
            ->assertForbidden();
    }

    public function test_layout_unggahan_dan_pilihan_default_menolak_versi_basi_dan_versi_kosong(): void
    {
        $base = '/api/v1/reports/'.self::KODE_LAPORAN;
        $upload = UploadedFile::fake()->createWithContent('daftar.xlsx', $this->spreadsheetTemplate());
        $id = $this->actingAs($this->owner)
            ->post("{$base}/layouts", ['file' => $upload, 'name' => 'Ringkas', 'scope' => 'tenant'])
            ->assertCreated()
            ->json('data.id');

        $listing = $this->getJson("{$base}/layouts")->assertOk();
        $this->assertSame(1, collect($listing->json('data'))->firstWhere('ref', $id)['version']);
        $this->assertSame(0, $listing->json('meta.default_versions.tenant'));

        $this->postJson("{$base}/layouts/{$id}", ['name' => 'Pertama', 'version' => 1])->assertOk()->assertJsonPath('data.version', 3);
        $this->postJson("{$base}/layouts/{$id}", ['name' => 'Kedua', 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_version');
        $this->postJson("{$base}/layouts/{$id}", ['name' => 'Kedua'])->assertStatus(428);
        $this->deleteJson("{$base}/layouts/{$id}", [], ['If-Match' => 'W/"1"'])->assertStatus(409);
        $this->assertSame('Pertama', DB::table('report_layouts')->where('id', $id)->value('name'));

        // Pilihan default lingkup tenant belum punya baris: versi 0 wajib dikirim, dan 0 yang dikirim
        // lagi sesudah barisnya ada berarti basi. Mengosongkan pilihan menghapus barisnya, jadi versi
        // lama yang dikirim sesudahnya juga basi.
        $this->putJson("{$base}/layout-default", ['layout_ref' => $id, 'scope' => 'tenant'])->assertStatus(428);
        $this->putJson("{$base}/layout-default", ['layout_ref' => $id, 'scope' => 'tenant', 'version' => 0])
            ->assertOk()
            ->assertJsonPath('meta.default_versions.tenant', 1);
        $this->putJson("{$base}/layout-default", ['layout_ref' => null, 'scope' => 'tenant', 'version' => 0])->assertStatus(409);
        $this->assertSame($id, $this->getJson("{$base}/layouts")->json('meta.default_ref'));
        $this->putJson("{$base}/layout-default", ['layout_ref' => null, 'scope' => 'tenant', 'version' => 1])
            ->assertOk()
            ->assertJsonPath('meta.default_versions.tenant', 0);
        $this->putJson("{$base}/layout-default", ['layout_ref' => $id, 'scope' => 'tenant', 'version' => 1])->assertStatus(409);

        $this->deleteJson("{$base}/layouts/{$id}", [], ['If-Match' => 'W/"3"'])->assertNoContent();
        $this->assertFalse(DB::table('report_layouts')->where('id', $id)->exists());
    }

    public function test_layout_bermakro_ditolak_dan_penolakan_module_menjadi_ekspor_gagal(): void
    {
        $macro = UploadedFile::fake()->createWithContent('jahat.docx', $this->docxWithMacro());
        $this->actingAs($this->owner)
            ->post('/api/v1/reports/'.self::KODE_LAPORAN.'/layouts', ['file' => $macro, 'name' => 'Jahat', 'scope' => 'tenant'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        // Work order yang tidak dapat dijangkau pengguna. Pesannya datang dari module, kata per
        // kata sama dengan yang dilihat pengguna di layar, dan Core meneruskannya apa adanya ke
        // baris ekspor alih-alih mengubahnya menjadi kesalahan server.
        $this->assertSame(
            'Work order tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.',
            $this->pesanEksporGagal((string) Str::ulid()),
        );

        // Parameter yang tidak lolos aturan module juga berhenti sebagai ekspor gagal, bukan
        // sebagai 500. Aturannya milik module; Core tidak pernah mengetahuinya.
        $this->assertStringContainsString('Parameter laporan tidak diterima', $this->pesanEksporGagal('bukan-ulid'));
    }

    public function test_ekspor_kedaluwarsa_dibersihkan_saat_daftar_dibaca(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/'.self::KODE_LAPORAN.'/exports', ['format' => 'docx', 'parameters' => ['id' => $this->workOrderId]])
            ->json('data');
        $path = DB::table('report_exports')->where('id', $response['id'])->value('file_path');
        Storage::disk('reporting-test')->assertExists($path);

        // Retensi ekspor dihitung dari waktu dibuat (area 4); bawaannya config reporting.retention_days.
        DB::table('report_exports')->where('id', $response['id'])->update(['created_at' => now()->subDays((int) config('reporting.retention_days') + 1)]);
        $this->actingAs($this->owner)->getJson('/api/v1/report-exports')->assertOk()->assertJsonCount(0, 'data');
        Storage::disk('reporting-test')->assertMissing($path);
    }

    /**
     * 8.2: masa simpan yang tertulis pada baris berasal dari setelan tenant, bukan dari config, dan
     * penghapusannya berjalan lewat layanan retensi — dibuktikan dengan menjalankan job-nya sungguhan.
     */
    public function test_masa_simpan_ekspor_mengikuti_setelan_tenant_dan_dihapus_layanan_retensi(): void
    {
        $tenantId = (string) $this->membership->tenant_id;
        app(RetentionService::class)->save($tenantId, RetentionPolicies::find('report_exports'), true, 3);
        $this->assertNotSame(3, (int) config('reporting.retention_days'), 'Setelan tenant harus berbeda dari bawaan config supaya test ini membuktikan sesuatu.');

        $lama = $this->ekspor('docx');
        $baru = $this->ekspor('docx');
        foreach ([$lama, $baru] as $export) {
            $this->assertSame('done', $export->status, $export->failure_message ?? '');
            $this->assertEqualsWithDelta(
                now()->addDays(3)->getTimestamp(),
                Carbon::parse($export->expires_at)->getTimestamp(),
                60,
                'expires_at tidak mengikuti masa simpan tenant (3 hari).',
            );
        }

        // Hanya yang lebih tua dari masa simpan tenant yang hilang; bawaan config (7 hari) menahan keduanya.
        DB::table('report_exports')->where('id', $lama->id)->update(['created_at' => now()->subDays(4)]);
        DB::table('report_exports')->where('id', $baru->id)->update(['created_at' => now()->subDays(2)]);
        $this->artisan('reporting:purge-exports')->assertSuccessful();

        $this->assertFalse(DB::table('report_exports')->where('id', $lama->id)->exists());
        Storage::disk('reporting-test')->assertMissing($lama->file_path);
        $this->assertTrue(DB::table('report_exports')->where('id', $baru->id)->exists());
        Storage::disk('reporting-test')->assertExists($baru->file_path);
    }

    /** B-8: gangguan sesaat pada layanan PDF diulang sampai batas percobaan, lalu gagal dengan pesan yang jelas. */
    public function test_gangguan_sesaat_diulang_sampai_batas_percobaan_lalu_gagal(): void
    {
        $this->pakaiAntreanDatabase();
        $this->jawabanRenderer = [
            Http::response('sibuk', 503),
            Http::failedConnection('cURL error 28: Operation timed out'),
            Http::response('gateway', 504),
        ];

        $id = $this->mintaEkspor('pdf');
        $this->jalankanWorkerSekali();

        $export = DB::table('report_exports')->where('id', $id)->first();
        $this->assertSame('queued', $export->status, 'Gangguan sesaat pertama tidak boleh langsung menggagalkan ekspor.');
        $this->assertSame('Gangguan sesaat saat membuat dokumen. Ekspor dicoba lagi otomatis (percobaan 2 dari 3).', $export->failure_message);
        $this->assertSame(1, DB::table('jobs')->count(), 'Job-nya harus kembali ke antrean untuk dicoba lagi.');

        $this->jalankanWorkerSekali();
        $this->jalankanWorkerSekali();

        $export = DB::table('report_exports')->where('id', $id)->first();
        $this->assertSame('failed', $export->status);
        $this->assertSame(
            'Layanan PDF sedang tidak dapat melayani. Coba lagi beberapa saat, atau pilih format Word atau Excel. Sudah dicoba 3 kali.',
            $export->failure_message,
        );
        $this->assertSame(3, $this->jumlahPanggilanRenderer());
        $this->assertSame(0, DB::table('jobs')->count(), 'Percobaan keempat tidak boleh ada.');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Kegagalan sudah dicatat pada baris ekspor; job-nya sendiri selesai.');
    }

    /** B-8: gangguan sesaat yang pulih pada percobaan berikutnya berakhir sebagai ekspor yang selesai. */
    public function test_gangguan_sesaat_yang_pulih_berakhir_selesai(): void
    {
        $this->pakaiAntreanDatabase();
        $this->jawabanRenderer = [Http::failedConnection('cURL error 7: Connection refused')];

        $id = $this->mintaEkspor('pdf');
        $this->jalankanWorkerSekali();
        $this->jalankanWorkerSekali();

        $export = DB::table('report_exports')->where('id', $id)->first();
        $this->assertSame('done', $export->status, $export->failure_message ?? '');
        $this->assertNull($export->failure_message, 'Catatan percobaan ulang harus hilang setelah ekspornya berhasil.');
        Storage::disk('reporting-test')->assertExists($export->file_path);
        $this->assertSame(2, $this->jumlahPanggilanRenderer());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /** B-8: layout yang ditolak dan data yang terlalu besar gagal pada percobaan pertama tanpa diulang. */
    public function test_kegagalan_layout_dan_data_terlalu_besar_tidak_diulang(): void
    {
        $this->pakaiAntreanDatabase();

        $this->jawabanRenderer = [Http::response('dokumen rusak', 400)];
        $id = $this->mintaEkspor('pdf');
        $this->jalankanWorkerSekali();

        $export = DB::table('report_exports')->where('id', $id)->first();
        $this->assertSame('failed', $export->status);
        $this->assertSame('Layanan PDF menolak dokumen (400). Periksa layout, lalu coba lagi.', $export->failure_message);
        $this->assertSame(1, $this->jumlahPanggilanRenderer());
        $this->assertSame(0, DB::table('jobs')->count(), 'Penolakan atas dokumen akan terulang persis sama; ia tidak boleh diulang.');

        config(['reporting.max_rows' => 0]);
        $id = $this->mintaEkspor('docx');
        $this->jalankanWorkerSekali();

        $export = DB::table('report_exports')->where('id', $id)->first();
        $this->assertSame('failed', $export->status);
        $this->assertStringStartsWith('Data terlalu besar untuk satu ekspor', (string) $export->failure_message);
        $this->assertSame(0, DB::table('jobs')->count(), 'Data terlalu besar akan terulang persis sama; ia tidak boleh diulang.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /**
     * B-8: worker yang mati di tengah ekspor meninggalkan baris `running`. Selama sewanya belum habis,
     * percobaan berikutnya tidak menyentuhnya — worker pertama mungkin masih bekerja — tetapi setelah
     * sewanya habis ekspor itu diambil alih dan selesai.
     */
    public function test_ekspor_yang_ditinggal_worker_mati_diambil_alih_setelah_sewanya_habis(): void
    {
        $this->pakaiAntreanDatabase();
        $id = $this->mintaEkspor('docx');
        DB::table('report_exports')->where('id', $id)->update(['status' => 'running', 'progress' => 40, 'started_at' => now()->subSeconds(10)]);

        $this->jalankanWorkerSekali();

        $export = DB::table('report_exports')->where('id', $id)->first();
        $this->assertSame('running', $export->status, 'Baris yang sewanya belum habis tidak boleh dikerjakan worker kedua.');
        $this->assertSame(40, (int) $export->progress);
        $job = DB::table('jobs')->first();
        $this->assertNotNull($job, 'Job-nya harus kembali ke antrean sampai sewa worker pertama habis.');
        $this->assertGreaterThan(now()->addSeconds(500)->getTimestamp(), (int) $job->available_at);

        // Worker pertama tidak pernah kembali: sewanya habis, dan antrean menyerahkan job-nya lagi.
        DB::table('report_exports')->where('id', $id)->update(['started_at' => now()->subSeconds(700)]);
        DB::table('jobs')->update(['available_at' => now()->getTimestamp()]);
        $this->jalankanWorkerSekali();

        $export = DB::table('report_exports')->where('id', $id)->first();
        $this->assertSame('done', $export->status, $export->failure_message ?? '');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Antrean yang menyerah atas nama job ini tetap meninggalkan pesan pada baris ekspor — kecuali bila
     * percobaan yang masih memegang sewa akan menulis hasilnya sendiri.
     */
    public function test_antrean_yang_menyerah_meninggalkan_pesan_pada_baris_ekspor(): void
    {
        $tenantId = (string) $this->membership->tenant_id;
        $this->pakaiAntreanDatabase();

        $habisWaktu = $this->mintaEkspor('docx');
        DB::table('report_exports')->where('id', $habisWaktu)->update(['status' => 'running', 'started_at' => now()]);
        (new RunReportExport($tenantId, $habisWaktu))->failed(new TimeoutExceededException('habis waktu'));
        $this->assertSame(
            'Ekspor dihentikan karena melewati batas waktu 10 menit. Persempit filternya, lalu coba lagi.',
            DB::table('report_exports')->where('id', $habisWaktu)->value('failure_message'),
        );

        $masihBerjalan = $this->mintaEkspor('docx');
        DB::table('report_exports')->where('id', $masihBerjalan)->update(['status' => 'running', 'started_at' => now()->subSeconds(30)]);
        (new RunReportExport($tenantId, $masihBerjalan))->failed(new MaxAttemptsExceededException('habis percobaan'));
        $this->assertSame('running', DB::table('report_exports')->where('id', $masihBerjalan)->value('status'));

        DB::table('report_exports')->where('id', $masihBerjalan)->update(['started_at' => now()->subSeconds(700)]);
        (new RunReportExport($tenantId, $masihBerjalan))->failed(new MaxAttemptsExceededException('habis percobaan'));
        $export = DB::table('report_exports')->where('id', $masihBerjalan)->first();
        $this->assertSame('failed', $export->status);
        $this->assertSame('Ekspor terhenti karena proses di server terputus berulang kali. Coba cetak lagi; bila terulang, hubungi administrator.', $export->failure_message);
    }

    public function test_identitas_cetak_mengisi_placeholder_kop_dan_logo_pada_dokumen(): void
    {
        $legalEntity = $this->legalEntityId;

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
                'version' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.parent_lines.1', 'DINAS KESEHATAN')
            ->assertJsonPath('data.display_name', 'CV Surya Jaya')
            ->assertJsonPath('data.address_lines.0', 'Jl. I Gusti Ngurah Rai')
            ->assertJsonPath('data.phone', '(0361) 829769')
            ->assertJsonPath('data.email', 'puskesmasmengwisatu@gmail.com');

        $logo = UploadedFile::fake()->image('lambang.png', 120, 120);
        $this->actingAs($this->owner)
            ->post("/api/v1/organizations/{$legalEntity}/print-identity/logos", ['file' => $logo, 'position' => 'kiri', 'version' => $this->versiIdentitas($legalEntity)])
            ->assertCreated()
            ->assertJsonPath('data.logos.0.position', 'kiri');
        $this->actingAs($this->owner)
            ->post("/api/v1/organizations/{$legalEntity}/print-identity/logos", ['file' => UploadedFile::fake()->image('instansi.png', 80, 80), 'position' => 'kanan', 'version' => $this->versiIdentitas($legalEntity)])
            ->assertCreated()
            ->assertJsonCount(2, 'data.logos');

        // Anggota biasa boleh melihat, tidak boleh mengubah; SVG ditolak.
        $this->actingAs($this->memberWithoutRoles())->getJson("/api/v1/organizations/{$legalEntity}/print-identity")->assertOk();
        $this->actingAs($this->memberWithoutRoles())->putJson("/api/v1/organizations/{$legalEntity}/print-identity", ['phone' => 'x'])->assertForbidden();
        $this->actingAs($this->owner)
            ->post("/api/v1/organizations/{$legalEntity}/print-identity/logos", ['file' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'), 'position' => 'kiri'])
            ->assertStatus(422);

        // Layout Word dengan kop: teks dan dua logo terisi, placeholder kop tanpa data kosong.
        // Ditaruh langsung pada salinan layout bawaan yang di-cache Core per versi app; cache
        // yang sudah terisi membuat Core tidak lagi meminta berkasnya ke module, jadi yang
        // dirender pasti template ini.
        $version = DB::table('apps')->where('id', 'management-aset')->value('version');
        Storage::disk('reporting-test')->put("reporting/builtin/management-aset/{$version}/".self::KODE_LAPORAN.'-standar.docx', $this->docxTemplateWithKop());
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/'.self::KODE_LAPORAN.'/exports', ['format' => 'docx', 'parameters' => ['id' => $this->workOrderId]])
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
        $this->actingAs($this->owner)->getJson('/api/v1/reports/'.self::KODE_LAPORAN.'/fields')
            ->assertOk()
            ->assertJsonFragment(['key' => 'kop.logo_kanan']);
    }

    /**
     * Mendaftarkan katalog app dari manifest module aset yang sungguhan.
     *
     * Barisnya tidak lagi ditulis tangan di test ini. Yang dipakai adalah perintah
     * `app:register-manifest` — jalur yang sama dengan yang dijalankan admin on-prem — supaya
     * kode laporan, permission, parameter, dan layout bawaan di katalog **hanya** bisa datang
     * dari manifest. Selama keduanya ditulis di dua tempat, keduanya akan menyimpang, dan
     * penyimpangannya baru terlihat sebagai ekspor gagal di tangan pengguna.
     *
     * Sampai 9 September 2026 manifestnya harus disalin lebih dulu ke folder bernama lain,
     * karena `management-aset` masih terdaftar di `ModulSedangDipindah` dan module yang
     * ditandai memang sengaja tidak didaftarkan ke katalog. Salinan itu dibuang pada F3-30:
     * modulnya kini dilayani, jadi registry yang sungguhan sudah memulangkannya.
     */
    private function daftarkanKatalogDariManifest(): void
    {
        $this->artisan('app:register-manifest', ['module' => 'management-aset'])->assertSuccessful();
    }

    /**
     * Menutup seluruh kabel kecuali layanan render PDF.
     *
     * `core-renderer` memang berada di luar deployment ini — ia Gotenberg, tidak mengenal
     * tenant, dan tidak ada di lingkungan test — jadi ia tetap dipalsukan seperti sebelumnya.
     * Segala alamat lain dicatat dan dijawab gagal, sehingga sisa lompatan HTTP antar bagian
     * tidak bisa lewat tanpa suara: ia muncul di `$permintaanHttpLain`, dan ekspornya gagal.
     */
    private function fakeHanyaLayananRender(): void
    {
        Http::fake([
            'renderer.test/*' => function (ClientRequest $request) {
                $jawaban = array_shift($this->jawabanRenderer) ?? Http::response('%PDF-1.4 palsu', 200, ['Content-Type' => 'application/pdf']);

                return $jawaban instanceof \Closure ? $jawaban($request) : $jawaban;
            },
            '*' => function (ClientRequest $request) {
                // Laravel memanggil **setiap** stub untuk tiap permintaan lalu memakai jawaban
                // pertama yang bukan null, jadi stub penampung ini ikut terpanggil untuk
                // permintaan ke layanan render. Penyaringannya di sini, bukan di pola stub-nya.
                if (str_contains($request->url(), 'renderer.test/')) {
                    return null;
                }

                $this->permintaanHttpLain[] = $request->url();

                return Http::response(['message' => 'Bagian ini tidak boleh dihubungi lewat HTTP.'], 503);
            },
        ]);
    }

    /** Meminta ekspor work order lewat API dan memulangkan id barisnya. */
    private function mintaEkspor(string $format): string
    {
        return (string) $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/'.self::KODE_LAPORAN.'/exports', ['format' => $format, 'parameters' => ['id' => $this->workOrderId]])
            ->assertStatus(202)
            ->json('data.id');
    }

    /** Baris ekspor setelah job-nya berjalan; hanya bermakna selama antreannya `sync`. */
    private function ekspor(string $format): \stdClass
    {
        $export = DB::table('report_exports')->where('id', $this->mintaEkspor($format))->first();
        $this->assertNotNull($export);

        return $export;
    }

    /**
     * Antrean sync tidak pernah menjalankan ulang job yang dikembalikan, jadi test percobaan ulang memakai
     * antrean database yang sama dengan deployment dan worker sungguhan. Jedanya nol supaya job yang
     * dikembalikan langsung dapat diambil lagi.
     */
    private function pakaiAntreanDatabase(): void
    {
        config(['queue.default' => 'database', 'reporting.export_attempts' => 3, 'reporting.export_retry_seconds' => [0, 0]]);
    }

    private function jalankanWorkerSekali(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--memory' => 4096])->assertSuccessful();
    }

    private function jumlahPanggilanRenderer(): int
    {
        return Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), 'renderer.test/'))->count();
    }

    /** Pesan kegagalan pada baris ekspor untuk sebuah parameter `id`. */
    private function pesanEksporGagal(string $id): string
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/reports/'.self::KODE_LAPORAN.'/exports', ['format' => 'docx', 'parameters' => ['id' => $id]])
            ->assertStatus(202)->json('data');
        $export = DB::table('report_exports')->where('id', $response['id'])->first();

        $this->assertSame('failed', $export->status, sprintf(
            'Ekspor dengan parameter id=%s justru berhasil. Penolakan module adalah satu-satunya hal '
            .'yang menahan seseorang mencetak dokumen di luar jangkauannya lewat jalur laporan.',
            $id,
        ));

        return (string) $export->failure_message;
    }

    /**
     * Laporan yang diuji, dari katalog definisi laporan module aset.
     *
     * Dibaca dari definisinya, bukan disalin ke dalam test, supaya assertion tentang katalog
     * membuktikan katalog sama dengan definisi module — satu-satunya sumbernya sejak blok
     * `reports` manifest dibuang — bukan sama dengan angan-angan test ini.
     *
     * @return array<string, mixed>
     */
    private function reportFromModuleCatalog(): array
    {
        foreach (app(DaftarLaporanModul::class)->untuk('management-aset')?->catalog() ?? [] as $laporan) {
            if ($laporan['code'] === self::KODE_LAPORAN) {
                return $laporan;
            }
        }

        $this->fail(sprintf('Module aset tidak lagi mendefinisikan laporan `%s`.', self::KODE_LAPORAN));
    }

    private function memberWithoutRoles(): User
    {
        $user = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $user->id, 'status' => 'active',
        ]);

        return $user;
    }

    /**
     * Satu work order draf dengan dua baris pekerjaan, milik tenant dan legal entity test.
     *
     * Pola ini ditiru dari `PenyediaLaporanTest::workOrder()` milik module: master data
     * disisipkan langsung, tetapi work ordernya dibuat lewat API module seperti pengguna
     * sungguhan. Bedanya hanya baris pekerjaannya dua, bukan satu — laporan Excel di sini
     * membuktikan baris template digandakan dan rumus di bawahnya ikut bergeser, dan satu
     * baris tidak dapat membuktikan keduanya.
     *
     * Nomornya tidak ditebak. Ia diterbitkan Core dari urutan nomor tenant, dan test membaca
     * nilai yang benar-benar tersimpan; menuliskannya sebagai konstanta akan membuat test ini
     * merah setiap kali profil nomor bawaan berubah, karena alasan yang bukan soal laporan.
     */
    private function buatWorkOrder(): void
    {
        $seed = [
            'tipe' => $this->master('aset_m_tipe_work_order', 'Korektif', 'TPWO-1'),
            'layanan' => $this->master('aset_m_tingkat_layanan', 'Mendesak', 'TGLY-1', ['urutan' => 1]),
            'trade' => $this->master('aset_m_trade', 'Mekanik', 'TRDE-1'),
            'jobType' => $this->master('aset_m_maintenance_job_type', 'Ganti ban', 'JOB-1', ['category_code' => 'corrective']),
            'group' => $this->master('aset_m_group_aset', 'Kendaraan', 'GRPA-1'),
            'jenis' => $this->master('aset_m_jenis_aset', 'Kendaraan roda 4', 'JNSA-1'),
            'tipeLokasi' => $this->master('aset_m_tipe_lokasi_aset', 'Gudang', 'TLKA-1'),
        ];
        $locationId = (string) Str::ulid();
        DB::table('aset_m_lokasi_aset')->insert([
            'id' => $locationId, 'tenant_id' => $this->membership->tenant_id, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'LOCA-1', 'nama' => 'Gudang Cakung', 'tipe_lokasi_id' => $seed['tipeLokasi'], 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $details = [];
        foreach ([['AST-WO-1', 'Forklift 1', 1.5], ['AST-WO-2', 'Genset 1', 2]] as [$kode, $nama, $jam]) {
            $assetId = (string) Str::ulid();
            // Register aset bernama `aset_tr_aset` sejak 18 September 2026; sebelumnya
            // `aset_tr_penerimaan_aset`, nama yang kini dipakai dokumen penerimaannya.
            DB::table('aset_tr_aset')->insert([
                'id' => $assetId, 'tenant_id' => $this->membership->tenant_id, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
                'nama' => $nama, 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
                'group_aset_id' => $seed['group'], 'jenis_aset_id' => $seed['jenis'], 'lokasi_aset_id' => $locationId,
                'acquired_on' => '2026-08-01', 'acquisition_value' => 250000000, 'currency_code' => 'IDR',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $details[] = [
                'aset_id' => $assetId, 'maintenance_job_type_id' => $seed['jobType'], 'trade_id' => $seed['trade'],
                'ditugaskan_ke_user_id' => 'montir-1', 'estimasi_jam' => $jam,
            ];
        }

        $this->workOrderId = $this->actingAs($this->owner)
            ->withHeader('Idempotency-Key', 'wo-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/pemeliharaan-aset', [
                'legal_entity_id' => $this->legalEntityId,
                'responsible_org_unit_id' => $this->orgUnitId,
                'tipe_work_order_id' => $seed['tipe'],
                'tingkat_layanan_id' => $seed['layanan'],
                'keterangan' => 'Ban depan kanan bocor',
                'diharapkan_mulai' => '2026-08-15 08:00:00',
                'diharapkan_selesai' => '2026-08-15 12:00:00',
                'details' => $details,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->kodeWorkOrder = (string) DB::table('aset_tr_pemeliharaan_aset')->where('id', $this->workOrderId)->value('kode');
    }

    /** @param array<string, mixed> $extra */
    private function master(string $table, string $nama, string $kode, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $this->membership->tenant_id, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $kode, 'nama' => $nama, 'aktif' => true, ...$extra,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Legal entity milik tenant test; registrasi bisnis belum membuatnya. */
    private function buatLegalEntity(string $name): string
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

    private function spreadsheetTemplate(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Nomor: ${kode}');
        $sheet->setCellValue('A2', 'Aset');
        $sheet->setCellValue('B2', 'Jam');
        $sheet->setCellValue('A3', '${baris.aset_nama}');
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

    /** Versi identitas cetak yang tersimpan; 0 bila belum pernah disimpan. */
    private function versiIdentitas(string $organizationId): int
    {
        return (int) DB::table('print_identities')->where('organization_id', $organizationId)->value('version');
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
