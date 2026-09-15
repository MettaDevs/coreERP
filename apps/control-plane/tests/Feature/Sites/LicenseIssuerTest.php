<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use Carbon\CarbonImmutable;
use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Sites\EntitlementsUnavailable;
use ControlPlane\Sites\LicenseIssuer;
use ControlPlane\Sites\SiteRejected;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Penerbit lisensi versi 2 dan pembaca daftar app dari Core.
 *
 * Bentuk jawaban Core di sini dipalsukan menurut kontrak di `docs/todo/lisensi-mengunci/README.md`
 * (`{"tenant_id", "apps"}`). Byte lisensinya dibandingkan utuh, bukan per bidang: yang ditandatangani
 * adalah byte itu, dan urutan kunci yang bergeser tetap lisensi yang berbeda bagi pembacanya.
 */
class LicenseIssuerTest extends SiteTestCase
{
    public function test_a_license_is_version_2_with_the_apps_from_core_and_a_valid_signature(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 08:00:00', 'UTC'));
        $public = $this->useLicenseKey();
        $site = $this->enrolledSite();
        // Tidak terurut dan kembar: lisensi tetap unik dan terurut.
        $this->fakeEntitlements($site, ['management-aset', 'human-resources', 'management-aset']);

        $issued = app(LicenseIssuer::class)->renew($site, '10.0.0.5');

        $this->assertSame(
            '{"version":2,"tenant_id":"'.$site->tenant_id.'","site_id":"'.$site->id.'",'
            .'"apps":["human-resources","management-aset"],"valid_until":"2026-10-15","issued_at":"2026-09-15T08:00:00Z"}',
            $issued['license'],
        );

        $signature = base64_decode($issued['signature'], true);
        $this->assertIsString($signature);
        $this->assertSame(1, openssl_verify($issued['license'], $signature, $public, OPENSSL_ALGO_SHA256));
        $this->assertSame(0, openssl_verify($issued['license'].' ', $signature, $public, OPENSSL_ALGO_SHA256));

        Http::assertSent(fn (OutboundRequest $request): bool => $request->method() === 'GET'
            && $request->url() === $this->entitlementsUrl($site)
            && $request->hasHeader('Authorization', 'Bearer kunci-uji'));

        $site->refresh();
        $this->assertSame('2026-09-15 08:00:00', $site->license_issued_at?->toDateTimeString());
        $this->assertSame('2026-10-15', $site->license_valid_until?->toDateString());

        $event = OperatorAuditEvent::query()->sole();
        $this->assertSame('site.license.renewed', $event->action);
        $this->assertNull($event->user_id);
        $this->assertSame('10.0.0.5', $event->ip_address);
        $this->assertSame($site->id, $event->subject_id);
        $this->assertSame(['human-resources', 'management-aset'], $event->detail['apps']);
        $this->assertSame('2026-10-15', $event->detail['valid_until']);
        $this->assertStringNotContainsString($issued['signature'], (string) json_encode($event->detail));
    }

    public function test_a_tenant_without_apps_gets_a_core_only_license(): void
    {
        $this->useLicenseKey();
        $site = $this->enrolledSite();
        $this->fakeEntitlements($site, []);

        $license = app(LicenseIssuer::class)->renew($site)['license'];

        $this->assertStringContainsString('"apps":[]', $license);
    }

    /**
     * "Hari ini" adalah hari di klinik. Pukul 20.00 UTC sudah pukul 03.00 keesokan harinya di Jakarta.
     */
    public function test_the_default_validity_counts_from_today_in_the_sites_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 20:00:00', 'UTC'));
        $this->useLicenseKey();
        $site = $this->enrolledSite();
        $this->fakeEntitlements($site);

        $license = json_decode(app(LicenseIssuer::class)->renew($site)['license'], true);

        $this->assertSame('2026-10-16', $license['valid_until']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableCoreAnswers(): iterable
    {
        yield 'sambungan putus' => ['putus'];
        yield 'galat server' => [[500, ['message' => 'rusak']]];
        yield 'kunci ditolak' => [[401, ['message' => 'Unauthenticated.']]];
        yield 'berhasil tanpa isi' => [[204, null]];
        // Bentuknya benar, statusnya bukan 200. Hanya penjaga status yang dapat menolaknya.
        yield 'diterima, belum selesai' => [[202, ['tenant_id' => '__TENANT__', 'apps' => ['human-resources']]]];
        yield 'tanpa apps' => [[200, ['tenant_id' => '__TENANT__']]];
        yield 'apps bukan daftar' => [[200, ['tenant_id' => '__TENANT__', 'apps' => ['a' => 'human-resources']]]];
        yield 'anggota bukan teks' => [[200, ['tenant_id' => '__TENANT__', 'apps' => ['human-resources', 7]]]];
        yield 'anggota kosong' => [[200, ['tenant_id' => '__TENANT__', 'apps' => ['']]]];
        // Ditolak agen; lebih baik ditolak di sini, tempat sebabnya tercatat.
        yield 'id app huruf besar' => [[200, ['tenant_id' => '__TENANT__', 'apps' => ['Human-Resources']]]];
        yield 'id app diawali tanda hubung' => [[200, ['tenant_id' => '__TENANT__', 'apps' => ['-hr']]]];
        yield 'tenant lain' => [[200, ['tenant_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'apps' => ['human-resources']]]];
    }

    #[DataProvider('unusableCoreAnswers')]
    public function test_nothing_is_issued_when_core_does_not_give_a_usable_app_list(mixed $answer): void
    {
        $this->useLicenseKey();
        $site = $this->enrolledSite();

        if ($answer === 'putus') {
            Http::fake([$this->entitlementsUrl($site) => Http::failedConnection()]);
        } else {
            [$status, $body] = $answer;
            $body = $body === null ? null : json_decode(str_replace('__TENANT__', $site->tenant_id, (string) json_encode($body)), true);
            Http::fake([$this->entitlementsUrl($site) => Http::response($body, $status)]);
        }

        try {
            app(LicenseIssuer::class)->renew($site);
            $this->fail('Lisensi tidak boleh diterbitkan dari daftar app yang tidak terbaca.');
        } catch (EntitlementsUnavailable $e) {
            $this->assertStringContainsString($this->entitlementsUrl($site), $e->getMessage());
        }

        $site->refresh();
        $this->assertNull($site->license_issued_at);
        $this->assertNull($site->license_valid_until);
        $this->assertSame(0, OperatorAuditEvent::query()->count());
    }

    public function test_a_console_without_a_private_key_refuses_before_calling_core(): void
    {
        config(['sites.license_private_key_path' => null]);
        $site = $this->enrolledSite();
        $this->fakeEntitlements($site);

        try {
            app(LicenseIssuer::class)->renew($site);
            $this->fail('Tanpa kunci privat tidak ada lisensi.');
        } catch (SiteRejected $e) {
            $this->assertSame('license_key_missing', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_the_database_refuses_a_license_issued_without_an_end_date(): void
    {
        $site = $this->enrolledSite();

        foreach ([
            ['license_issued_at' => now(), 'license_valid_until' => null],
            ['license_issued_at' => null, 'license_valid_until' => '2026-10-15'],
        ] as $half) {
            try {
                DB::transaction(fn () => DB::table('sites')->where('id', $site->id)->update($half));
                $this->fail('Lisensi yang diterbitkan tanpa tanggal berakhir, atau sebaliknya, harus ditolak database.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('sites_lisensi_berpasangan', $e->getMessage());
            }
        }
    }

    /** DDL PostgreSQL ikut transaksi test, jadi turun-naik di sini tidak meninggalkan apa pun. */
    public function test_the_license_columns_migration_rolls_back_and_forward(): void
    {
        $migration = require __DIR__.'/../../../../core/database/migrations/2026_09_16_100000_add_license_columns_to_sites.php';

        $migration->down();
        $this->assertFalse(Schema::hasColumn('sites', 'license_issued_at'));
        $this->assertFalse(Schema::hasColumn('sites', 'license_valid_until'));
        $this->assertFalse(Schema::hasColumn('sites', 'license_suspended_at'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('sites', ['license_issued_at', 'license_valid_until', 'license_suspended_at']));
    }
}
