<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\NumberSequenceProfileSeeder;
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
use Symfony\Component\Yaml\Yaml;
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
 * `app.yaml` module lewat perintah pendaftaran yang sama dengan yang dipakai on-prem,
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

        $this->seed(NumberSequenceProfileSeeder::class);
        $this->daftarkanKatalogDariManifest();
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant laporan',
            'app_ids' => ['management-aset'], 'email' => 'owner@laporan.test', 'password' => 'password',
        ]);
        $this->membership = $this->owner->activeMembership();
        $this->bootstrapRuntime();

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
        $manifest = $this->laporanDiManifest();

        $data = $this->actingAs($this->owner)->getJson('/api/v1/reports')->assertOk()->json('data');
        $laporan = collect($data)->firstWhere('code', self::KODE_LAPORAN);

        $this->assertNotNull($laporan, sprintf(
            'Katalog laporan tidak memuat `%s`. Barisnya berasal dari blok `reports` app.yaml module; '
            .'kalau ia hilang, yang hilang bukan sekadar satu entri daftar melainkan tombol Cetak pada '
            .'layar work order, dan tidak ada pesan kesalahan di mana pun yang menyebutkannya.',
            self::KODE_LAPORAN,
        ));
        $this->assertSame('Management Aset', $laporan['app_name']);
        $this->assertSame($manifest['permission'], $laporan['permission'], sprintf(
            'Permission laporan di katalog (%s) berbeda dari yang dideklarasikan manifest (%s). Katalog '
            .'menentukan siapa yang melihat tombol Cetak, sedangkan module menegakkan permission-nya '
            .'sendiri saat dataset dibaca; ketika keduanya berbeda, penggunanya melihat tombol yang '
            .'selalu berujung ekspor gagal.',
            $laporan['permission'],
            $manifest['permission'],
        ));
        $this->assertSame($manifest['parameters'], $laporan['parameters']);
        $this->assertSame('bawaan:'.$manifest['builtin_layouts'][0]['key'], $laporan['builtin_layouts'][0]['ref']);
        $this->assertTrue($laporan['can_run'], sprintf(
            'Pemilik bisnis tidak dianggap berhak menjalankan laporannya sendiri. Ia menerima seluruh '
            .'duty app yang di-entitle, jadi `%s` mestinya sampai kepadanya lewat rantai '
            .'role -> duty -> privilege -> permission; kalau tidak, rantai itu putus di suatu tempat dan '
            .'seluruh module ikut tertutup untuknya, bukan hanya laporan ini.',
            $manifest['permission'],
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
            ->putJson('/api/v1/reports/'.self::KODE_LAPORAN.'/layout-default', ['layout_ref' => $layout['id'], 'scope' => 'tenant'])
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

        DB::table('report_exports')->where('id', $response['id'])->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($this->owner)->getJson('/api/v1/report-exports')->assertOk()->assertJsonCount(0, 'data');
        Storage::disk('reporting-test')->assertMissing($path);
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
        // Ditaruh langsung pada salinan layout bawaan yang di-cache Core per versi app; cache
        // yang sudah terisi membuat Core tidak lagi meminta berkasnya ke module, jadi yang
        // dirender pasti template ini.
        $version = DB::table('apps')->where('id', 'management-aset')->value('version');
        $releaseKey = substr(sha1('local/api@sha256:'.str_repeat('a', 64)), 0, 12);
        Storage::disk('reporting-test')->put("reporting/builtin/management-aset/{$version}-{$releaseKey}/".self::KODE_LAPORAN.'-standar.docx', $this->docxTemplateWithKop());
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
     * Mendaftarkan katalog app dari `app.yaml` module aset yang sungguhan.
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
     * Perintah ini dipakai sebagai persiapan saja: laporan hanya jalan setelah ada baris
     * release dan placement yang siap. Bentuk rilisnya sendiri tidak diuji di berkas ini,
     * jadi yang berubah di sini hanya cara memanggil perintahnya — satu image edisi,
     * tanpa nama layanan.
     */
    private function bootstrapRuntime(): void
    {
        $manifest = tempnam(sys_get_temp_dir(), 'coreerp-manifest-');
        File::put($manifest, "id: management-aset\nversion: 0.1.0\n");
        $this->artisan('app:bootstrap-local-runtime', [
            'manifest' => $manifest,
            '--edition-image' => 'local/edisi@sha256:'.str_repeat('a', 64),
        ])->assertSuccessful();
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
            'renderer.test/*' => Http::response('%PDF-1.4 palsu', 200, ['Content-Type' => 'application/pdf']),
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
     * Blok `reports` manifest untuk laporan yang diuji.
     *
     * Dibaca dari berkas, bukan disalin ke dalam test, supaya assertion tentang katalog
     * membuktikan katalog sama dengan manifest — bukan sama dengan angan-angan test ini.
     *
     * @return array<string, mixed>
     */
    private function laporanDiManifest(): array
    {
        /** @var array<string, mixed> $manifest */
        $manifest = Yaml::parseFile($this->berkasManifest());

        foreach ($manifest['reports'] ?? [] as $laporan) {
            if (($laporan['code'] ?? null) === self::KODE_LAPORAN) {
                return $laporan;
            }
        }

        $this->fail(sprintf('app.yaml module aset tidak lagi mendeklarasikan laporan `%s`.', self::KODE_LAPORAN));
    }

    private function berkasManifest(): string
    {
        return dirname(base_path(), 2).'/modules/apperp/management-aset/app.yaml';
    }

    private function memberWithoutRoles(): User
    {
        $user = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $user->id, 'system_role' => 'member', 'status' => 'active',
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
            DB::table('aset_tr_penerimaan_aset')->insert([
                'id' => $assetId, 'tenant_id' => $this->membership->tenant_id, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
                'nama' => $nama, 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
                'group_aset_id' => $seed['group'], 'jenis_aset_id' => $seed['jenis'], 'asset_location_id' => $locationId,
                'acquired_on' => '2026-08-01', 'acquisition_value' => 250000000, 'currency_code' => 'IDR',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $details[] = [
                'asset_id' => $assetId, 'maintenance_job_type_id' => $seed['jobType'], 'trade_id' => $seed['trade'],
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
