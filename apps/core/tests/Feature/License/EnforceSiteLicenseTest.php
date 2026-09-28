<?php

declare(strict_types=1);

namespace Tests\Feature\License;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ProviderAccess;
use App\Models\User;
use App\Support\License\SiteLicenseState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesSiteLicenses;
use Tests\TestCase;

/**
 * Kunci lisensi di depan grup web: siapa yang tertahan, siapa yang lewat, dan bentuk penolakannya.
 *
 * Kelas kegagalan yang paling mahal di sini ada dua dan arahnya berlawanan. Kunci yang tidak menahan
 * membuat lisensi tidak berarti apa pun. Kunci yang menahan orang yang salah — provider yang datang
 * memperbaiki, orang yang ingin keluar, pemantau `/up`, atau seluruh pemasangan SaaS — mematikan
 * layanan yang tidak pernah diminta dikunci. Karena itu setiap pintu yang dilepas punya test-nya
 * sendiri, dan satu test membuktikan pemasangan yang tidak mewajibkan lisensi tidak pernah tertahan.
 */
final class EnforceSiteLicenseTest extends TestCase
{
    use RefreshDatabase;
    use WritesSiteLicenses;

    protected function setUp(): void
    {
        parent::setUp();

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

    // ------------------------------------------------------------------ tertahan

    public function test_a_tenant_user_behind_an_expired_required_license_sees_the_lock_page_with_403(): void
    {
        $validUntil = now()->subDays(3)->toDateString();
        $this->lockWith($this->licenseJson($validUntil));

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('license-locked')
                ->where('status', SiteLicenseState::EXPIRED)
                ->where('validUntil', $validUntil));
    }

    /** Dirender di tempat, bukan dialihkan: tidak ada tujuan pengalihan, jadi tidak ada putaran. */
    public function test_the_lock_page_is_rendered_in_place_at_any_address_and_never_redirects(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));
        $user = User::factory()->create();

        foreach (['/', '/dashboard', '/settings/profile', '/settings/access'] as $address) {
            $response = $this->actingAs($user)->get($address);

            $this->assertSame(403, $response->getStatusCode(), "{$address} seharusnya tertahan.");
            $this->assertFalse($response->isRedirection(), "{$address} tidak boleh dialihkan.");
            $response->assertInertia(fn (AssertableInertia $page) => $page->component('license-locked'));
        }
    }

    /** Kunjungan Inertia dari halaman yang sudah terbuka menerima halaman kunci juga, bukan JSON galat. */
    public function test_an_inertia_visit_receives_the_lock_page_as_an_inertia_response(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));

        // Versi aset harus sama, kalau tidak Inertia menjawab 409 sebelum kunci sempat bekerja.
        $version = (string) app(HandleInertiaRequests::class)->version(request());

        $this->actingAs(User::factory()->create())
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('dashboard'))
            ->assertForbidden()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'license-locked');
    }

    /** Hilang dan tanda tangan salah mengunci sama seperti habis. */
    #[DataProvider('brokenLicenses')]
    public function test_a_missing_or_invalid_required_license_locks_too(string $case, string $status): void
    {
        config()->set('coreerp.license.required', true);

        if ($case === 'invalid') {
            $this->installSignedLicense($this->licenseJson(now()->addMonth()->toDateString()), signer: 'foreign');
        }

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('license-locked')
                ->where('status', $status)
                ->where('validUntil', null));
    }

    /** @return array<string, array{string, string}> */
    public static function brokenLicenses(): array
    {
        return [
            'berkas tidak ada' => ['missing', SiteLicenseState::MISSING],
            'tanda tangan kunci lain' => ['invalid', SiteLicenseState::INVALID],
        ];
    }

    // ------------------------------------------------------------------ pemanggil JSON

    public function test_a_json_caller_receives_license_locked_instead_of_a_page(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));

        $this->actingAs(User::factory()->create())
            ->getJson(route('dashboard'))
            ->assertForbidden()
            ->assertExactJson([
                'error' => 'license_locked',
                'message' => 'Lisensi aplikasi ini tidak berlaku. Hubungi penyedia aplikasi.',
            ]);
    }

    /** Rute JSON di grup web tidak selalu dipanggil dengan `Accept: application/json`. */
    public function test_an_api_path_receives_json_even_without_asking_for_it(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));

        // Rute yang menjawab 200 bagi siapa pun, jadi 403-nya pasti datang dari kunci.
        $this->get('/api/v1/control/apps')->assertOk();

        $this->actingAs(User::factory()->create())
            ->get('/api/v1/control/apps')
            ->assertForbidden()
            ->assertJsonPath('error', 'license_locked');
    }

    // ------------------------------------------------------------------ yang dilepas

    public function test_a_provider_admin_passes_a_locked_installation(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));
        $provider = User::factory()->create();
        ProviderAccess::query()->create(['user_id' => $provider->id, 'role' => 'provider_admin']);

        $this->actingAs($provider)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('siteLicense.status', SiteLicenseState::EXPIRED)
                ->where('siteLicense.required', true));
    }

    /** Penanda provider yang bukan admin tidak cukup — sama dengan gerbang katalog app. */
    public function test_a_provider_record_without_the_admin_role_is_still_locked(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));
        $user = User::factory()->create();
        ProviderAccess::query()->create(['user_id' => $user->id, 'role' => 'provider_viewer']);

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }

    public function test_a_guest_is_not_held_and_still_sees_the_login_page(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));

        $this->get(route('login'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('auth/login'));
        // Beranda tidak dijaga `auth` dan tidak ada di daftar yang dilepas; yang melepas tamu di sana
        // hanya pemeriksaan tamu itu sendiri.
        $this->get(route('home'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('welcome'));
        // Halaman yang dijaga `auth` tetap mengalihkan tamu ke login, bukan menampilkan halaman kunci.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_login_is_reachable_by_a_locked_user_and_ends_in_the_lock_page_not_a_loop(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));
        $user = User::factory()->create();

        // Fortify mengalihkan akun yang sudah masuk dari /login ke beranda; beranda itulah yang
        // menjawab halaman kunci. Satu pengalihan, lalu berhenti.
        $this->actingAs($user)->get(route('login'))->assertRedirect();
        $this->actingAs($user)->followingRedirects()->get(route('login'))
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('license-locked'));
    }

    public function test_a_locked_user_can_always_log_out(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));

        $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect();

        $this->assertGuest();
    }

    public function test_the_health_check_answers_while_locked(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));

        $this->actingAs(User::factory()->create())->get('/up')->assertOk();
    }

    public function test_the_password_reset_routes_stay_reachable(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));

        // Rute Fortify yang menerima orang yang sudah masuk. Bila penjaga menahannya, jawabannya 403,
        // bukan 200.
        $this->actingAs(User::factory()->create())
            ->get(route('password.confirm'))
            ->assertOk();
    }

    // ------------------------------------------------------------------ tidak pernah mengunci

    /**
     * Test yang membuat kunci ini aman dipasang di grup web seluruh pemasangan.
     *
     * Lisensi yang habis, hilang, dan bertanda tangan salah — ketiga keadaan yang mengunci bila wajib
     * — tidak menahan siapa pun ketika lisensinya tidak diwajibkan.
     */
    #[DataProvider('everyLockingState')]
    public function test_an_installation_that_does_not_require_a_license_is_never_locked(string $case): void
    {
        config()->set('coreerp.license.required', false);

        match ($case) {
            'expired' => $this->installSignedLicense($this->licenseJson(now()->subYear()->toDateString(), [])),
            'invalid' => $this->installSignedLicense($this->licenseJson(now()->addYear()->toDateString()), signer: 'foreign'),
            'missing' => null,
            'unset' => config()->set('coreerp.license.path', null),
        };

        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->getJson('/api/v1/control/apps')->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function everyLockingState(): array
    {
        return [
            'habis' => ['expired'],
            'tanda tangan salah' => ['invalid'],
            'berkas hilang' => ['missing'],
            'tidak disetel' => ['unset'],
        ];
    }

    public function test_a_required_license_in_force_does_not_lock(): void
    {
        config()->set('coreerp.license.required', true);
        $this->installSignedLicense($this->licenseJson(now()->addDays(3)->toDateString(), []));

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('siteLicense.status', SiteLicenseState::EXPIRING)
                ->where('siteLicense.daysLeft', 3));
    }

    /** Perpanjangan membuka kunci pada permintaan berikutnya, tanpa pekerja PHP diganti. */
    public function test_a_renewed_license_unlocks_on_the_next_request(): void
    {
        $this->lockWith($this->licenseJson(now()->subDay()->toDateString()));
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();

        File::delete([$this->licensePath(), $this->licensePath().'.sig']);
        $this->installSignedLicense($this->licenseJson(now()->addDays(30)->toDateString()));

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    private function lockWith(string $licenseBytes): void
    {
        config()->set('coreerp.license.required', true);
        $this->installSignedLicense($licenseBytes);
    }
}
