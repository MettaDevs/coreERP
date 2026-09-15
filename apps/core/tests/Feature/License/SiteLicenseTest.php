<?php

declare(strict_types=1);

namespace Tests\Feature\License;

use App\Models\User;
use App\Support\License\SiteLicense;
use App\Support\License\SiteLicenseState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesSiteLicenses;
use Tests\TestCase;

/**
 * Pembaca lisensi situs versi 2: keadaan, daftar app, dan dua jawaban yang diturunkan darinya.
 *
 * Tiga hal dibuktikan bersama, dan tidak satu pun cukup sendirian. Pertama, setiap keadaan terbaca
 * dengan benar — termasuk berkas yang disunting satu byte, tanda tangan dari kunci yang bukan kunci
 * rilis, dan daftar app yang bentuknya keliru. Kedua, **terkunci** hanya berarti wajib dan tidak
 * berlaku. Ketiga, pemasangan yang tidak mewajibkan lisensi tidak pernah terkunci dan tidak pernah
 * kehilangan app, apa pun isi berkasnya — test yang membuat SaaS dan beli-putus aman dari fitur ini.
 */
final class SiteLicenseTest extends TestCase
{
    use RefreshDatabase;
    use WritesSiteLicenses;

    protected function setUp(): void
    {
        parent::setUp();

        // Dibekukan supaya "hari ini" tidak berganti di antara menulis tanggal dan membacanya.
        $this->freezeTime();
        $this->prepareSiteLicenseDirectory();
    }

    protected function tearDown(): void
    {
        try {
            $this->removeSiteLicenseDirectory();
        } finally {
            parent::tearDown();
        }
    }

    // ------------------------------------------------------------------ tanpa lisensi

    public function test_an_installation_without_a_license_path_needs_no_license_and_logs_nothing(): void
    {
        config()->set('coreerp.license.path', null);
        $log = Log::spy();

        $state = app(SiteLicense::class)->state();

        $this->assertSame(SiteLicenseState::NOT_REQUIRED, $state->status);
        $this->assertNull($state->validUntil);
        $this->assertFalse($state->isLocked());
        $log->shouldNotHaveReceived('warning');
    }

    /** Mengosongkan satu baris `.env` tidak boleh lebih murah daripada menghapus berkasnya. */
    public function test_a_required_license_without_a_path_is_missing_and_locked(): void
    {
        config()->set('coreerp.license.required', true);
        config()->set('coreerp.license.path', '');
        Log::shouldReceive('warning')->once();

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::MISSING, $state->status);
        $this->assertTrue($state->required);
        $this->assertTrue($state->isLocked());
    }

    // ------------------------------------------------------------------ hilang

    public function test_a_configured_license_whose_file_is_absent_is_missing_and_warned_once_per_request(): void
    {
        // Mock ketat, bukan spy: panggilan log selain satu `warning` — termasuk `error` — menggagalkan test.
        Log::shouldReceive('warning')->once();

        // Dua pembaca dalam satu permintaan — prop bersama dan middleware kunci, misalnya.
        $first = app(SiteLicense::class)->state();
        $second = app(SiteLicense::class)->state();

        $this->assertSame(SiteLicenseState::MISSING, $first->status);
        $this->assertSame($first, $second, 'Berkasnya seharusnya dibaca sekali per permintaan.');
        $this->assertNull($first->validUntil);
    }

    public function test_a_license_without_its_signature_file_is_missing(): void
    {
        File::put($this->licensePath(), $this->licenseJson(now()->addYear()->toDateString()));

        $this->assertSame(SiteLicenseState::MISSING, $this->freshState()->status);
    }

    public function test_a_license_without_a_readable_public_key_is_missing(): void
    {
        $this->installSignedLicense($this->licenseJson(now()->addYear()->toDateString()));

        config()->set('coreerp.license.public_key_path', null);
        $this->assertSame(SiteLicenseState::MISSING, $this->freshState()->status);

        config()->set('coreerp.license.public_key_path', $this->licensePath().'.tidak-ada.pem');
        $this->assertSame(SiteLicenseState::MISSING, $this->freshState()->status);
    }

    // ------------------------------------------------------------------ bertanda tangan sah

    public function test_a_signed_version_2_license_far_from_its_end_date_is_valid_and_carries_its_apps(): void
    {
        $validUntil = now()->addDays(8)->toDateString();
        $this->installSignedLicense($this->licenseJson($validUntil, ['human-resources', 'management-aset']));
        $log = Log::spy();

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::VALID, $state->status);
        $this->assertSame($validUntil, $state->validUntil);
        $this->assertSame(8, $state->daysLeft);
        $this->assertSame(['human-resources', 'management-aset'], $state->apps);
        $log->shouldNotHaveReceived('warning');
    }

    /** Kontrak mengizinkan daftar kosong: tenant yang hanya memakai Core. */
    public function test_an_empty_app_list_is_a_valid_license(): void
    {
        $this->installSignedLicense($this->licenseJson(now()->addMonth()->toDateString(), []));

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::VALID, $state->status);
        $this->assertSame([], $state->apps);
    }

    /** Batas jendela peringatan dua-duanya termasuk: hari ke-7 dan hari ini sendiri. */
    #[DataProvider('daysInsideTheWarningWindow')]
    public function test_a_license_ending_inside_the_warning_window_is_expiring(int $daysLeft): void
    {
        $validUntil = now()->addDays($daysLeft)->toDateString();
        $this->installSignedLicense($this->licenseJson($validUntil));

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::EXPIRING, $state->status);
        $this->assertSame($validUntil, $state->validUntil);
        $this->assertSame($daysLeft, $state->daysLeft);
    }

    /** @return array<string, array{int}> */
    public static function daysInsideTheWarningWindow(): array
    {
        return [
            'hari terakhir jendela' => [7],
            'berakhir hari ini' => [0],
        ];
    }

    public function test_a_license_past_its_end_date_is_expired(): void
    {
        $validUntil = now()->subDay()->toDateString();
        $this->installSignedLicense($this->licenseJson($validUntil));

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::EXPIRED, $state->status);
        $this->assertSame($validUntil, $state->validUntil);
        $this->assertSame(-1, $state->daysLeft);
    }

    // ------------------------------------------------------------------ tidak sah

    public function test_a_single_tampered_byte_makes_the_license_invalid_and_hides_its_apps(): void
    {
        $bytes = $this->licenseJson(now()->addYear()->toDateString(), ['contoh-a']);
        $this->installSignedLicense($bytes);

        // Satu byte, dan byte yang paling menggoda untuk diubah: daftar app-nya.
        $position = strpos($bytes, 'contoh-a');
        $this->assertNotFalse($position);
        $bytes[$position + 7] = 'b';
        File::put($this->licensePath(), $bytes);
        Log::shouldReceive('warning')->once();

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::INVALID, $state->status);
        $this->assertNull($state->validUntil, 'Tanggal dari berkas yang tidak terbukti tidak boleh ikut dipulangkan.');
        $this->assertSame([], $state->apps, 'Daftar app dari berkas yang tidak terbukti tidak boleh ikut dipulangkan.');
    }

    public function test_a_signature_made_with_another_key_makes_the_license_invalid(): void
    {
        $this->installSignedLicense($this->licenseJson(now()->addYear()->toDateString()), signer: 'foreign');

        $this->assertSame(SiteLicenseState::INVALID, $this->freshState()->status);
    }

    public function test_a_signature_that_is_not_base64_makes_the_license_invalid(): void
    {
        File::put($this->licensePath(), $this->licenseJson(now()->addYear()->toDateString()));
        File::put($this->licensePath().'.sig', "bukan base64 sama sekali!\n");

        $this->assertSame(SiteLicenseState::INVALID, $this->freshState()->status);
    }

    /** Bertanda tangan sah bukan berarti berbentuk lisensi. Penerbit yang keliru pun ditolak. */
    #[DataProvider('signedButMalformedLicenses')]
    public function test_signed_content_that_is_not_a_known_license_is_invalid(string $bytes): void
    {
        $this->installSignedLicense($bytes);

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::INVALID, $state->status);
        $this->assertNull($state->validUntil);
        $this->assertSame([], $state->apps);
    }

    /** @return array<string, array{string}> */
    public static function signedButMalformedLicenses(): array
    {
        return [
            'JSON rusak' => ['{"version":2,"apps":[],"valid_until":"2099-01-01"'],
            'bukan objek' => ['"2099-01-01"'],
            'array di puncak' => ['[{"version":2,"apps":[],"valid_until":"2099-01-01"}]'],
            'versi 1 tanpa daftar app' => ['{"version":1,"valid_until":"2099-01-01"}'],
            'versi 1 walau membawa daftar app' => ['{"version":1,"apps":["contoh-a"],"valid_until":"2099-01-01"}'],
            'versi 3' => ['{"version":3,"apps":[],"valid_until":"2099-01-01"}'],
            'versi berupa teks' => ['{"version":"2","apps":[],"valid_until":"2099-01-01"}'],
            'versi pecahan' => ['{"version":2.0,"apps":[],"valid_until":"2099-01-01"}'],
            'tanpa tanggal berakhir' => ['{"version":2,"apps":[]}'],
            'tanggal yang tidak ada' => ['{"version":2,"apps":[],"valid_until":"2099-02-30"}'],
            'tanggal urutan lain' => ['{"version":2,"apps":[],"valid_until":"01-01-2099"}'],
            'tanggal beserta jam' => ['{"version":2,"apps":[],"valid_until":"2099-01-01T00:00:00Z"}'],
        ];
    }

    /**
     * Daftar app yang bentuknya keliru menolak **seluruh** lisensi, bukan hanya id yang cacat.
     * Membuang id itu diam-diam menutup app yang dibeli tanpa satu catatan pun yang menyebut sebabnya.
     */
    #[DataProvider('malformedAppLists')]
    public function test_a_signed_license_with_a_malformed_app_list_is_invalid(string $apps): void
    {
        $this->installSignedLicense('{"version":2,"apps":'.$apps.',"valid_until":"2099-01-01"}');

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::INVALID, $state->status);
        $this->assertSame([], $state->apps);
    }

    /** @return array<string, array{string}> */
    public static function malformedAppLists(): array
    {
        return [
            'tanpa daftar app' => ['null'],
            'teks, bukan daftar' => ['"contoh-a"'],
            'objek kosong' => ['{}'],
            'objek berindeks' => ['{"0":"contoh-a"}'],
            'id bukan teks' => ['[1]'],
            'id bersarang' => ['[["contoh-a"]]'],
            'id kosong' => ['[""]'],
            'huruf besar' => ['["Contoh-A"]'],
            'diawali tanda hubung' => ['["-contoh"]'],
            'garis bawah' => ['["contoh_a"]'],
            'spasi' => ['["contoh a"]'],
            'baris baru di ujung' => ['["contoh-a\n"]'],
            'id berulang' => ['["contoh-a","contoh-a"]'],
        ];
    }

    public function test_a_license_without_an_apps_key_is_invalid(): void
    {
        $this->installSignedLicense('{"version":2,"valid_until":"2099-01-01"}');

        $this->assertSame(SiteLicenseState::INVALID, $this->freshState()->status);
    }

    // ------------------------------------------------------------------ terkunci dan app yang diizinkan

    /**
     * Terkunci = wajib **dan** hilang, tidak sah, atau habis. Sisanya tidak pernah terkunci.
     *
     * @param  SiteLicenseState::*  $status
     */
    #[DataProvider('lockMatrix')]
    public function test_locked_means_required_and_not_in_force(string $status, bool $required, bool $locked): void
    {
        $state = new SiteLicenseState($status, apps: ['contoh-a'], required: $required);

        $this->assertSame($locked, $state->isLocked());
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function lockMatrix(): array
    {
        return [
            'wajib, hilang' => [SiteLicenseState::MISSING, true, true],
            'wajib, tidak sah' => [SiteLicenseState::INVALID, true, true],
            'wajib, habis' => [SiteLicenseState::EXPIRED, true, true],
            'wajib, berlaku' => [SiteLicenseState::VALID, true, false],
            'wajib, segera habis' => [SiteLicenseState::EXPIRING, true, false],
            'tidak wajib, hilang' => [SiteLicenseState::MISSING, false, false],
            'tidak wajib, tidak sah' => [SiteLicenseState::INVALID, false, false],
            'tidak wajib, habis' => [SiteLicenseState::EXPIRED, false, false],
            'tidak wajib, tidak disetel' => [SiteLicenseState::NOT_REQUIRED, false, false],
        ];
    }

    public function test_a_required_valid_license_allows_only_the_apps_it_lists(): void
    {
        config()->set('coreerp.license.required', true);
        $this->installSignedLicense($this->licenseJson(now()->addMonth()->toDateString(), ['human-resources']));

        $license = new SiteLicense;

        $this->assertFalse($license->isLocked());
        $this->assertTrue($license->allowsApp('human-resources'));
        $this->assertFalse($license->allowsApp('management-aset'));
        // Pencocokan persis, bukan awalan atau huruf besar-kecil.
        $this->assertFalse($license->allowsApp('human'));
        $this->assertFalse($license->allowsApp('Human-Resources'));
    }

    /** Lisensi yang masih mencantumkan app tidak boleh tetap membukanya setelah habis. */
    public function test_a_required_expired_license_is_locked_and_allows_no_app_even_the_ones_it_lists(): void
    {
        config()->set('coreerp.license.required', true);
        $this->installSignedLicense($this->licenseJson(now()->subDay()->toDateString(), ['human-resources']));

        $license = new SiteLicense;

        $this->assertTrue($license->isLocked());
        $this->assertFalse($license->allowsApp('human-resources'));
    }

    public function test_an_expiring_required_license_still_allows_its_apps(): void
    {
        config()->set('coreerp.license.required', true);
        $this->installSignedLicense($this->licenseJson(now()->toDateString(), ['human-resources']));

        $license = new SiteLicense;

        $this->assertSame(SiteLicenseState::EXPIRING, $license->state()->status);
        $this->assertFalse($license->isLocked());
        $this->assertTrue($license->allowsApp('human-resources'));
    }

    /**
     * Pemasangan yang tidak mewajibkan lisensi tidak pernah kehilangan app — termasuk ketika sebuah
     * lisensi yang tidak mencantumkannya kebetulan tersisa di disk, dan ketika lisensinya habis.
     */
    public function test_a_license_that_is_not_required_never_locks_and_allows_every_app(): void
    {
        $this->installSignedLicense($this->licenseJson(now()->subYear()->toDateString(), []));

        $license = new SiteLicense;

        $this->assertSame(SiteLicenseState::EXPIRED, $license->state()->status);
        $this->assertFalse($license->isLocked());
        $this->assertTrue($license->allowsApp('human-resources'));
    }

    /**
     * Config dapat berisi teks, bukan boolean — misalnya dari cache config yang disusun dari `.env`
     * tanpa penguraian. Teks `"false"` yang dibaca sebagai benar akan mengunci server yang tidak
     * pernah diminta terkunci.
     */
    #[DataProvider('requiredSettings')]
    public function test_the_required_setting_is_parsed_as_a_boolean(mixed $setting, bool $required): void
    {
        config()->set('coreerp.license.required', $setting);

        $this->assertSame($required, (new SiteLicense)->required());
    }

    /** @return array<string, array{mixed, bool}> */
    public static function requiredSettings(): array
    {
        return [
            'true' => [true, true],
            'teks true' => ['true', true],
            'teks 1' => ['1', true],
            'false' => [false, false],
            'teks false' => ['false', false],
            'teks kosong' => ['', false],
            'null' => [null, false],
        ];
    }

    /**
     * `COREERP_LICENSE_REQUIRED` diurai di berkas config, bukan diteruskan apa adanya.
     *
     * `env()` Laravel hanya mengenali `true` dan `false`; `1` dan `on` — cara lain yang wajar untuk
     * menulis "nyala" di `.env` — tiba sebagai teks. Config yang menyimpan teks membuat setiap
     * pembacanya harus ingat menguraikannya lagi.
     */
    #[DataProvider('requiredEnvironmentValues')]
    public function test_the_config_parses_the_required_environment_variable_as_a_boolean(?string $value, bool $required): void
    {
        $key = 'COREERP_LICENSE_REQUIRED';
        $before = ['env' => getenv($key), '_ENV' => $_ENV[$key] ?? null, '_SERVER' => $_SERVER[$key] ?? null];

        try {
            // `$_SERVER` juga, bukan hanya `putenv()`: repository env Laravel membacanya lebih dulu.
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }

            $config = require config_path('coreerp.php');

            $this->assertSame($required, $config['license']['required']);
        } finally {
            $before['env'] === false ? putenv($key) : putenv($key.'='.$before['env']);

            foreach (['_ENV', '_SERVER'] as $global) {
                if ($before[$global] === null) {
                    unset($GLOBALS[$global][$key]);
                } else {
                    $GLOBALS[$global][$key] = $before[$global];
                }
            }
        }
    }

    /** @return array<string, array{string|null, bool}> */
    public static function requiredEnvironmentValues(): array
    {
        return [
            'true' => ['true', true],
            '1' => ['1', true],
            'on' => ['on', true],
            'false' => ['false', false],
            '0' => ['0', false],
            'no' => ['no', false],
            'kosong' => ['', false],
            'tidak disetel' => [null, false],
        ];
    }

    /** SaaS tidak membayar apa pun: lisensi yang tidak wajib tidak membaca berkas demi menjawab dua pertanyaan ini. */
    public function test_an_installation_that_does_not_require_a_license_does_not_read_it_to_answer(): void
    {
        // Disetel tetapi tidak ada: seandainya pertanyaan ini memicu pembacaan, peringatannya tercatat.
        $log = Log::spy();
        $license = new SiteLicense;

        $this->assertFalse($license->isLocked());
        $this->assertTrue($license->allowsApp('contoh-a'));
        $log->shouldNotHaveReceived('warning');
    }

    // ------------------------------------------------------------------ prop bersama

    /**
     * Lisensi yang sudah habis pada pemasangan yang tidak mewajibkannya tetap melayani halaman biasa
     * dengan 200, dan halamannya menerima keadaan lisensinya sebagai prop untuk spanduk.
     */
    public function test_an_expired_license_that_is_not_required_still_serves_a_page_and_shares_its_state(): void
    {
        $validUntil = now()->subDays(10)->toDateString();
        $this->installSignedLicense($this->licenseJson($validUntil));

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('siteLicense.status', SiteLicenseState::EXPIRED)
                ->where('siteLicense.validUntil', $validUntil)
                ->where('siteLicense.daysLeft', -10)
                ->where('siteLicense.required', false)
                // Daftar app tidak dikirim ke peramban; yang menyaring server.
                ->missing('siteLicense.apps'));
    }

    public function test_a_guest_receives_no_license_state_and_nothing_is_read(): void
    {
        config()->set('coreerp.license.required', true);
        $log = Log::spy();

        // Lisensi yang wajib, disetel, tetapi tidak ada: seandainya tamu memicu pembacaan — lewat
        // prop bersama atau lewat middleware kunci — peringatannya akan tercatat.
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('siteLicense', null));

        $log->shouldNotHaveReceived('warning');
    }

    // ------------------------------------------------------------------ pembantu

    /** Instans baru, supaya satu test dapat membaca beberapa susunan berkas berturut-turut. */
    private function freshState(): SiteLicenseState
    {
        return (new SiteLicense)->state();
    }
}
