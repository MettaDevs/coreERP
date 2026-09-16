<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Dns\CloudflareSettings;
use ControlPlane\Models\Environment;
use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteEnrollmentToken;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Sites\SiteDns;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Alamat aplikasi otomatis server klien dan record DNS-nya di Cloudflare (`SiteDns`).
 */
final class SiteDnsTest extends SiteTestCase
{
    private FakeCloudflare $cloudflare;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://admin.contoh.test']);
        $this->useLicenseKey();
        $this->cloudflare = $this->useCloudflare();
    }

    // ------------------------------------------------------------------ perintah pasang membuat record

    public function test_the_install_command_points_the_tenant_address_at_the_server(): void
    {
        [$environment, $site] = $this->installable('103.122.2.72');
        $host = $this->hostOf($environment);

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasNoErrors();

        $records = $this->cloudflare->named($host);
        $this->assertCount(1, $records);
        $this->assertSame('A', $records[0]['type']);
        $this->assertSame('103.122.2.72', $records[0]['content']);
        // Tidak lewat proxy Cloudflare: sertifikatnya diambil proxy di server klien, dan klinik tidak boleh
        // bergantung pada jaringan kita.
        $this->assertFalse($records[0]['proxied']);
        $this->assertSame(1, $records[0]['ttl']);
        $this->assertSame('coreerp-site:'.$site->id, $records[0]['comment']);

        $site->refresh();
        $this->assertSame($records[0]['id'], $site->dns_record_id);
        $this->assertSame($host, $site->dns_name);
        $this->assertSame('103.122.2.72', $site->dns_target);

        $this->assertSame('https://'.$host, SiteOperation::query()->sole()->parameters['app_url']);

        $written = OperatorAuditEvent::query()->where('action', 'site.dns.written')->sole();
        $this->assertSame(['name' => $host, 'type' => 'A', 'target' => '103.122.2.72'], $written->detail['after']);
    }

    /** Perintah pasang yang dibuat ulang tidak menulis ulang record yang sudah benar. */
    public function test_a_repeated_install_command_leaves_a_correct_record_alone(): void
    {
        [$environment] = $this->installable('103.122.2.72');
        $operator = $this->operator();

        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();
        $this->cloudflare->calls = [];
        $this->travel(1)->seconds();
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();

        $this->assertSame([], array_values(array_filter($this->cloudflare->calls, fn (string $call): bool => ! str_starts_with($call, 'GET '))));
        $this->assertCount(1, $this->cloudflare->named($this->hostOf($environment)));
        $this->assertSame(1, OperatorAuditEvent::query()->where('action', 'site.dns.written')->count());
    }

    public function test_without_a_server_address_nothing_is_created_and_cloudflare_is_not_asked(): void
    {
        [$environment] = $this->installable(null);

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors('server_client');

        $this->assertStringContainsString('Catat alamat server', (string) session('errors')->first('server_client'));
        $this->assertNothingIssued();
        $this->assertSame([], $this->cloudflare->calls);
    }

    public function test_without_a_cloudflare_token_nothing_is_created(): void
    {
        DB::table('console_settings')->where('key', CloudflareSettings::TOKEN)->delete();
        [$environment] = $this->installable('103.122.2.72');

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors('server_client');

        $this->assertStringContainsString('dns:token-cloudflare', (string) session('errors')->first('server_client'));
        $this->assertNothingIssued();
    }

    /** Alamat `http` atau berport — setelan pengembangan lokal — tidak pernah sampai ke agen. */
    public function test_an_address_the_agent_would_refuse_is_refused_here_first(): void
    {
        [$environment] = $this->installable('103.122.2.72');
        config(['core.address_scheme' => 'http', 'core.address_port' => '8000']);

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors('server_client');

        $this->assertStringContainsString('https tanpa port', (string) session('errors')->first('server_client'));
        $this->assertNothingIssued();
        $this->assertSame([], $this->cloudflare->records);
    }

    /**
     * Record dengan nama yang sama yang tidak dibuat admin.erp — misalnya dibuat dengan tangan untuk layanan lain —
     * tidak pernah ditimpa. Perintah pasang ditolak dengan menyebut isinya.
     */
    public function test_someone_elses_record_is_never_overwritten(): void
    {
        [$environment] = $this->installable('103.122.2.72');
        $host = $this->hostOf($environment);
        $this->cloudflare->foreignRecord($host, 'A', '198.51.100.7');

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/perintah-pasang")
            ->assertSessionHasErrors('server_client');

        $this->assertStringContainsString('bukan buatan admin.erp (A → 198.51.100.7)', (string) session('errors')->first('server_client'));
        $this->assertNothingIssued();
        $this->assertSame([['198.51.100.7', null]], array_map(fn (array $r): array => [$r['content'], $r['comment']], $this->cloudflare->named($host)));
    }

    // ------------------------------------------------------------------ jenis record dan perubahan alamat

    /** @return iterable<string, array{string, string}> */
    public static function targets(): iterable
    {
        yield 'IPv4' => ['103.122.2.72', 'A'];
        yield 'IPv6' => ['2001:db8::25', 'AAAA'];
        yield 'nama host' => ['vps1.klinik.id', 'CNAME'];
    }

    #[DataProvider('targets')]
    public function test_the_record_type_follows_the_server_address(string $target, string $type): void
    {
        [$environment] = $this->installable($target);

        $this->actingAs($this->operator())->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();

        $this->assertSame([[$type, $target]], array_map(fn (array $r): array => [$r['type'], $r['content']], $this->cloudflare->named($this->hostOf($environment))));
    }

    /**
     * Alamat server yang berubah memindahkan record yang sama. Pergantian jenis — IP ke nama host — membuang record
     * lama lebih dulu: Cloudflare menolak CNAME yang berdampingan dengan A pada satu nama, dan tiruannya juga.
     */
    public function test_changing_the_server_address_moves_the_record(): void
    {
        [$environment, $site] = $this->installable('103.122.2.72');
        $host = $this->hostOf($environment);
        $operator = $this->operator();
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();
        $firstId = $site->refresh()->dns_record_id;

        $this->actingAs($operator)
            ->patch("/situs/{$site->id}/setelan", ['server_address' => '103.122.2.80'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', 'Setelan server klien disimpan.');

        $this->assertSame([[$firstId, 'A', '103.122.2.80']], array_map(fn (array $r): array => [$r['id'], $r['type'], $r['content']], $this->cloudflare->named($host)));
        $this->assertSame('103.122.2.80', $site->refresh()->dns_target);

        $this->actingAs($operator)
            ->patch("/lingkungan/{$environment->id}/server-klien", ['server_address' => 'vps1.klinik.id'])
            ->assertSessionHasNoErrors();

        $this->assertSame([['CNAME', 'vps1.klinik.id']], array_map(fn (array $r): array => [$r['type'], $r['content']], $this->cloudflare->named($host)));
        $this->assertSame('synced', SiteDns::state($site->refresh()));
    }

    /**
     * Cloudflare yang menolak tidak membatalkan setelan. Kalimatnya tampil, layar menandai record yang belum
     * mengikuti, dan "Sinkronkan DNS" menyelesaikannya begitu Cloudflare kembali.
     */
    public function test_a_failing_cloudflare_keeps_the_settings_and_marks_the_record_outdated(): void
    {
        [$environment, $site] = $this->installable('103.122.2.72');
        $operator = $this->operator();
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();

        $this->cloudflare->failWith = 500;

        $this->actingAs($operator)
            ->patch("/situs/{$site->id}/setelan", ['server_address' => '103.122.2.80'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', fn (string $message): bool => str_contains($message, 'record DNS belum mengikuti alamat baru'));

        $this->assertSame('103.122.2.80', $site->refresh()->server_address);
        $this->assertSame('103.122.2.72', $site->dns_target);

        $this->actingAs($operator)->get("/situs/{$site->id}")
            ->assertInertia(fn ($page) => $page->where('site.dns.state', 'outdated')->where('site.dns.target', '103.122.2.72'));

        $this->actingAs($operator)->post("/situs/{$site->id}/dns")->assertSessionHasErrors('dns');

        $this->cloudflare->failWith = null;

        $this->actingAs($operator)->post("/situs/{$site->id}/dns")->assertSessionHasNoErrors();

        $this->actingAs($operator)->get("/situs/{$site->id}")
            ->assertInertia(fn ($page) => $page->where('site.dns.state', 'synced')->where('site.dns.target', '103.122.2.80'));
    }

    // ------------------------------------------------------------------ pencabutan

    public function test_revoking_removes_only_our_record(): void
    {
        [$environment, $site] = $this->installable('103.122.2.72');
        $operator = $this->operator();
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();
        $this->cloudflare->foreignRecord('lain.erp.contoh.test');

        $this->actingAs($operator)->post("/situs/{$site->id}/cabut", ['confirm_name' => $site->name])->assertSessionHasNoErrors();

        $this->assertSame([], $this->cloudflare->named($this->hostOf($environment)));
        $this->assertCount(1, $this->cloudflare->named('lain.erp.contoh.test'));
        $this->assertNull($site->refresh()->dns_record_id);
        $this->assertNotNull($site->revoked_at);
        $this->assertSame(1, OperatorAuditEvent::query()->where('action', 'site.dns.deleted')->count());
    }

    /** Cloudflare yang menolak tidak membatalkan pencabutan; recordnya tetap tercatat untuk dihapus lagi. */
    public function test_a_failing_cloudflare_does_not_stop_a_revocation(): void
    {
        [$environment, $site] = $this->installable('103.122.2.72');
        $operator = $this->operator();
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/perintah-pasang")->assertSessionHasNoErrors();
        $this->cloudflare->failWith = 503;

        $this->actingAs($operator)
            ->post("/situs/{$site->id}/cabut", ['confirm_name' => $site->name])
            ->assertSessionHas('message', fn (string $message): bool => str_contains($message, 'belum terhapus'));

        $site->refresh();
        $this->assertNotNull($site->revoked_at);
        $this->assertNotNull($site->dns_record_id);
    }

    // ------------------------------------------------------------------ token dan pengaturan

    public function test_the_token_is_stored_only_when_cloudflare_shows_the_zone(): void
    {
        DB::table('console_settings')->where('key', CloudflareSettings::TOKEN)->delete();
        $path = (string) tempnam(sys_get_temp_dir(), 'token-cf-');

        try {
            file_put_contents($path, "token-salah-yang-cukup-panjang\n");
            $this->assertSame(1, Artisan::call('dns:token-cloudflare', ['--berkas' => $path]));
            $this->assertNull(app(CloudflareSettings::class)->token());

            file_put_contents($path, "bukan token\n");
            $this->assertSame(1, Artisan::call('dns:token-cloudflare', ['--berkas' => $path]));

            $this->cloudflare->token = 'token-uji-yang-benar-panjangnya';
            file_put_contents($path, "CLOUDFLARE_API_TOKEN='token-uji-yang-benar-panjangnya'\n");
            $pending = $this->artisan('dns:token-cloudflare', ['--berkas' => $path]);
            $this->assertInstanceOf(PendingCommand::class, $pending);
            // `run()` tegas: tanpanya perintah baru berjalan saat objeknya dilepas, sesudah `finally` membuang berkasnya.
            $pending
                ->expectsOutputToContain('melihat zona contoh.test')
                ->doesntExpectOutputToContain('token-uji-yang-benar-panjangnya')
                ->assertExitCode(0)
                ->run();
        } finally {
            @unlink($path);
        }

        $this->assertSame('token-uji-yang-benar-panjangnya', app(CloudflareSettings::class)->token());
        // Terenkripsi di tabel, bukan teks.
        $this->assertStringNotContainsString('token-uji', (string) DB::table('console_settings')->where('key', CloudflareSettings::TOKEN)->value('value'));
    }

    public function test_the_settings_page_says_whether_cloudflare_accepts_the_token(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->get('/pengaturan')
            ->assertInertia(fn ($page) => $page
                ->where('dns.baseDomain', 'erp.contoh.test')
                ->where('dns.configured', true)
                ->where('dns.check.ok', true)
                ->where('dns.check.zone', 'contoh.test'));

        $this->cloudflare->token = 'token-lain';

        $this->actingAs($operator)->get('/pengaturan')
            ->assertInertia(fn ($page) => $page
                ->where('dns.check.ok', false)
                ->where('dns.check.error', fn (string $error): bool => str_contains($error, 'menolak token')));

        DB::table('console_settings')->where('key', CloudflareSettings::TOKEN)->delete();

        $this->actingAs($operator)->get('/pengaturan')
            ->assertInertia(fn ($page) => $page->where('dns.configured', false)->where('dns.check', null));
    }

    public function test_the_database_keeps_the_dns_columns_together(): void
    {
        $site = $this->site();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sites_dns_berpasangan');

        DB::table('sites')->where('id', $site->id)->update(['dns_record_id' => 'rec-1']);
    }

    // ------------------------------------------------------------------ perkakas

    /** @return array{0: Environment, 1: Site} */
    private function installable(?string $serverAddress): array
    {
        $tenant = $this->tenant('PT Klinik Dns');
        $environment = $this->clientServerEnvironment($tenant);
        $site = $this->site([
            'tenant_id' => $tenant,
            'environment_id' => $environment->id,
            'name' => 'PT Klinik Dns — Produksi',
            'edition' => Site::SINGLE_IMAGE_EDITION,
            'server_address' => $serverAddress,
        ]);
        $this->owner($tenant);
        $this->fakeEntitlements($site);
        $this->release('1.0.0');

        return [$environment, $site];
    }

    private function hostOf(Environment $environment): string
    {
        return $environment->tenant?->slug.'.erp.contoh.test';
    }

    private function assertNothingIssued(): void
    {
        $this->assertSame(0, SiteEnrollmentToken::query()->count());
        $this->assertSame(0, SiteOperation::query()->count());
        $this->assertSame(0, OperatorAuditEvent::query()->where('action', 'site.install_command.issued')->count());
    }
}
