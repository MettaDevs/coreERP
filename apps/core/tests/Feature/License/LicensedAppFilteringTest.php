<?php

declare(strict_types=1);

namespace Tests\Feature\License;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\AppServiceCredential;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\LaunchableAppCatalog;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\WritesSiteLicenses;
use Tests\TestCase;

/**
 * App yang tidak ada di lisensi tertutup di keempat pintunya, walaupun database mengatakan tenant
 * berhak dan app-nya terpasang.
 *
 * Itulah kriteria terima yang dijaga di sini: `tenant_app_entitlements` dan pemasangan module tinggal
 * di database server klien, dan siapa pun yang memegang server itu dapat menambah barisnya. Setiap
 * test di bawah karena itu memakai tenant yang **benar-benar** berhak dan terpasang atas `contoh-a`
 * lewat pendaftaran usaha yang sungguhan — satu-satunya yang membuat app itu tertutup adalah daftar
 * app di lisensi.
 *
 * Tiap pintu diuji dua arah dari susunan yang sama: terbuka ketika lisensi mencantumkan app-nya, dan
 * tertutup ketika tidak. Arah terbuka membuktikan susunan test-nya sehat; tanpa itu, "tertutup" dapat
 * lulus hanya karena app-nya memang tidak pernah dapat dibuka.
 */
final class LicensedAppFilteringTest extends TestCase
{
    use RefreshDatabase;
    use WritesSiteLicenses;

    private const MODULE = 'contoh-a';

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->prepareSiteLicenseDirectory();

        config()->set('coreerp.app_catalog', array_merge(
            (array) config('coreerp.app_catalog', []),
            [$this->exampleModuleCatalog()],
        ));
        $this->seed(AppCatalogSeeder::class);

        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Pemilik',
            'business_name' => 'PT Lisensi',
            'app_ids' => [self::MODULE],
            'email' => 'pemilik@lisensi.test',
            'password' => 'password',
        ]);

        $membership = $this->owner->activeMembership();
        $this->assertNotNull($membership);
        $this->membership = $membership;

        // Pengaman susunan: hak dan pemasangan memang ada di database. Kalau salah satunya hilang,
        // setiap "tertutup" di bawah akan lulus karena alasan yang salah.
        $this->assertDatabaseHas('tenant_app_entitlements', ['tenant_id' => $this->membership->tenant_id, 'app_id' => self::MODULE, 'status' => 'active']);
        $this->assertDatabaseHas('core_module_installations', ['tenant_id' => $this->membership->tenant_id, 'module_id' => self::MODULE]);
    }

    protected function tearDown(): void
    {
        try {
            $this->removeSiteLicenseDirectory();
        } finally {
            parent::tearDown();
        }
    }

    // ------------------------------------------------------------------ ResolveModuleContext

    public function test_a_module_page_opens_when_the_license_lists_the_app(): void
    {
        $this->licenseListing([self::MODULE]);

        $this->actingAs($this->owner)->get('/contoh-a/daftar-barang')->assertOk();
        $this->actingAs($this->owner)->getJson('/contoh-a/barang')->assertOk();
    }

    public function test_a_module_page_and_its_json_route_are_forbidden_when_the_license_does_not_list_the_app(): void
    {
        $this->licenseListing(['human-resources']);

        $this->actingAs($this->owner)->get('/contoh-a/daftar-barang')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/contoh-a/barang')->assertForbidden();
    }

    // ------------------------------------------------------------------ LaunchableAppCatalog::for()

    public function test_the_launcher_lists_a_licensed_app(): void
    {
        $this->licenseListing([self::MODULE]);

        $this->assertSame([self::MODULE], $this->launchableIds());
        $this->actingAs($this->owner)->get('/apps/contoh-a')->assertRedirect('/contoh-a/daftar-barang');
    }

    public function test_the_launcher_launch_manifest_and_app_link_hide_an_unlicensed_app(): void
    {
        $this->licenseListing([]);

        $this->assertSame([], $this->launchableIds());
        $this->actingAs($this->owner)->getJson('/api/v1/launch-manifest')->assertOk()->assertJsonCount(0, 'data.apps');
        $this->actingAs($this->owner)->get('/apps/contoh-a')->assertForbidden();
    }

    // ------------------------------------------------------------------ HandleInertiaRequests

    public function test_owned_products_include_a_licensed_app(): void
    {
        $this->licenseListing([self::MODULE]);

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entitledProducts', 1)
            ->where('entitledProducts.0.id', self::MODULE)
            ->has('launchableProducts', 1));
    }

    public function test_owned_products_omit_an_unlicensed_app(): void
    {
        $this->licenseListing(['human-resources']);

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entitledProducts', 0)
            ->has('launchableProducts', 0));
    }

    // ------------------------------------------------------------------ AuthenticateAppService

    public function test_an_internal_call_from_a_licensed_app_is_accepted(): void
    {
        $this->licenseListing([self::MODULE]);

        $this->internalCall()->assertOk();
    }

    public function test_an_internal_call_from_an_unlicensed_app_is_refused(): void
    {
        $this->licenseListing(['human-resources']);

        $this->internalCall()->assertForbidden();
    }

    public function test_an_internal_call_is_refused_while_the_license_is_locked_even_for_a_listed_app(): void
    {
        config()->set('coreerp.license.required', true);
        $this->installSignedLicense($this->licenseJson(now()->subDay()->toDateString(), [self::MODULE]));

        $this->internalCall()->assertForbidden();
    }

    // ------------------------------------------------------------------ tidak wajib

    /** Tanpa lisensi wajib, lisensi yang tidak mencantumkan apa pun tidak menutup satu pintu pun. */
    public function test_every_door_stays_open_when_the_license_is_not_required(): void
    {
        $this->installSignedLicense($this->licenseJson(now()->addMonth()->toDateString(), []));
        config()->set('coreerp.license.required', false);

        $this->actingAs($this->owner)->get('/contoh-a/daftar-barang')->assertOk();
        $this->assertSame([self::MODULE], $this->launchableIds());
        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->has('entitledProducts', 1));
        $this->internalCall()->assertOk();
    }

    // ------------------------------------------------------------------ pembantu

    /** @param  list<string>  $apps */
    private function licenseListing(array $apps): void
    {
        config()->set('coreerp.license.required', true);
        $this->installSignedLicense($this->licenseJson(now()->addMonth()->toDateString(), $apps));
    }

    /** @return list<string> */
    private function launchableIds(): array
    {
        // Ikatan scoped dibuang supaya config dan berkas yang baru ditulis benar-benar dibaca ulang.
        $this->app->forgetScopedInstances();

        return array_column(app(LaunchableAppCatalog::class)->for($this->membership), 'id');
    }

    /**
     * Panggilan `internal/v1` sebagai app module itu sendiri, dengan kredensial yang terikat tenant.
     *
     * `units-of-measure` dipilih karena jawabannya tidak bergantung pada apa pun selain penjaganya:
     * 200 berarti penjaga melepas, 403 berarti menolak.
     *
     * @return TestResponse<Response>
     */
    private function internalCall(): TestResponse
    {
        [, $token] = AppServiceCredential::issueToken(self::MODULE, 'uji-lisensi', (string) $this->membership->tenant_id);

        return $this->withHeaders([
            'X-CoreERP-App-Id' => self::MODULE,
            'X-CoreERP-Service-Token' => $token,
            'X-CoreERP-Tenant-Id' => (string) $this->membership->tenant_id,
        ])->getJson('/api/internal/v1/units-of-measure');
    }

    /**
     * Katalog module contoh, sama bentuknya dengan yang dipakai `HalamanModuleShellTest`.
     *
     * @return array<string, mixed>
     */
    private function exampleModuleCatalog(): array
    {
        return [
            'id' => self::MODULE,
            'name' => 'Contoh A',
            'version' => '0.1.0',
            'status' => 'available',
            'database' => 'core_erp',
            'has_ui' => true,
            'description' => 'Module contoh untuk menguji saringan lisensi.',
            'navigation' => [
                'rail' => [['id' => 'master', 'label' => 'Master data']],
                'sidebar' => [
                    'master' => [
                        ['id' => 'daftar-barang', 'label' => 'Daftar barang', 'permission' => 'contoh-a.barang.read'],
                    ],
                ],
            ],
            'entry_points' => [
                ['code' => 'contoh-a.barang.form', 'name' => 'Layar barang', 'type' => 'form'],
                ['code' => 'contoh-a.barang.api', 'name' => 'API barang', 'type' => 'api'],
            ],
            'permissions' => [
                ['code' => 'contoh-a.barang.read', 'name' => 'Lihat barang', 'entry_point' => 'contoh-a.barang.form', 'access' => 'read'],
                ['code' => 'contoh-a.barang.create', 'name' => 'Tambah barang', 'entry_point' => 'contoh-a.barang.api', 'access' => 'create'],
            ],
            'privileges' => [
                ['code' => 'contoh-a.barang.maintain', 'name' => 'Pelihara barang', 'permissions' => ['contoh-a.barang.read', 'contoh-a.barang.create']],
            ],
            'duties' => [
                ['code' => 'contoh-a.barang.manage', 'name' => 'Kelola barang', 'privileges' => ['contoh-a.barang.maintain']],
            ],
        ];
    }
}
