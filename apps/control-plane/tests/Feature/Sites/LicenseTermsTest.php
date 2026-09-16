<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use Carbon\CarbonImmutable;
use ControlPlane\Models\OperatorAuditEvent;
use ControlPlane\Sites\LicenseIssuer;
use ControlPlane\Sites\LicenseTerms;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Masa lisensi: bawaan konsol, timpaan per situs, dan lisensi permanen.
 *
 * Tiga hal dibuktikan bersama. Pertama, angka yang dipakai penerbit memang angka yang berlaku untuk situs
 * itu — bukan angka di `config`, yang sebelumnya satu-satunya sumber. Kedua, situs permanen menerima
 * lisensi tanpa tanggal berakhir, dan permintaan operator yang menyebut tanggal tidak dapat membatalkannya.
 * Ketiga, angka yang mustahil ditolak — oleh layar maupun oleh database.
 */
class LicenseTermsTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 08:00:00', 'UTC'));
    }

    // ------------------------------------------------------------------ angka yang berlaku

    public function test_without_anything_stored_the_terms_come_from_config(): void
    {
        config(['sites.license_valid_days' => 30, 'sites.license_renew_before_days' => 10]);

        $this->assertSame(['validDays' => 30, 'renewBeforeDays' => 10], app(LicenseTerms::class)->defaults());
    }

    public function test_the_console_default_overrides_config_and_the_site_overrides_the_console(): void
    {
        config(['sites.license_valid_days' => 30, 'sites.license_renew_before_days' => 10]);
        app(LicenseTerms::class)->storeDefaults(90, 45, null);

        $site = $this->enrolledSite();

        $this->assertSame(['validDays' => 90, 'renewBeforeDays' => 45], app(LicenseTerms::class)->defaults());
        $this->assertSame(
            ['perpetual' => false, 'validDays' => 90, 'renewBeforeDays' => 45, 'overridden' => false],
            app(LicenseTerms::class)->forSite($site),
        );

        $site->forceFill(['license_valid_days' => 365, 'license_renew_before_days' => 60])->save();

        $this->assertSame(
            ['perpetual' => false, 'validDays' => 365, 'renewBeforeDays' => 60, 'overridden' => true],
            app(LicenseTerms::class)->forSite($site->refresh()),
        );
    }

    /**
     * Baris `console_settings` disunting tangan lewat runbook, dan nol hari di sana berarti setiap lisensi
     * yang lahir sudah habis: seluruh klien terkunci pada perpanjangan berikutnya.
     *
     * @param  ?string  $stored  nilai yang tersimpan, null berarti tidak ada barisnya
     */
    #[DataProvider('storedDefaults')]
    public function test_a_stored_default_that_is_not_a_sane_number_falls_back_to_config(?string $stored, int $expected): void
    {
        config(['sites.license_valid_days' => 30]);

        if ($stored !== null) {
            DB::table('console_settings')->insert([
                'key' => LicenseTerms::VALID_DAYS,
                'value' => $stored,
                'updated_at' => now(),
            ]);
        }

        $this->assertSame($expected, app(LicenseTerms::class)->defaults()['validDays']);
    }

    /** @return iterable<string, array{?string, int}> */
    public static function storedDefaults(): iterable
    {
        yield 'belum pernah disetel' => [null, 30];
        yield 'nol hari' => ['0', 30];
        yield 'kosong' => ['', 30];
        yield 'bukan angka' => ['selamanya', 30];
        yield 'negatif' => ['-5', 30];
        yield 'di atas batas' => ['4000', 30];
        // Yang menolak ini hanya bentuknya. Tanpa pemeriksaan bentuk, PHP membaca '90 hari' sebagai 90 dan
        // '30.5' sebagai 30: nilai yang disunting keliru diam-diam menjadi masa lisensi seluruh klien.
        yield 'angka dengan ekor' => ['90 hari', 30];
        yield 'angka pecahan' => ['30.5', 30];
        yield 'angka yang wajar' => ['90', 90];
    }

    // ------------------------------------------------------------------ lisensi permanen

    public function test_a_perpetual_site_is_issued_a_license_without_an_end_date(): void
    {
        $this->useLicenseKey();
        $site = $this->enrolledSite();
        $site->forceFill(['license_perpetual' => true])->save();
        $this->fakeEntitlements($site, ['human-resources']);

        $issued = app(LicenseIssuer::class)->renew($site->refresh());

        $this->assertSame(
            '{"version":2,"tenant_id":"'.$site->tenant_id.'","site_id":"'.$site->id.'",'
            .'"apps":["human-resources"],"valid_until":null,"issued_at":"2026-09-15T08:00:00Z"}',
            $issued['license'],
        );

        $site->refresh();
        $this->assertNull($site->license_valid_until);
        $this->assertSame('2026-09-15 08:00:00', $site->license_issued_at?->toDateTimeString());
        $this->assertTrue($site->licenseIssuedPerpetual());

        $event = OperatorAuditEvent::query()->sole();
        $this->assertTrue($event->detail['perpetual']);
        $this->assertNull($event->detail['valid_until']);
    }

    /**
     * Tanggal yang diminta operator datang dari layar yang mungkin dibuka sebelum situs ini ditandai
     * permanen. Lisensi bertanggal yang lahir dari situ akan mengunci klinik yang justru dibebaskan.
     */
    public function test_a_date_asked_for_by_an_operator_cannot_undo_a_perpetual_site(): void
    {
        $this->useLicenseKey();
        $operator = $this->operator();
        $site = $this->enrolledSite();
        $site->forceFill(['license_perpetual' => true])->save();
        $this->fakeEntitlements($site, []);

        $request = Request::create('/situs/'.$site->id.'/operasi', 'POST');
        $request->setUserResolver(fn () => $operator);

        $issued = app(LicenseIssuer::class)->issueForOperator($request, $site->refresh(), '2026-12-31');

        $this->assertStringContainsString('"valid_until":null', $issued['license']);
        $this->assertNull($site->refresh()->license_valid_until);
    }

    public function test_a_site_that_is_no_longer_perpetual_is_issued_a_dated_license_again(): void
    {
        $this->useLicenseKey();
        $site = $this->enrolledSite();
        $site->forceFill(['license_perpetual' => true])->save();
        $this->fakeEntitlements($site, []);
        app(LicenseIssuer::class)->renew($site->refresh());

        $site->forceFill(['license_perpetual' => false])->save();
        $issued = app(LicenseIssuer::class)->renew($site->refresh());

        $this->assertStringContainsString('"valid_until":"2026-10-15"', $issued['license']);
        $this->assertFalse($site->refresh()->licenseIssuedPerpetual());
    }

    // ------------------------------------------------------------------ layar operator

    public function test_the_settings_screen_shows_the_default_terms_and_saves_new_ones(): void
    {
        $operator = $this->operator();
        app(LicenseTerms::class)->storeDefaults(30, 10, null);

        $this->actingAs($operator)->get('/pengaturan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('licenseTerms', ['validDays' => 30, 'renewBeforeDays' => 10]));

        $this->actingAs($operator)->patch('/pengaturan/lisensi', ['valid_days' => 90, 'renew_before_days' => 45])
            ->assertRedirect('/pengaturan');

        $this->assertSame(['validDays' => 90, 'renewBeforeDays' => 45], app(LicenseTerms::class)->defaults());

        $event = OperatorAuditEvent::query()->where('action', 'console.license_terms_changed')->sole();
        $this->assertSame(['validDays' => 30, 'renewBeforeDays' => 10], $event->detail['from']);
        $this->assertSame(['validDays' => 90, 'renewBeforeDays' => 45], $event->detail['to']);
    }

    /**
     * Perpanjangan yang tidak lebih awal dari masa lisensinya sendiri tidak pernah terjadi, dan setiap
     * klien terkunci pada tanggal berakhirnya. Ditolak isian, bukan hanya oleh constraint per situs.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('impossibleDefaults')]
    public function test_the_settings_screen_refuses_terms_that_cannot_work(array $payload, string $field): void
    {
        app(LicenseTerms::class)->storeDefaults(30, 10, null);

        $this->actingAs($this->operator())
            ->patch('/pengaturan/lisensi', $payload)
            ->assertSessionHasErrors($field);

        $this->assertSame(['validDays' => 30, 'renewBeforeDays' => 10], app(LicenseTerms::class)->defaults());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function impossibleDefaults(): iterable
    {
        yield 'perpanjangan sama dengan masanya' => [['valid_days' => 30, 'renew_before_days' => 30], 'renew_before_days'];
        yield 'perpanjangan lebih lama dari masanya' => [['valid_days' => 30, 'renew_before_days' => 31], 'renew_before_days'];
        yield 'masa nol hari' => [['valid_days' => 0, 'renew_before_days' => 10], 'valid_days'];
        yield 'masa di atas batas' => [['valid_days' => 3651, 'renew_before_days' => 10], 'valid_days'];
        yield 'bukan angka' => [['valid_days' => 'selamanya', 'renew_before_days' => 10], 'valid_days'];
    }

    public function test_an_operator_marks_a_site_perpetual_from_its_page(): void
    {
        $operator = $this->operator();
        $site = $this->enrolledSite();

        $this->actingAs($operator)
            ->post('/situs/'.$site->id.'/lisensi/masa', ['mode' => 'perpetual', 'confirm_name' => $site->name])
            ->assertRedirect('/situs/'.$site->id);

        $terms = app(LicenseTerms::class)->forSite($site->refresh());
        $this->assertTrue($terms['perpetual']);
        $this->assertFalse($terms['overridden']);

        $event = OperatorAuditEvent::query()->where('action', 'site.license.terms_changed')->sole();
        $this->assertSame($operator->getAuthIdentifier(), $event->user_id);
        $this->assertFalse($event->detail['from']['perpetual']);
        $this->assertTrue($event->detail['to']['perpetual']);
    }

    public function test_a_site_keeps_its_own_days_and_can_go_back_to_the_console_default(): void
    {
        $operator = $this->operator();
        $site = $this->enrolledSite();

        $this->actingAs($operator)->post('/situs/'.$site->id.'/lisensi/masa', [
            'mode' => 'custom',
            'valid_days' => 120,
            'renew_before_days' => 30,
            'confirm_name' => $site->name,
        ])->assertRedirect('/situs/'.$site->id);

        $this->assertSame(
            ['perpetual' => false, 'validDays' => 120, 'renewBeforeDays' => 30, 'overridden' => true],
            app(LicenseTerms::class)->forSite($site->refresh()),
        );

        $this->actingAs($operator)->post('/situs/'.$site->id.'/lisensi/masa', [
            'mode' => 'default',
            'confirm_name' => $site->name,
        ])->assertRedirect('/situs/'.$site->id);

        $this->assertFalse(app(LicenseTerms::class)->forSite($site->refresh())['overridden']);
    }

    /**
     * Nama situs yang salah ketik tidak boleh cukup untuk mengubah masa lisensi klinik yang lain. Penjaga
     * yang sama dipakai seluruh tindakan di halaman situs.
     */
    public function test_a_wrong_site_name_changes_nothing(): void
    {
        $site = $this->enrolledSite();

        $this->actingAs($this->operator())
            ->post('/situs/'.$site->id.'/lisensi/masa', ['mode' => 'perpetual', 'confirm_name' => 'situs lain'])
            ->assertSessionHasErrors('confirm_name');

        $this->assertFalse(app(LicenseTerms::class)->forSite($site->refresh())['perpetual']);
        $this->assertSame(0, OperatorAuditEvent::query()->where('action', 'site.license.terms_changed')->count());
    }

    public function test_the_site_page_refuses_a_renewal_window_that_is_not_earlier_than_the_term(): void
    {
        $site = $this->enrolledSite();

        $this->actingAs($this->operator())->post('/situs/'.$site->id.'/lisensi/masa', [
            'mode' => 'custom',
            'valid_days' => 30,
            'renew_before_days' => 30,
            'confirm_name' => $site->name,
        ])->assertSessionHasErrors('renew_before_days');

        $this->assertNull($site->refresh()->license_valid_days);
    }

    // ------------------------------------------------------------------ yang dijaga database

    /**
     * Penerbitan menulis tiga kolom sekaligus, dan constraint ini yang menjaga ketiganya tetap masuk akal
     * ketika kelak ada penulis lain — perintah artisan, perbaikan data, migrasi — yang hanya tahu separuh
     * aturannya.
     *
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('impossibleLicenseStates')]
    public function test_the_database_refuses_a_license_state_that_cannot_exist(array $attributes): void
    {
        $site = $this->enrolledSite();

        $this->expectException(QueryException::class);
        $site->forceFill($attributes)->save();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function impossibleLicenseStates(): iterable
    {
        yield 'tanggal tanpa penerbitan' => [['license_valid_until' => '2026-10-15']];
        yield 'diterbitkan tanpa tanggal dan tanpa penanda permanen' => [['license_issued_at' => '2026-09-15 08:00:00']];
        yield 'permanen tetapi tetap bertanggal' => [[
            'license_issued_at' => '2026-09-15 08:00:00',
            'license_valid_until' => '2026-10-15',
            'license_issued_perpetual' => true,
        ]];
        yield 'penanda permanen tanpa penerbitan' => [['license_issued_perpetual' => true]];
        yield 'masa nol hari' => [['license_valid_days' => 0]];
        yield 'perpanjangan tidak lebih awal dari masanya' => [['license_valid_days' => 30, 'license_renew_before_days' => 30]];
    }
}
