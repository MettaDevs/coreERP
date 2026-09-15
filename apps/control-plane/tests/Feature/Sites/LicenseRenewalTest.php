<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use Carbon\CarbonImmutable;
use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Models\Site;
use ControlPlane\Sites\LicenseRenewal;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Perpanjangan lisensi lewat jawaban laporan agen.
 *
 * Jam dipaku pada 15 September 2026 pukul 08.00 UTC — pukul 15.00 di Jakarta, jadi "hari ini" sama di
 * kedua zona waktu dan batas sepuluh hari jatuh pada 25 September.
 */
class LicenseRenewalTest extends SiteTestCase
{
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 08:00:00', 'UTC'));
        $this->public = $this->useLicenseKey();
    }

    // ------------------------------------------------------------------ aturan jatuh tempo

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool}>
     */
    public static function renewalCases(): iterable
    {
        // [atribut situs, isi laporan yang diganti, jatuh tempo?]
        yield 'belum pernah ada lisensi' => [[], ['license_expires_at' => null], true];
        yield 'laporan tanpa bidang lisensi' => [[], ['license_expires_at' => '__HAPUS__'], true];
        yield 'sudah habis' => [[], ['license_expires_at' => '2026-09-14'], true];
        yield 'tepat sepuluh hari lagi' => [[], ['license_expires_at' => '2026-09-25'], true];
        yield 'masa masih jauh' => [[], ['license_expires_at' => '2026-09-26'], false];
        yield 'situs dicabut' => [['revoked_at' => '2026-09-01 00:00:00'], ['license_expires_at' => null], false];
        yield 'perpanjangan dihentikan' => [['license_suspended_at' => '2026-09-10 00:00:00'], ['license_expires_at' => null], false];
        yield 'baru diterbitkan 59 menit lalu' => [['license_issued_at' => '2026-09-15 07:01:00', 'license_valid_until' => '2026-10-15'], ['license_expires_at' => null], false];
        yield 'terakhir diterbitkan 61 menit lalu' => [['license_issued_at' => '2026-09-15 06:59:00', 'license_valid_until' => '2026-10-15'], ['license_expires_at' => null], true];
    }

    /**
     * @param  array<string, mixed>  $siteAttributes
     * @param  array<string, mixed>  $reportOverrides
     */
    #[DataProvider('renewalCases')]
    public function test_renewal_is_due_only_by_the_rules(array $siteAttributes, array $reportOverrides, bool $due): void
    {
        $site = $this->enrolledSite();
        $site->forceFill($siteAttributes)->save();

        $report = $this->report($site, $reportOverrides);

        if (($reportOverrides['license_expires_at'] ?? null) === '__HAPUS__') {
            unset($report['license_expires_at']);
        }

        $this->assertSame($due, app(LicenseRenewal::class)->due($site->refresh(), $report));
    }

    // ------------------------------------------------------------------ jawaban laporan

    public function test_the_report_answer_carries_a_license_only_when_due(): void
    {
        $site = $this->enrolledSite();
        $this->fakeEntitlements($site, ['human-resources']);

        // Masa masih jauh: jawaban persis seperti sebelum lisensi mengunci, dan Core tidak dipanggil.
        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => '2026-10-15']))
            ->assertOk()
            ->assertExactJson(['interval_seconds' => 60]);
        Http::assertNothingSent();

        $answer = $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => '2026-09-20']))
            ->assertOk()
            ->json();

        $this->assertSame(['interval_seconds', 'license'], array_keys($answer));
        $this->assertSame(['license', 'signature'], array_keys($answer['license']));
        // Base64 standar satu baris: agen menulisnya apa adanya ke `license.json.sig`.
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9+/]+={0,2}$#', $answer['license']['signature']);
        $license = json_decode($answer['license']['license'], true);
        // Agen tidak memeriksa `tenant_id`, `issued_at`, maupun keunikan dan urutan `apps`; Core yang
        // membacanya. Jadi yang menjaganya di sisi ini test, bukan agen.
        $this->assertSame(['version', 'tenant_id', 'site_id', 'apps', 'valid_until', 'issued_at'], array_keys($license));
        $this->assertSame(2, $license['version']);
        $this->assertSame($site->tenant_id, $license['tenant_id']);
        $this->assertSame($site->id, $license['site_id']);
        $this->assertSame(['human-resources'], $license['apps']);
        $this->assertSame('2026-10-15', $license['valid_until']);
        $this->assertSame('2026-09-15T08:00:00Z', $license['issued_at']);
        $this->assertSame(1, openssl_verify($answer['license']['license'], (string) base64_decode($answer['license']['signature'], true), $this->public, OPENSSL_ALGO_SHA256));

        // Laporan berikutnya masih membawa tanggal lama — agen belum memasangnya. Jeda menahan lisensi
        // kedua.
        $this->travel(1)->minutes();
        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => '2026-09-20']))
            ->assertOk()
            ->assertExactJson(['interval_seconds' => 60]);

        Http::assertSentCount(1);
        $this->assertSame(1, OperatorAuditEvent::query()->where('action', 'site.license.renewed')->count());
    }

    /**
     * Perpanjangan otomatis tidak pernah tercatat atas nama siapa pun — juga ketika penjaga autentikasi
     * kebetulan mengenali seseorang di permintaan yang sama.
     */
    public function test_a_renewal_is_audited_without_a_user(): void
    {
        $site = $this->enrolledSite();
        $this->fakeEntitlements($site);
        $this->actingAs($this->operator());

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => null]))
            ->assertOk()
            ->assertJsonStructure(['interval_seconds', 'license' => ['license', 'signature']]);

        $event = OperatorAuditEvent::query()->sole();
        $this->assertSame('site.license.renewed', $event->action);
        $this->assertNull($event->user_id);
    }

    public function test_core_failing_still_accepts_the_report_and_retries_after_a_pause(): void
    {
        $site = $this->enrolledSite();
        Http::fake([
            $this->entitlementsUrl($site) => Http::sequence()
                ->push(['message' => 'rusak'], 500)
                ->push(['tenant_id' => $site->tenant_id, 'apps' => ['human-resources']]),
        ]);

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => null]))
            ->assertOk()
            ->assertExactJson(['interval_seconds' => 60]);

        $site->refresh();
        $this->assertNotNull($site->last_seen_at);
        $this->assertNull($site->license_issued_at);
        $this->assertSame(0, OperatorAuditEvent::query()->count());

        // Satu menit kemudian Core tidak dipanggil lagi.
        $this->travel(1)->minutes();
        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => null]))
            ->assertOk()
            ->assertExactJson(['interval_seconds' => 60]);
        Http::assertSentCount(1);

        // Sesudah jedanya lewat, dicoba lagi.
        $this->travel(60)->minutes();
        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => null]))
            ->assertOk()
            ->assertJsonStructure(['interval_seconds', 'license' => ['license', 'signature']]);
        Http::assertSentCount(2);
    }

    public function test_a_console_without_a_license_key_still_accepts_the_report(): void
    {
        config(['sites.license_private_key_path' => null]);
        $site = $this->enrolledSite();
        $this->fakeEntitlements($site);

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => null]))
            ->assertOk()
            ->assertExactJson(['interval_seconds' => 60]);

        Http::assertNothingSent();
    }

    /** Cacat di jalur perpanjangan sendiri pun tidak menggagalkan laporan yang sudah tercatat. */
    public function test_an_unexpected_failure_while_renewing_still_accepts_the_report(): void
    {
        $site = $this->enrolledSite();
        Http::fake([$this->entitlementsUrl($site) => fn () => throw new RuntimeException('cacat tak terduga')]);

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => null]))
            ->assertOk()
            ->assertExactJson(['interval_seconds' => 60]);

        $this->assertNotNull($site->refresh()->last_seen_at);
        $this->assertNull($site->license_issued_at);
    }

    public function test_a_suspended_site_reporting_an_expired_license_gets_none(): void
    {
        $site = $this->enrolledSite();
        $site->forceFill(['license_suspended_at' => now()])->save();
        $this->fakeEntitlements($site);

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['license_expires_at' => '2026-09-01']))
            ->assertOk()
            ->assertExactJson(['interval_seconds' => 60]);

        Http::assertNothingSent();
        $this->assertNull(Site::query()->findOrFail($site->id)->license_issued_at);
    }
}
