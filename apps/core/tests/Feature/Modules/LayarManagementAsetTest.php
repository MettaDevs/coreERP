<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Layar modul aset berjalan di dalam build shell, bukan di dalam iframe.
 *
 * `HalamanModuleShellTest` membuktikan bentuk ini pada module contoh — module yang ditulis
 * khusus sebagai bahan uji, dengan satu halaman dan dua entri menu. Berkas ini membuktikannya
 * pada modul produk yang sungguhan: 33 entri menu, izin per entri, dan alamat yang membawa
 * ruas milik satu record. Yang dijaga di sini adalah hal-hal yang tidak muncul pada module
 * satu halaman.
 *
 * Katalognya didaftarkan dari `app.yaml` lewat `app:register-manifest` — jalur yang sama
 * dengan yang dijalankan admin on-prem — supaya id entri menu yang diuji di sini tidak pernah
 * berbeda dari yang dipakai runtime.
 */
class LayarManagementAsetTest extends TestCase
{
    use RefreshDatabase;

    /** Entri menu yang dipakai contoh sepanjang berkas ini. Ada di `app.yaml` sejak F3-01. */
    private const MENU = 'group-aset';

    private User $pemilik;

    private User $tanpaIzin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NumberSequenceProfileSeeder::class);
        $this->artisan('app:register-manifest', ['module' => 'management-aset'])->assertSuccessful();

        $this->pemilik = app(RegisterBusiness::class)->handle([
            'name' => 'Pemilik',
            'business_name' => 'PT Layar Aset',
            'app_ids' => ['management-aset'],
            'email' => 'pemilik@aset.test',
            'password' => 'password',
        ]);

        // Anggota tenant yang sama tanpa satu pun role. Ini bentuk pelanggaran yang paling
        // mungkin terjadi di lapangan: bukan penyusup dari luar, melainkan rekan sekantor
        // yang belum diberi hak pada module ini.
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
        $tautan = $this->tautanMenu(self::MENU);

        $this->assertSame('/management-aset/'.self::MENU, $tautan, implode("\n", [
            'Menu module masih memakai tautan bergaya iframe (`/apps/<id>?view=…`).',
            'Modul ini berjalan di runtime yang sama dengan shell, jadi tautannya rute biasa.',
        ]));
    }

    public function test_layar_module_dirender_sebagai_halaman_inertia_module(): void
    {
        $this->actingAs($this->pemilik)
            ->get('/management-aset/'.self::MENU)
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $halaman) => $halaman
                    // `false` pada argumen kedua: nama halaman module memakai `::`, dan
                    // pemeriksaan bawaan menerjemahkannya sebagai jalur berkas.
                    ->component('management-aset::Modul', false)
                    ->where('view', self::MENU)
                    ->where('segments', [])
                    ->has('permissions')
                    ->has('konteks.user_id'),
            );
    }

    /**
     * Ruas sesudah id menu ikut sampai ke halaman.
     *
     * Ini yang menggantikan ruas sesudah tanda pagar. Tanpanya, layar yang membuka satu
     * record pada halaman tersendiri kehilangan tombol kembali peramban, muat ulang, dan
     * tautan yang bisa disalin — tiga hal yang dulu bekerja dan tidak boleh hilang dalam
     * pemindahan yang katanya tidak mengubah perilaku.
     */
    public function test_ruas_sesudah_id_menu_ikut_sampai_ke_halaman(): void
    {
        $this->actingAs($this->pemilik)
            ->get('/management-aset/'.self::MENU.'/01JQ8W2M4K/ubah')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $halaman) => $halaman
                    ->component('management-aset::Modul', false)
                    ->where('view', self::MENU)
                    ->where('segments', ['01JQ8W2M4K', 'ubah']),
            );
    }

    /**
     * Id menu yang tidak ada di manifest menjawab 404, bukan 403.
     *
     * Bedanya penting justru saat menu dan rute sedang tidak sejalan: 403 terbaca sebagai
     * masalah hak akses dan menghabiskan waktu orang di tempat yang salah.
     */
    public function test_id_menu_yang_tidak_ada_di_manifest_menjawab_404(): void
    {
        $this->actingAs($this->pemilik)
            ->get('/management-aset/menu-yang-tidak-pernah-ada')
            ->assertNotFound();
    }

    public function test_pengguna_tanpa_izin_tidak_melihat_menu_dan_tidak_bisa_membuka_layarnya(): void
    {
        $this->assertNull($this->tautanMenu(self::MENU, $this->tanpaIzin));

        $this->actingAs($this->tanpaIzin)
            ->get('/management-aset/'.self::MENU)
            ->assertForbidden();
    }

    /**
     * Kerangka layar tetap milik Core, dan itu yang membuat sidebar module muncul.
     */
    public function test_sidebar_module_ikut_terkirim_pada_layar_module(): void
    {
        $this->actingAs($this->pemilik)
            ->get('/management-aset/'.self::MENU)
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $halaman) => $halaman
                    ->where('app.id', 'management-aset')
                    ->where('app.navigation.activeItemId', self::MENU)
                    ->etc(),
            );
    }

    /**
     * Kriteria keluar fase 4, diperiksa pada berkas yang benar-benar dirender.
     *
     * Pemeriksaan berkas dibuat bisa gagal lebih dulu: berkas yang diperiksa wajib memuat
     * penanda yang pasti ada padanya, jadi berkas yang hilang, berpindah, atau terbaca kosong
     * menandai dirinya sendiri. "Tidak ada iframe di berkas yang tidak terbaca" bukan bukti
     * apa pun.
     */
    public function test_layar_module_tidak_memakai_iframe(): void
    {
        $akar = dirname(__DIR__, 3);
        $halamanModul = dirname($akar, 2).'/modules/apperp/management-aset/ui/Pages/Modul.tsx';

        $this->assertFileExists($halamanModul, implode("\n", [
            'Halaman Inertia modul aset tidak ada. Nama halaman `management-aset::Modul`',
            'diselesaikan pemilih halaman shell ke berkas ini; tanpa berkasnya, setiap layar',
            'modul gagal dimuat di peramban sementara seluruh test HTTP di atas tetap hijau.',
        ]));

        $isi = (string) file_get_contents($halamanModul);

        $this->assertStringContainsString(
            'export default',
            $isi,
            'Halaman modul tidak memuat `export default`; ia terbaca kosong atau bukan halaman '
            .'React, dan pemeriksaan iframe di bawahnya berhenti membuktikan apa pun.',
        );
        $this->assertStringNotContainsString('<iframe', $isi);
    }

    /**
     * Tautan entri menu untuk pengguna tertentu, atau null bila ia tidak melihatnya.
     */
    private function tautanMenu(string $idMenu, ?User $pengguna = null): ?string
    {
        $pengguna ??= $this->pemilik;

        $props = $this->actingAs($pengguna)
            ->get('/dashboard')
            ->assertOk()
            ->viewData('page')['props'] ?? [];

        $produk = collect($props['launchableProducts'] ?? []);

        if ($produk->firstWhere('id', 'management-aset') === null) {
            return null;
        }

        $halaman = $this->actingAs($pengguna)->get('/apps/management-aset');

        // Peluncur produk meneruskan module ke entri menu pertama yang boleh dilihat
        // pengguna ini. Yang dicari di sini entri tertentu, jadi menunya dibaca dari
        // kerangka module pada layar itu.
        $tujuan = $halaman->headers->get('Location');

        if ($tujuan === null) {
            return null;
        }

        $kerangka = $this->actingAs($pengguna)
            ->get($tujuan)
            ->viewData('page')['props']['app']['navigation']['rails'] ?? [];

        foreach ($kerangka as $rail) {
            foreach ($rail['items'] ?? [] as $item) {
                if (($item['id'] ?? null) === $idMenu) {
                    return $item['href'] ?? null;
                }
            }
        }

        return null;
    }
}
