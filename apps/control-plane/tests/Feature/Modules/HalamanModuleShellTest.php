<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\CoreApp;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\LaunchableAppCatalog;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Penjaga: jalur menu module — dari manifest, lewat katalog, sampai layar — benar-benar
 * tersambung.
 *
 * Yang diuji di sini bukan tampilan, melainkan empat sambungan yang masing-masing bisa
 * putus tanpa satu pun error:
 *
 * 1. **Tautan menu.** Menu module dan menu app container disaring izin dengan kode yang
 *    sama; yang berbeda hanya tujuannya. Sebuah module yang tetap mendapat tautan bergaya
 *    iframe (`/apps/<id>?view=…`) akan tampil normal di sidebar dan baru gagal saat diklik.
 * 2. **Rute yang dituju ada.** Tautan itu disusun dengan aturan `/<id module>/<id entri>`,
 *    jadi id entri pada manifest dan jalur pada berkas rute module harus sejalan. Keduanya
 *    dimiliki orang yang berbeda dan tidak ada yang mengikatnya selain test ini.
 * 3. **Sidebar terisi pada halaman module.** Kerangka layar dibagikan middleware konteks
 *    module. Kalau ia dibagikan di tempat yang salah — misalnya dari `HandleInertiaRequests`,
 *    yang menyusun prop bersama **sebelum** middleware rute berjalan — halamannya tetap
 *    terbuka, hanya tanpa menu.
 * 4. **Halaman yang dirender halaman module, bukan halaman iframe.** Satu-satunya `iframe`
 *    di shell ada pada `pages/apps/host.tsx`. Selama halaman module berkomponen
 *    `contoh-a::Daftar`, berkas itu tidak pernah ikut dirender.
 */
class HalamanModuleShellTest extends TestCase
{
    use RefreshDatabase;

    private const TAUTAN_MENU = '/contoh-a/daftar-barang';

    private User $pemilik;

    private User $tanpaIzin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coreerp.app_catalog', array_merge(
            (array) config('coreerp.app_catalog', []),
            [$this->katalogModuleContoh()],
        ));
        $this->seed(AppCatalogSeeder::class);

        $this->pemilik = app(RegisterBusiness::class)->handle([
            'name' => 'Pemilik',
            'business_name' => 'PT Layar',
            'app_ids' => ['contoh-a'],
            'email' => 'pemilik@layar.test',
            'password' => 'password',
        ]);

        $this->tanpaIzin = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $this->pemilik->activeMembership()->tenant_id,
            'user_id' => $this->tanpaIzin->id,
            'system_role' => 'user',
            'status' => 'active',
        ]);
    }

    public function test_menu_module_menunjuk_rute_module_bukan_halaman_iframe(): void
    {
        $menu = $this->menuModule();

        $this->assertSame(
            [['id' => 'daftar-barang', 'label' => 'Daftar barang', 'href' => self::TAUTAN_MENU]],
            $menu,
            'Menu module masih memakai tautan bergaya iframe. Module berjalan di runtime yang '
            .'sama, jadi entri menunya harus menunjuk rute module.',
        );
    }

    public function test_tautan_menu_module_mendarat_pada_rute_yang_terdaftar(): void
    {
        // Aturan `/<id module>/<id entri>` mengikat dua berkas yang tidak saling tahu:
        // manifest module dan berkas rutenya. Tanpa pemeriksaan ini, mengganti salah satu
        // menghasilkan menu yang tampil rapi lalu membalas 404.
        foreach ($this->menuModule() as $entri) {
            $this->assertNotNull(
                Route::getRoutes()->match(Request::create($entri['href'], 'GET')),
                'Tautan menu '.$entri['href'].' tidak menunjuk rute mana pun.',
            );
        }
    }

    public function test_halaman_module_menampilkan_data_dari_tabel_module(): void
    {
        $this->actingAs($this->pemilik)
            ->get(self::TAUTAN_MENU)
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                // Nama komponen berbentuk `<id module>::<berkas>`; pemilih halaman shell
                // yang menerjemahkannya ke folder `ui/Pages` milik module.
                //
                // Pemeriksaan keberadaan berkas bawaan Inertia dimatikan di sini karena ia
                // hanya tahu `resources/js/pages`. Yang menggantikannya bukan ketiadaan
                // pemeriksaan, melainkan test di bawah, yang memakai aturan penerjemahan
                // yang sama dengan shell.
                ->component('contoh-a::Daftar', false)
                ->has('barang', 2)
                ->where('barang.0.kode', 'BRG-BWN-01'));
    }

    public function test_nama_halaman_module_menunjuk_berkas_yang_benar_benar_ada(): void
    {
        $komponen = $this->actingAs($this->pemilik)
            ->get(self::TAUTAN_MENU)
            ->assertOk()
            ->viewData('page')['component'];

        [$modul, $halaman] = explode('::', $komponen);

        // Aturan yang sama dengan `resources/js/app.tsx`: penerbit tidak ikut disebut nama
        // halaman, jadi yang dicocokkan adalah akhiran jalurnya.
        // tests/Feature/Modules -> tests -> control-plane -> apps -> akar repo
        $berkas = glob(dirname(__DIR__, 5).'/modules/*/'.$modul.'/ui/Pages/'.$halaman.'.tsx');

        $this->assertNotEmpty(
            $berkas,
            'Nama halaman "'.$komponen.'" tidak menunjuk berkas mana pun di bawah '
            .'modules/<penerbit>/'.$modul.'/ui/Pages. Pemilih halaman shell akan melempar '
            .'"Halaman module tidak ditemukan" di peramban, jauh dari sini.',
        );
    }

    public function test_sidebar_module_ikut_terkirim_pada_halaman_module(): void
    {
        $this->actingAs($this->pemilik)
            ->get(self::TAUTAN_MENU)
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->where('app.id', 'contoh-a')
                ->where('app.name', 'Contoh A')
                ->where('app.navigation.activeItemId', 'daftar-barang')
                ->where('app.navigation.rails.0.items.0.href', self::TAUTAN_MENU));
    }

    public function test_halaman_core_tidak_ikut_menerima_kerangka_module(): void
    {
        // Sidebar membedakan layar app dari layar Core lewat keberadaan prop `app`, bukan
        // isinya. Sebuah prop `app` bernilai null yang ikut ke setiap halaman akan
        // mengosongkan menu Core di seluruh aplikasi.
        $this->actingAs($this->pemilik)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman->missing('app'));
    }

    public function test_peluncur_produk_meneruskan_module_ke_entri_menu_pertama(): void
    {
        // `/apps/<id>` adalah tautan yang dipakai peluncur untuk app container maupun
        // module. Untuk module ia tidak punya halaman tuan rumah, jadi ia harus meneruskan,
        // bukan membalas 404 dari pencarian penempatan container.
        $this->actingAs($this->pemilik)
            ->get('/apps/contoh-a')
            ->assertRedirect(self::TAUTAN_MENU);
    }

    public function test_pengguna_tanpa_izin_tidak_melihat_menu_dan_tidak_bisa_membuka_halaman(): void
    {
        $this->assertSame([], $this->menuModule($this->tanpaIzin));

        $this->actingAs($this->tanpaIzin)
            ->get(self::TAUTAN_MENU)
            ->assertForbidden();
    }

    public function test_halaman_module_dan_tuan_rumahnya_tidak_memakai_iframe(): void
    {
        $akar = dirname(__DIR__, 3);

        $this->assertStringNotContainsString(
            '<iframe',
            (string) file_get_contents($akar.'/resources/js/lib/halaman-module.tsx'),
        );
        $this->assertStringNotContainsString(
            '<iframe',
            (string) file_get_contents(dirname($akar, 2).'/modules/apperp/contoh-a/ui/Pages/Daftar.tsx'),
        );

        // Penutup untuk pemeriksanya sendiri. Dua pernyataan di atas akan tetap hijau kalau
        // berkasnya hilang, berpindah, atau dibaca kosong — dan "tidak ada iframe di berkas
        // yang tidak terbaca" bukan bukti apa pun. Halaman iframe yang lama masih ada di
        // repo dan memang memuat kata itu, jadi ia dipakai sebagai kendali positif.
        $this->assertStringContainsString(
            '<iframe',
            (string) file_get_contents($akar.'/resources/js/pages/apps/host.tsx'),
            'Halaman iframe lama tidak lagi memuat elemen `iframe`; pemeriksaan di atas '
            .'kehilangan kendali positifnya dan berhenti membuktikan apa pun.',
        );
    }

    /**
     * Entri menu module yang terlihat oleh seorang pengguna.
     *
     * @return list<array{id:string,label:string,href:string}>
     */
    private function menuModule(?User $pengguna = null): array
    {
        $pengguna ??= $this->pemilik;
        $membership = $pengguna->activeMembership();

        return array_values(collect(
            app(LaunchableAppCatalog::class)->navigationFor(
                $membership,
                CoreApp::query()->findOrFail('contoh-a'),
            )
        )->flatMap(fn (array $rail): array => $rail['items'])->all());
    }

    /**
     * Katalog module contoh, sama bentuknya dengan yang dipakai penjaga konteks permintaan.
     *
     * @return array<string, mixed>
     */
    private function katalogModuleContoh(): array
    {
        return [
            'id' => 'contoh-a',
            'name' => 'Contoh A',
            'version' => '0.1.0',
            'status' => 'available',
            'database' => 'core_erp',
            'has_ui' => true,
            'description' => 'Module contoh untuk menguji halaman module di shell.',
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
                [
                    'code' => 'contoh-a.barang.maintain',
                    'name' => 'Pelihara barang',
                    'permissions' => ['contoh-a.barang.read', 'contoh-a.barang.create'],
                ],
            ],
            'duties' => [
                [
                    'code' => 'contoh-a.barang.manage',
                    'name' => 'Kelola barang',
                    'privileges' => ['contoh-a.barang.maintain'],
                ],
            ],
        ];
    }
}
