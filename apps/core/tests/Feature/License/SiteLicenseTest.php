<?php

declare(strict_types=1);

namespace Tests\Feature\License;

use App\Models\User;
use App\Support\License\SiteLicense;
use App\Support\License\SiteLicenseState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lisensi situs: tanda, bukan kunci.
 *
 * Dua hal dibuktikan bersama, dan tidak satu pun cukup sendirian. Pertama, setiap keadaan terbaca
 * dengan benar — termasuk berkas yang disunting satu byte dan tanda tangan dari kunci yang bukan
 * kunci rilis, dua cara paling murah untuk memalsukan lisensi. Kedua, keadaan yang paling buruk
 * sekalipun tidak mengunci apa pun: halaman tetap dilayani 200. Test pertama tanpa yang kedua
 * membiarkan seseorang kelak "menegakkan" lisensi dengan niat baik.
 *
 * Kuncinya dibuat di dalam test, bukan diambil dari berkas di repo. Kunci privat yang ikut
 * di-commit — sekalipun "hanya untuk test" — adalah kunci yang suatu hari disalin ke tempat lain.
 */
final class SiteLicenseTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{release: OpenSSLAsymmetricKey, foreign: OpenSSLAsymmetricKey}|null */
    private static ?array $keys = null;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        // Dibekukan supaya "hari ini" tidak berganti di antara menulis tanggal dan membacanya.
        $this->freezeTime();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'coreerp-lisensi-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($this->directory);

        config()->set('coreerp.license.path', $this->licensePath());
        config()->set('coreerp.license.public_key_path', $this->directory.DIRECTORY_SEPARATOR.'release.pub.pem');
        config()->set('coreerp.license.warn_days', 30);

        File::put($this->directory.DIRECTORY_SEPARATOR.'release.pub.pem', $this->publicPem('release'));
    }

    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->directory);
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
        $log->shouldNotHaveReceived('warning');
    }

    // ------------------------------------------------------------------ hilang

    public function test_a_configured_license_whose_file_is_absent_is_missing_and_warned_once_per_request(): void
    {
        // Mock ketat, bukan spy: panggilan log selain satu `warning` — termasuk `error` — menggagalkan test.
        Log::shouldReceive('warning')->once();

        // Dua pembaca dalam satu permintaan — misalnya prop bersama dan sesuatu yang lain kelak.
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

        config()->set('coreerp.license.public_key_path', $this->directory.DIRECTORY_SEPARATOR.'tidak-ada.pem');
        $this->assertSame(SiteLicenseState::MISSING, $this->freshState()->status);
    }

    // ------------------------------------------------------------------ bertanda tangan sah

    public function test_a_signed_license_far_from_its_end_date_is_valid(): void
    {
        $validUntil = now()->addDays(31)->toDateString();
        $this->installSignedLicense($this->licenseJson($validUntil));
        $log = Log::spy();

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::VALID, $state->status);
        $this->assertSame($validUntil, $state->validUntil);
        $log->shouldNotHaveReceived('warning');
    }

    /** Batas jendela peringatan dua-duanya termasuk: hari ke-30 dan hari ini sendiri. */
    #[DataProvider('daysInsideTheWarningWindow')]
    public function test_a_license_ending_inside_the_warning_window_is_expiring(int $daysLeft): void
    {
        $validUntil = now()->addDays($daysLeft)->toDateString();
        $this->installSignedLicense($this->licenseJson($validUntil));

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::EXPIRING, $state->status);
        $this->assertSame($validUntil, $state->validUntil);
    }

    /** @return array<string, array{int}> */
    public static function daysInsideTheWarningWindow(): array
    {
        return [
            'hari terakhir jendela' => [30],
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
    }

    // ------------------------------------------------------------------ tidak sah

    public function test_a_single_tampered_byte_makes_the_license_invalid(): void
    {
        $bytes = $this->licenseJson(now()->addYear()->toDateString());
        $this->installSignedLicense($bytes);

        // Satu byte, dan byte yang paling menggoda untuk diubah: nama edisinya.
        $position = strpos($bytes, 'apotek');
        $this->assertNotFalse($position);
        $bytes[$position] = 'A';
        File::put($this->licensePath(), $bytes);
        Log::shouldReceive('warning')->once();

        $state = $this->freshState();

        $this->assertSame(SiteLicenseState::INVALID, $state->status);
        $this->assertNull($state->validUntil, 'Tanggal dari berkas yang tidak terbukti tidak boleh ikut dipulangkan.');
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
    }

    /** @return array<string, array{string}> */
    public static function signedButMalformedLicenses(): array
    {
        return [
            'JSON rusak' => ['{"version":1,"valid_until":"2099-01-01"'],
            'bukan objek' => ['"2099-01-01"'],
            'versi 2' => ['{"version":2,"valid_until":"2099-01-01"}'],
            'versi berupa teks' => ['{"version":"1","valid_until":"2099-01-01"}'],
            'tanpa tanggal berakhir' => ['{"version":1}'],
            'tanggal yang tidak ada' => ['{"version":1,"valid_until":"2099-02-30"}'],
            'tanggal urutan lain' => ['{"version":1,"valid_until":"01-01-2099"}'],
            'tanggal beserta jam' => ['{"version":1,"valid_until":"2099-01-01T00:00:00Z"}'],
        ];
    }

    // ------------------------------------------------------------------ tidak pernah mengunci

    /**
     * Test yang membuat fitur ini boleh ada.
     *
     * Lisensi yang sudah habis tetap melayani halaman biasa dengan 200, dan halamannya menerima
     * keadaan lisensinya sebagai prop — bukan dialihkan, bukan ditolak, bukan dikosongkan.
     */
    public function test_an_expired_license_still_serves_an_authenticated_page_and_shares_its_state(): void
    {
        $validUntil = now()->subDays(10)->toDateString();
        $this->installSignedLicense($this->licenseJson($validUntil));

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('siteLicense.status', SiteLicenseState::EXPIRED)
                ->where('siteLicense.validUntil', $validUntil));
    }

    public function test_a_guest_receives_no_license_state_and_nothing_is_read(): void
    {
        $log = Log::spy();

        // Lisensi yang disetel tetapi tidak ada: seandainya tamu memicu pembacaan, peringatannya
        // akan tercatat.
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

    private function licensePath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'license.json';
    }

    private function licenseJson(string $validUntil): string
    {
        return json_encode([
            'version' => 1,
            'tenant_id' => '01j9zq3v6n8m2k4h7g5f3d1c0b',
            'site_id' => '01j9zq3v6n8m2k4h7g5f3d1c0c',
            'edition' => 'apotek-sejahtera',
            'valid_until' => $validUntil,
            'issued_at' => '2026-09-14T00:00:00Z',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Menulis `license.json` beserta `.sig`-nya, persis bentuk yang dipasang agen.
     *
     * Tanda tangannya diakhiri baris baru, karena begitulah berkas satu baris biasanya ditulis —
     * sehingga setiap test hijau di sini sekaligus membuktikan baris baru itu diterima.
     *
     * @param  'release'|'foreign'  $signer
     */
    private function installSignedLicense(string $bytes, string $signer = 'release'): void
    {
        $signed = openssl_sign($bytes, $signature, $this->keys()[$signer], OPENSSL_ALGO_SHA256);
        $this->assertTrue($signed, 'Lisensi uji tidak dapat ditandatangani.');

        File::put($this->licensePath(), $bytes);
        File::put($this->licensePath().'.sig', base64_encode((string) $signature)."\n");
    }

    /** @param  'release'|'foreign'  $which */
    private function publicPem(string $which): string
    {
        $details = openssl_pkey_get_details($this->keys()[$which]);
        $this->assertIsArray($details);

        return (string) $details['key'];
    }

    /**
     * Dua pasang kunci RSA, dibuat sekali untuk seluruh kelas — membuat kunci 2048 bit per test
     * hanya memperlambat suite tanpa membuktikan apa pun tambahan.
     *
     * @return array{release: OpenSSLAsymmetricKey, foreign: OpenSSLAsymmetricKey}
     */
    private function keys(): array
    {
        return self::$keys ??= [
            'release' => $this->makeKeyPair(),
            'foreign' => $this->makeKeyPair(),
        ];
    }

    private function makeKeyPair(): OpenSSLAsymmetricKey
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);

        // PHP untuk Windows tidak menemukan `openssl.cnf`-nya sendiri, dan `openssl_pkey_new()` gagal
        // dengan "configuration file routines::no such file". Berkasnya ikut terpasang di samping
        // binary PHP; di Linux cabang ini tidak pernah diambil.
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if ($key === false && is_file($bundledConfig)) {
            $key = openssl_pkey_new($options + ['config' => $bundledConfig]);
        }

        $this->assertNotFalse($key, 'Kunci RSA untuk lisensi uji tidak dapat dibuat.');

        return $key;
    }
}
