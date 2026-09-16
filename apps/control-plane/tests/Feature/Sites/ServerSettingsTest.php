<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Models\Site;
use ControlPlane\Models\User;
use ControlPlane\Sites\ServerAddress;
use ControlPlane\Sites\SiteReports;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Alamat server klien dan setelannya: bentuk yang diterima, dua pintu yang menyimpannya, dan IP yang dicatat
 * dari laporan agen.
 */
final class ServerSettingsTest extends SiteTestCase
{
    // ------------------------------------------------------------------ bentuk alamat

    /** @return iterable<string, array{string}> */
    public static function acceptedAddresses(): iterable
    {
        yield 'IPv4' => ['103.122.2.72'];
        yield 'IPv6' => ['2001:db8::1'];
        yield 'nama host' => ['vps1.klinik.id'];
        yield 'subdomain bertanda hubung' => ['srv-01.cabang-utara.klinik.co.id'];
    }

    /** @return iterable<string, array{string}> */
    public static function refusedAddresses(): iterable
    {
        yield 'alamat aplikasi' => ['https://103.122.2.72'];
        yield 'dengan port' => ['103.122.2.72:22'];
        yield 'dengan jalur' => ['vps1.klinik.id/admin'];
        yield 'dengan pengguna' => ['root@103.122.2.72'];
        yield 'spasi di tengah' => ['vps1 klinik.id'];
        yield 'satu label saja' => ['vps1'];
        yield 'IPv4 di luar jangkauan' => ['999.1.1.1'];
        yield 'label diawali tanda hubung' => ['-vps.klinik.id'];
    }

    #[DataProvider('acceptedAddresses')]
    public function test_an_ip_or_host_name_is_accepted(string $address): void
    {
        $this->assertTrue(ServerAddress::valid($address));
    }

    #[DataProvider('refusedAddresses')]
    public function test_anything_that_is_not_just_a_machine_address_is_refused(string $address): void
    {
        $this->assertFalse(ServerAddress::valid((string) ServerAddress::normalize($address)));
    }

    public function test_the_address_is_tidied_before_it_is_checked_and_stored(): void
    {
        $operator = $this->operator();
        $environment = $this->clientServerEnvironment($this->tenant());

        $this->actingAs($operator)
            ->post("/lingkungan/{$environment->id}/server-klien", ['server_address' => '  VPS1.Klinik.ID '])
            ->assertSessionHasNoErrors();

        $site = Site::query()->sole();
        $this->assertSame('vps1.klinik.id', $site->server_address);
        $this->assertSame('vps1.klinik.id', OperatorAuditEvent::query()->where('action', 'site.created')->sole()->detail['server_address']);
    }

    /** Kalimat penolakannya menyebut bentuk yang benar, bukan hanya "tidak sah". */
    public function test_an_application_address_in_the_server_field_is_refused_with_the_right_shape(): void
    {
        $environment = $this->clientServerEnvironment($this->tenant());

        $this->actingAs($this->operator())
            ->post("/lingkungan/{$environment->id}/server-klien", ['server_address' => 'https://103.122.2.72/'])
            ->assertSessionHasErrors(['server_address' => ServerAddress::MESSAGE]);

        $this->assertSame(0, Site::query()->count());
    }

    /**
     * CHECK `sites_alamat_server_polos` menahan bentuk yang pasti salah dari penulis selain konsol ini. Satu
     * baris sah di awal membuktikan penolakannya bukan karena kolomnya ditolak seluruhnya.
     */
    public function test_the_database_refuses_a_non_plain_server_address_from_any_writer(): void
    {
        $site = $this->site();

        DB::table('sites')->where('id', $site->id)->update(['server_address' => '103.122.2.72']);
        $this->assertSame('103.122.2.72', $site->refresh()->server_address);

        foreach (['VPS1.KLINIK.ID', 'https://vps1.klinik.id', 'vps1 klinik.id', '', 'root@vps1.klinik.id'] as $address) {
            try {
                DB::transaction(fn () => DB::table('sites')->where('id', $site->id)->update(['server_address' => $address]));
                $this->fail("Alamat '{$address}' seharusnya ditolak database.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('sites_alamat_server_polos', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ dua pintu penyimpan

    /** Halaman rincian server klien menyimpan setelan yang sama dengan panel, dengan aturan dan jejak audit yang sama. */
    public function test_the_site_page_saves_the_settings_and_audits_before_and_after(): void
    {
        $operator = $this->operator();
        $environment = $this->clientServerEnvironment($this->tenant());
        $this->actingAs($operator)->post("/lingkungan/{$environment->id}/server-klien", ['server_address' => '10.0.0.5'])->assertSessionHasNoErrors();
        $site = Site::query()->sole();

        $this->actingAs($operator)
            ->patch("/situs/{$site->id}/setelan", ['server_address' => '103.122.2.72:22'])
            ->assertSessionHasErrors('server_address');

        $this->actingAs($operator)
            ->patch("/situs/{$site->id}/setelan", [
                'server_address' => '103.122.2.72',
                'address' => 'https://erp.klinik-sendiri.test',
                'update_window_start' => '22:00',
                'update_window_end' => '04:00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/situs/{$site->id}");

        $site->refresh();
        $this->assertSame('103.122.2.72', $site->server_address);
        // Alamat aplikasi milik klien belum didukung; isiannya diabaikan.
        $this->assertNull($site->address);
        $this->assertSame(['start' => '22:00', 'end' => '04:00', 'timezone' => 'Asia/Jakarta'], $site->updateWindow());

        $updated = OperatorAuditEvent::query()->where('action', 'site.settings.updated')->sole();
        $this->assertSame('10.0.0.5', $updated->detail['before']['server_address']);
        $this->assertSame('103.122.2.72', $updated->detail['after']['server_address']);

        // Panel di halaman lingkungan membaca dan menyimpan kolom yang sama.
        $this->actingAs($operator)->get("/lingkungan/{$environment->id}")
            ->assertInertia(fn ($page) => $page->where('serverClient.site.serverAddress', '103.122.2.72'));

        $this->actingAs($operator)
            ->patch("/lingkungan/{$environment->id}/server-klien", ['server_address' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($site->refresh()->server_address);
    }

    /** Situs lama tanpa lingkungan tidak punya panel, dan justru karena itu halaman rinciannya harus dapat menyimpan. */
    public function test_a_legacy_site_without_an_environment_can_record_its_address(): void
    {
        $site = $this->enrolledSite();

        $this->actingAs($this->operator())
            ->patch("/situs/{$site->id}/setelan", ['server_address' => 'server-lama.klinik.id'])
            ->assertSessionHasNoErrors();

        $this->assertSame('server-lama.klinik.id', $site->refresh()->server_address);
    }

    public function test_a_revoked_site_keeps_its_settings(): void
    {
        $site = $this->enrolledSite(0, ['server_address' => '10.0.0.9', 'revoked_at' => now()]);

        $this->actingAs($this->operator())
            ->patch("/situs/{$site->id}/setelan", ['server_address' => '10.0.0.10'])
            ->assertSessionHasErrors(['settings' => 'Situs ini sudah dicabut.']);

        $this->assertSame('10.0.0.9', $site->refresh()->server_address);
        $this->assertSame(0, OperatorAuditEvent::query()->count());
    }

    public function test_non_operators_cannot_change_the_settings(): void
    {
        $site = $this->enrolledSite(0, ['server_address' => '10.0.0.9']);
        $id = DB::table('users')->insertGetId([
            'name' => 'Bukan Operator', 'email' => 'biasa@contoh.test', 'password' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::query()->findOrFail($id))
            ->patch("/situs/{$site->id}/setelan", ['server_address' => '10.0.0.10'])
            ->assertNotFound();

        $this->assertSame('10.0.0.9', $site->refresh()->server_address);
    }

    // ------------------------------------------------------------------ IP dari laporan agen

    public function test_a_report_records_where_the_agent_reported_from_without_touching_the_recorded_address(): void
    {
        $site = $this->enrolledSite(0, ['server_address' => 'vps1.klinik.id']);

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site))->assertOk();

        $site->refresh();
        // Permintaan test datang dari REMOTE_ADDR bawaan Symfony.
        $this->assertSame('127.0.0.1', $site->last_seen_ip);
        $this->assertSame('vps1.klinik.id', $site->server_address);
    }

    /** Nilai yang bukan IP tidak tersimpan dan tidak menggagalkan laporan; IP sebelumnya dipertahankan. */
    public function test_a_malformed_origin_keeps_the_previous_ip(): void
    {
        $site = $this->enrolledSite();
        $site->forceFill(['last_seen_ip' => '36.72.1.9'])->save();

        app(SiteReports::class)->record($site, $this->report($site), 'bukan-alamat');

        $this->assertSame('36.72.1.9', $site->refresh()->last_seen_ip);
        $this->assertNotNull($site->last_seen_at);
    }
}
