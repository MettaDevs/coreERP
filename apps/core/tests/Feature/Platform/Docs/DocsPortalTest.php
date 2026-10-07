<?php

namespace Tests\Feature\Platform\Docs;

use App\Foundation\Currency\Support\MoneyPrecision;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Models\ProviderAccess;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\CocokDenganKontrak;
use Tests\TestCase;

/**
 * Portal dokumentasi `/docs`: spesifikasi integrasi terbuka untuk tim luar tanpa login, referensi
 * internal hanya untuk admin penyedia.
 */
class DocsPortalTest extends TestCase
{
    use CocokDenganKontrak, RefreshDatabase;

    public function test_tamu_hanya_melihat_spesifikasi_integrasi(): void
    {
        $halaman = $this->get('/docs')->assertOk();

        $halaman->assertSee('Integrasi · Finance', false);
        $halaman->assertSee(route('docs.kontrak', 'integrasi-finance'), false);
        $halaman->assertDontSee('Layar CoreERP (internal)', false);
        $halaman->assertDontSee('Pusat admin (internal)', false);
    }

    public function test_tamu_dapat_mengunduh_kontrak_integrasi_yang_hanya_memuat_endpoint_klien_integrasi(): void
    {
        $jawaban = $this->get('/docs/kontrak/integrasi-finance.yaml')->assertOk();

        $this->assertStringStartsWith('application/yaml', (string) $jawaban->headers->get('Content-Type'));
        $spesifikasi = Yaml::parse((string) $jawaban->getContent());
        $this->assertSame(
            ['/finance-postings', '/finance-postings/{posting_id}/ack', '/legal-entities', '/operating-units', '/vendors'],
            collect(array_keys($spesifikasi['paths']))->sort()->values()->all(),
        );
        $this->assertSame(['integrationClient'], array_keys($spesifikasi['components']['securitySchemes']));
        $this->assertArrayHasKey('financePosting', $spesifikasi['webhooks']);
        // Endpoint yang juga dibaca module menampilkan cara masuk klien integrasi saja.
        $this->assertSame([['integrationClient' => []]], $spesifikasi['paths']['/operating-units']['get']['security']);
        $this->assertSame([['integrationClient' => []]], $spesifikasi['paths']['/legal-entities']['get']['security']);
        $this->assertStringContainsString('Langkah 1', $spesifikasi['info']['description']);
    }

    public function test_contoh_payload_di_panduan_cocok_dengan_skemanya_dan_seimbang(): void
    {
        $spesifikasi = Yaml::parseFile(base_path('contracts/terbit/integrasi-finance.yaml'));
        $contoh = $spesifikasi['paths']['/finance-postings']['get']['responses']['200']['content']['application/json']['examples'];

        $this->assertNotEmpty($contoh);
        foreach ($contoh as $nama => $isi) {
            foreach ($isi['value']['data'] as $posting) {
                $this->assertCocokSkema($posting, 'FinancePosting', 'contracts/terbit/integrasi-finance.yaml');
                $debit = MoneyPrecision::sum(...array_column($posting['journal_lines'], 'debit'));
                $kredit = MoneyPrecision::sum(...array_column($posting['journal_lines'], 'credit'));
                $this->assertTrue(MoneyPrecision::decimal($debit)->isEqualTo($kredit), "Contoh {$nama} tidak seimbang.");
                $this->assertTrue(MoneyPrecision::decimal($debit)->isEqualTo($posting['totals']['debit']), "Total contoh {$nama} tidak sama dengan jumlah barisnya.");
            }
        }
    }

    public function test_daftar_jenis_posting_punya_bagian_sendiri_dan_sama_di_setiap_tempat(): void
    {
        // Pembaca menemukan daftar ini lewat pencarian docs: judul bagian di panduan dan model
        // `PostingType`. Keduanya, parameter penyaring, dan contoh payload harus memuat daftar yang
        // sama. Daftar yang hanya lengkap di satu tempat membuat pembaca menebak.
        $spesifikasi = Yaml::parseFile(base_path('contracts/terbit/integrasi-finance.yaml'));

        $daftar = $this->jenisDiTabel($this->bagianPanduan($spesifikasi['info']['description'], 'Jenis posting (posting_type)'));
        $this->assertNotEmpty($daftar, 'Bagian Jenis posting tidak memuat tabel jenis.');

        $model = $spesifikasi['components']['schemas']['PostingType'];
        $this->assertSame($daftar, $model['examples']);
        $this->assertSame($daftar, $this->jenisDiTabel($model['description']));
        $this->assertSame(['$ref' => '#/components/schemas/PostingType'], $spesifikasi['components']['schemas']['FinancePosting']['properties']['posting_type']);

        $parameters = $spesifikasi['paths']['/finance-postings']['get']['parameters'] ?? [];
        $this->assertIsArray($parameters);
        $parameter = null;
        foreach ($parameters as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === 'posting_type') {
                $parameter = $candidate;

                break;
            }
        }
        $this->assertIsArray($parameter);
        $description = $parameter['description'] ?? null;
        $this->assertIsString($description);
        foreach ($daftar as $jenis) {
            $this->assertStringContainsString("`{$jenis}`", $description, "Parameter posting_type tidak menyebut {$jenis}.");
        }

        $contoh = $spesifikasi['paths']['/finance-postings']['get']['responses']['200']['content']['application/json']['examples'];
        foreach ($contoh as $nama => $isi) {
            foreach ($isi['value']['data'] as $posting) {
                $this->assertContains($posting['posting_type'], $daftar, "Contoh {$nama} memakai jenis yang tidak ada di daftar.");
            }
        }
    }

    public function test_tamu_dapat_mengunduh_kontrak_analitik_yang_hanya_memuat_endpoint_publikasi(): void
    {
        $this->get('/docs')->assertOk()->assertSee('Integrasi · Analitik', false);
        $this->get('/docs?spec=integrasi-analitik')->assertOk()->assertSee(route('docs.kontrak', 'integrasi-analitik'), false);

        $spesifikasi = Yaml::parse((string) $this->get('/docs/kontrak/integrasi-analitik.yaml')->assertOk()->getContent());
        $paths = $spesifikasi['paths'] ?? [];
        $this->assertIsArray($paths);
        $pathNames = array_map('strval', array_keys($paths));
        sort($pathNames);
        $this->assertSame(['/analytics/publications', '/analytics/publications/{code}', '/analytics/publications/{code}/rows'], $pathNames);

        $schemes = $spesifikasi['components']['securitySchemes'] ?? [];
        $this->assertIsArray($schemes);
        $schemeNames = array_map('strval', array_keys($schemes));
        sort($schemeNames);
        $this->assertSame(['integrationClient'], $schemeNames);
        foreach (['Memulai', 'Langkah 1', 'Halaman dan cursor', 'CSV', 'Google Sheets lewat Apps Script', 'n8n, Make, dan Zapier', 'Kode galat'] as $bagian) {
            $this->assertStringContainsString('## '.$bagian, $spesifikasi['info']['description']);
        }
    }

    public function test_daftar_format_dan_kode_galat_analitik_punya_bagian_sendiri_dan_sama_di_setiap_tempat(): void
    {
        // Pembaca mencari "format" dan "kode galat" di docs: judul bagian di panduan dan model sendiri harus
        // memuat daftar yang sama, dan daftar itu harus sama dengan yang dikirim kode.
        $spesifikasi = Yaml::parseFile(base_path('contracts/terbit/integrasi-analitik.yaml'));
        $panduan = $spesifikasi['info']['description'];

        $format = $this->nilaiDiTabel($this->bagianPanduan($panduan, 'Format baris'), '[a-z]+');
        $this->assertSame(Publication::FORMATS, $format);
        $this->assertSame($format, $spesifikasi['components']['schemas']['AnalyticsFormat']['examples']);
        $this->assertSame($format, $this->nilaiDiTabel($spesifikasi['components']['schemas']['AnalyticsFormat']['description'], '[a-z]+'));
        $parameters = $spesifikasi['paths']['/analytics/publications/{code}/rows']['get']['parameters'] ?? [];
        $this->assertIsArray($parameters);
        $parameter = null;
        foreach ($parameters as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === 'format') {
                $parameter = $candidate;

                break;
            }
        }
        $this->assertIsArray($parameter);
        $this->assertSame('#/components/schemas/AnalyticsFormat', $parameter['schema']['$ref']);

        $kode = $this->nilaiDiTabel($this->bagianPanduan($panduan, 'Kode galat'), 'analytics\.[a-z_]+');
        $this->assertNotEmpty($kode);
        $model = $spesifikasi['components']['schemas']['AnalyticsErrorCode'];
        $this->assertSame($kode, $model['examples']);
        $this->assertSame($kode, $this->nilaiDiTabel($model['description'], 'analytics\.[a-z_]+'));

        // Setiap kode yang dapat dikirim galat publikasi ada di daftar.
        preg_match_all("/'(analytics\.[a-z_]+)'/", (string) file_get_contents(app_path('Platform/Analytics/External/PublicationErrors.php')), $dikirim);
        foreach (array_unique($dikirim[1]) as $satu) {
            $this->assertContains($satu, $kode, "Kode {$satu} dikirim kode tetapi tidak ada di daftar Kode galat.");
        }
    }

    public function test_judul_panduan_tanpa_kode_supaya_hasil_pencarian_menuju_judulnya(): void
    {
        // Scalar membuat alamat hasil pencarian dari seluruh teks judul, tetapi membuang bagian
        // kode saat memberi id pada judul yang tampil. Judul "Jenis posting (`posting_type`)"
        // membuat hasil pencarian menunjuk `jenis-posting-posting-type`, sedangkan judulnya ber-id
        // `jenis-posting`, sehingga pembaca yang mengkliknya tidak dibawa ke mana pun. Garis bawah
        // tanpa backtick aman; keduanya diuji di Scalar pada 22 September 2026.
        $berkas = glob(base_path('contracts/terbit/*.yaml')) ?: [];
        $this->assertNotEmpty($berkas);

        foreach ($berkas as $satu) {
            $panduan = (string) (Yaml::parseFile($satu)['info']['description'] ?? '');
            $tanpaBlokKode = (string) preg_replace('/^```.*?^```/ms', '', $panduan);
            preg_match_all('/^#{1,6} .*$/m', $tanpaBlokKode, $judul);

            foreach ($judul[0] as $baris) {
                $this->assertStringNotContainsString('`', $baris, basename($satu).": judul \"{$baris}\" memuat kode.");
            }
        }
    }

    public function test_kontrak_internal_dan_referensi_scramble_tertutup_bagi_tamu_dan_anggota_biasa(): void
    {
        foreach (['/docs/kontrak/app.yaml', '/docs/kontrak/pusat-admin.yaml', '/docs/api', '/docs/api.json'] as $alamat) {
            $this->get($alamat)->assertForbidden();
        }
        $this->get('/docs/kontrak/tidak-ada.yaml')->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->get('/docs/kontrak/app.yaml')->assertForbidden();
        $this->get('/docs')->assertOk()->assertDontSee('App module (internal)', false);
    }

    public function test_admin_penyedia_melihat_semua_spesifikasi_termasuk_kontrak_app_di_katalog(): void
    {
        $this->seed(AppCatalogSeeder::class);
        $this->actingAs($this->adminPenyedia());

        $this->get('/docs')->assertOk()
            ->assertSee('Integrasi · Finance', false)
            ->assertSee('App module (internal)', false)
            ->assertSee('Pusat admin (internal)', false)
            ->assertSee('Layar CoreERP (internal)', false)
            ->assertSee('App Uji', false);
        $this->get('/docs?spec=pusat-admin')->assertOk()->assertSee(route('docs.kontrak', 'pusat-admin'), false);
        $this->get('/docs/kontrak/app.yaml')->assertOk();
    }

    public function test_kontrak_app_di_katalog_diarahkan_ke_url_yang_didaftarkan_app(): void
    {
        // Kontrak dimiliki repository app, jadi portal mengarahkan ke URL yang didaftarkan app —
        // bukan menyajikan berkas dari repository platform ini.
        $this->seed(AppCatalogSeeder::class);
        $this->get('/docs/openapi/app-uji')->assertForbidden();

        $this->actingAs($this->adminPenyedia());
        $this->get('/docs/openapi/app-uji')->assertRedirect('https://contracts.example.test/app-uji/openapi.yaml');
        $this->get('/docs/openapi/not-an-app')->assertNotFound();
    }

    /** Isi satu bagian panduan, dari judulnya sampai judul setingkat berikutnya. */
    private function bagianPanduan(string $panduan, string $judul): string
    {
        $pembuka = "## {$judul}\n";
        $mulai = strpos($panduan, $pembuka);
        $this->assertNotFalse($mulai, "Panduan tidak punya bagian \"{$judul}\".");

        $sisa = substr($panduan, $mulai + strlen($pembuka));
        $akhir = strpos($sisa, "\n## ");

        return $akhir === false ? $sisa : substr($sisa, 0, $akhir);
    }

    /**
     * Nilai jenis posting di kolom pertama tabel markdown, sesuai urutannya. Baris judul dan contoh
     * pemakaian seperti `posting_type=asset.*` tidak berbentuk jenis, jadi tidak ikut.
     *
     * @return list<string>
     */
    private function jenisDiTabel(string $markdown): array
    {
        preg_match_all('/^\| `([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)` \|/m', $markdown, $cocok);

        return $cocok[1];
    }

    /**
     * Nilai berbentuk `$pola` di kolom pertama tabel markdown, sesuai urutannya.
     *
     * @return list<string>
     */
    private function nilaiDiTabel(string $markdown, string $pola): array
    {
        preg_match_all('/^\| `('.$pola.')` \|/m', $markdown, $cocok);

        return $cocok[1];
    }

    private function adminPenyedia(): User
    {
        $penyedia = User::factory()->create();
        ProviderAccess::query()->create(['user_id' => $penyedia->id, 'role' => 'provider_admin']);

        return $penyedia;
    }
}
