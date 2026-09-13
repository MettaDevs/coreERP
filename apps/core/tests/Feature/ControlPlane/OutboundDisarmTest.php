<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Environment;
use App\Models\Tenant;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\ControlPlane\OutboundRefused;
use App\Support\Modules\TenantScope;
use App\Support\Observabilitas\LaporanKesalahan;
use App\Support\Observabilitas\PengirimDiscord;
use App\Support\Reporting\Rendering\PdfConverter;
use App\Support\Reporting\Rendering\RenderedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Salinan produksi tidak dapat menghubungi pihak yang sebenarnya — dan produksi tetap bisa.
 *
 * Tiap penjagaan di sini muncul **dua kali**: sekali membuktikan ia menolak ketika
 * `outbound_allowed` mati, sekali membuktikan ia melepaskan ketika lingkungannya produksi.
 * Yang kedua bukan pelengkap. Penjaga yang hijau karena buta — karena ia menolak segalanya,
 * atau karena jalurnya tidak pernah benar-benar dijalankan — sudah dua kali membakar repo
 * ini, dan ia terlihat persis sama dengan penjaga yang bekerja selama hanya sisi merahnya
 * yang diuji.
 *
 * Satu test di sini menjaga hal yang berlawanan arah dengan sisanya: perender PDF **wajib
 * tetap berhasil** di lingkungan yang sambungan keluarnya sudah dimatikan. Ia ada supaya
 * pengecualian itu tidak diam-diam "dilengkapi" oleh orang berikutnya yang membaca daftar
 * pelucutan.
 */
class OutboundDisarmTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://discord.test/api/webhooks/1/rahasia';

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearThrottleMarker();

        $client = Client::create(['legal_name' => 'PT Uji', 'slug' => 'pt-uji', 'status' => 'active']);
        $this->tenantId = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji',
            'slug' => 'pt-uji',
            'status' => 'active',
        ])->id;

        config()->set('coreerp.app_context_signing_key', 'kunci-uji');
        config()->set('coreerp.event_endpoints', [[
            'type' => 'core.workflow.decision.v2',
            'url' => 'https://procurement.test/events',
            'module' => 'procurement',
        ]]);
        config()->set('coreerp.discord.webhook_url', self::WEBHOOK);
        config()->set('coreerp.discord.mention', '@here');
        config()->set('coreerp.signoz_url', '');
        config()->set('reporting.renderer_url', 'http://core-renderer:3000');
    }

    protected function tearDown(): void
    {
        $this->clearThrottleMarker();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------
    // Penerbit event workflow — bahaya paling konkret yang sudah ada di repo hari ini.
    // ---------------------------------------------------------------------------------

    public function test_the_event_publisher_does_not_send_from_a_test_environment(): void
    {
        $this->activate($this->sandbox());
        $id = $this->outbox();
        Http::fake();

        Artisan::call('workflow-events:publish');

        Http::assertNothingSent();
        $this->assertNotNull(
            DB::table('outbox_events')->where('id', $id)->value('published_at'),
            'Barisnya dibiarkan menggantung. Perintah ini berjalan di penjadwal, jadi baris yang '
            .'tidak pernah ditandai akan diambil ulang selamanya dan menutupi baris yang benar-benar '
            .'gagal terkirim.',
        );
        $this->assertStringContainsString('ditandai terbit tanpa dikirim', Artisan::output());
    }

    public function test_the_event_publisher_still_sends_from_production(): void
    {
        $this->activate($this->production());
        $id = $this->outbox();
        Http::fake(['https://procurement.test/events' => Http::response(['data' => ['accepted' => true]])]);

        Artisan::call('workflow-events:publish');

        Http::assertSent(fn ($request) => $request->url() === 'https://procurement.test/events'
            && $request->hasHeader('X-CoreERP-Event-Signature')
            && $request['id'] === $id);
        $this->assertNotNull(DB::table('outbox_events')->where('id', $id)->value('published_at'));
    }

    // ---------------------------------------------------------------------------------
    // Laporan kesalahan ke Discord.
    // ---------------------------------------------------------------------------------

    /**
     * Ditekan oleh penjagaan miliknya sendiri, bukan oleh jaring global.
     *
     * Keduanya menghasilkan "tidak ada yang terkirim", jadi assertion itu saja tidak dapat
     * membedakannya — dan penjaga yang tidak dapat dibedakan dari penjaga lain tidak dapat
     * dibuktikan bekerja. Yang membedakannya penjeda kiriman: ia menulis penanda begitu
     * sebuah laporan diluluskan. Penanda yang tetap kosong berarti pengiriman berhenti
     * **sebelum** penjeda, yaitu di dalam kelas ini, jauh di hulu jaring global.
     */
    public function test_the_discord_report_is_suppressed_in_a_test_environment(): void
    {
        config()->set('coreerp.discord.jeda_detik', 60);
        $this->activate($this->sandbox());
        Http::fake();

        PengirimDiscord::kirim(LaporanKesalahan::dari(new RuntimeException('gagal'), null));

        Http::assertNothingSent();
        $this->assertSame([], $this->throttleMarker(), 'Penjeda sudah tersentuh, jadi yang menahan '
            .'kiriman ini bukan penjagaan di dalam PengirimDiscord melainkan sesuatu di hilirnya.');
    }

    public function test_the_discord_report_is_still_sent_from_production(): void
    {
        config()->set('coreerp.discord.jeda_detik', 0);
        $this->activate($this->production());
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        PengirimDiscord::kirim(LaporanKesalahan::dari(new RuntimeException('gagal'), null));

        Http::assertSent(fn ($request) => $request->url() === self::WEBHOOK);
    }

    // ---------------------------------------------------------------------------------
    // Jaring global — lapis terakhir untuk panggilan yang belum ditulis siapa pun.
    // ---------------------------------------------------------------------------------

    public function test_the_global_guard_refuses_a_call_nobody_guards(): void
    {
        $this->activate($this->sandbox());
        Http::fake();

        try {
            Http::get('https://sistem-pelanggan.test/webhook?token=rahasia');
            $this->fail('Panggilan keluar dari lingkungan uji lolos tanpa suara.');
        } catch (OutboundRefused $rejected) {
            $this->assertStringContainsString('bukan produksi', $rejected->getMessage());
            $this->assertStringContainsString('sistem-pelanggan.test', $rejected->getMessage());
            $this->assertStringNotContainsString('rahasia', $rejected->getMessage(), 'Query string '
                .'ikut tercetak ke pesan galat, dan pesan galat dibaca lebih banyak orang daripada '
                .'permintaannya sendiri.');
        }

        Http::assertNothingSent();
    }

    /**
     * Jaring yang sama diturunkan dari tenant aktif, bukan hanya dari ikatan eksplisit.
     *
     * Jalur inilah yang berlaku pada permintaan HTTP biasa hari ini — tidak ada yang mengikat
     * id environment di sana — jadi jaring yang hanya terbukti lewat ikatan eksplisit belum
     * terbukti pada jalur yang sebenarnya ditempuh pengguna.
     */
    public function test_the_global_guard_recognises_the_environment_through_the_active_tenant(): void
    {
        $this->sandbox();
        $this->app->forgetInstance(ActiveEnvironment::class);
        $this->app->instance(TenantScope::KUNCI, $this->tenantId);
        Http::fake();

        $this->expectException(OutboundRefused::class);
        Http::get('https://sistem-pelanggan.test/webhook');
    }

    public function test_the_global_guard_lets_a_call_from_production_through(): void
    {
        $this->activate($this->production());
        Http::fake(['https://sistem-pelanggan.test/*' => Http::response(['ok' => true])]);

        $jawaban = Http::get('https://sistem-pelanggan.test/webhook');

        $this->assertTrue($jawaban->successful());
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------------------------
    // Perender PDF — yang sengaja TIDAK dijaga.
    // ---------------------------------------------------------------------------------

    /**
     * Gotenberg tetap terhubung di lingkungan yang sambungan keluarnya sudah mati.
     *
     * Test ini berjalan berlawanan arah dengan sisanya, dan ia disengaja: ia merah pada hari
     * seseorang "melengkapi" daftar pelucutan dengan menambahkan penjagaan di `PdfConverter`
     * atau membuang pengecualiannya dari jaring global. Mematikan pencetakan di setiap sandbox
     * dan setiap demo adalah harga yang tidak dibayar oleh keamanan siapa pun — perendernya
     * tidak mengenal tenant dan tidak menyimpan apa pun.
     */
    public function test_the_pdf_renderer_may_still_be_reached_from_a_test_environment(): void
    {
        $this->activate($this->sandbox());
        Http::fake([
            'core-renderer:3000/*' => Http::response('%PDF-1.4 palsu', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $source = (string) tempnam(sys_get_temp_dir(), 'uji-');
        file_put_contents($source, 'dokumen');

        $result = (new PdfConverter)->convert(new RenderedFile($source, 'docx'));

        $this->assertSame('pdf', $result->format);
        $this->assertSame('%PDF-1.4 palsu', file_get_contents($result->localPath));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'core-renderer:3000/forms/libreoffice/convert'));

        $result->cleanup();
        @unlink($source);
    }

    // ---------------------------------------------------------------------------------

    private function production(): Environment
    {
        return $this->make('production', true);
    }

    private function sandbox(): Environment
    {
        return $this->make('sandbox', false);
    }

    /**
     * `outbound_allowed` tidak diterima sebagai parameter bebas dengan sengaja.
     *
     * Constraint `environments_keluar_ikut_jenis` mengikat nilainya pada jenis environment,
     * jadi kombinasi lain memang tidak dapat lahir. Menuliskannya sebagai dua pembuat yang
     * berbeda membuat test di atas gagal saat disusun, bukan saat dijalankan.
     */
    private function make(string $kind, bool $outboundAllowed): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenantId,
            'kind' => $kind,
            'name' => 'Uji '.$kind,
            'slug' => 'uji-'.Str::lower(Str::random(6)),
            'database_name' => null,
            'status' => 'active',
            'outbound_allowed' => $outboundAllowed,
        ]);
    }

    /**
     * Mengikat environment yang sedang dikerjakan, seperti yang kelak dilakukan perintah
     * artisan, job antrean, dan scheduler.
     *
     * Instansnya dilupakan lebih dulu karena `ActiveEnvironment` memoisasi jawabannya: tanpa itu
     * test yang kebetulan sudah menyentuhnya akan terus membaca jawaban lama.
     */
    private function activate(Environment $environment): void
    {
        $this->app->forgetInstance(ActiveEnvironment::class);
        $this->app->instance(ActiveEnvironment::KEY, $environment->id);
    }

    private function outbox(): string
    {
        $id = (string) Str::ulid();

        DB::table('outbox_events')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'correlation_id' => (string) Str::ulid(),
            'type' => 'core.workflow.decision.v2',
            'payload' => json_encode(['decision' => 'approved'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return list<string> */
    private function throttleMarker(): array
    {
        return array_values(glob(storage_path('logs/.penjeda-kiriman').'/*') ?: []);
    }

    private function clearThrottleMarker(): void
    {
        foreach ($this->throttleMarker() as $file) {
            @unlink($file);
        }
    }
}
