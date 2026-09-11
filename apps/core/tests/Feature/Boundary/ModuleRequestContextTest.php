<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Modules\Contracts\KonteksPermintaan;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Penjaga: rute module terlindungi tanpa token, dan konteksnya berbentuk sama seperti dulu.
 *
 * Dua hal yang dijaga di sini, dan keduanya mudah rusak diam-diam:
 *
 * 1. **Perlindungan.** Rute module tidak lagi memverifikasi token, jadi satu-satunya yang
 *    berdiri di depannya adalah middleware konteks. Kalau middleware itu lepas dari grup
 *    rute — misalnya karena berkas rute module ditulis ulang — rute itu terbuka untuk
 *    siapa pun yang sudah masuk, termasuk pengguna dari tenant yang sama yang tidak
 *    memegang izin apa pun pada module tersebut.
 * 2. **Bentuk konteks.** Kunci atribut permintaan disalin apa adanya dari middleware app
 *    lama. Nilainya sekarang datang dari sesi Core, bukan dari token, tetapi 22 berkas pada
 *    modul aset membaca kunci itu langsung. Mengganti satu huruf pada salah satu kunci akan
 *    membuat 22 berkas membaca null tanpa satu pun error — kebocoran diam, bukan kegagalan
 *    berisik. Karena itu daftar kuncinya ditulis ulang secara harfiah di test ini, bukan
 *    diambil dari konstanta kelas yang diuji; membandingkan sebuah konstanta dengan dirinya
 *    sendiri tidak membuktikan apa pun.
 */
class ModuleRequestContextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kunci atribut yang ditulis middleware app lama, disalin dari
     * `api/app/Http/Middleware/RequireCoreErpContext.php` pada repo Management Aset.
     *
     * @var list<string>
     */
    private const KUNCI_APP_LAMA = [
        'coreerp.data_policies',
        'coreerp.legal_entity_id',
        'coreerp.org_unit_id',
        'coreerp.permissions',
        'coreerp.tenant_id',
        'coreerp.user_id',
    ];

    private const KEBIJAKAN = 'contoh-a.barang-terlihat';

    private User $pemilik;

    private User $tanpaIzin;

    private string $entitasLegalId;

    private string $unitOperasiId;

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
            'business_name' => 'PT Konteks',
            'app_ids' => ['contoh-a'],
            'email' => 'pemilik@konteks.test',
            'password' => 'password',
        ]);

        $membership = $this->pemilik->activeMembership();
        $this->entitasLegalId = $this->organisasi($membership->tenant_id, 'PT Konteks Pusat', 'legal_entity');
        $this->unitOperasiId = $this->organisasi($membership->tenant_id, 'Gudang Utara', 'operating_unit');
        $this->beriKebijakanData($membership);

        // Anggota tenant yang sama, tanpa satu pun role. Ini bentuk pelanggaran yang paling
        // mungkin terjadi di lapangan: bukan penyusup dari luar, melainkan rekan sekantor
        // yang belum diberi hak pada module ini.
        $this->tanpaIzin = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $membership->tenant_id,
            'user_id' => $this->tanpaIzin->id,
            'system_role' => 'user',
            'status' => 'active',
        ]);
    }

    public function test_rute_module_contoh_dimuat_oleh_penyedia_layanan_module(): void
    {
        // Sampai F2-10 berkas rute module tidak dimuat siapa pun. Kalau penyedia layanan
        // module hilang, rute ini lenyap dan semua test lain di berkas ini gagal dengan 404
        // yang membingungkan; yang ini menjelaskan sebabnya lebih dulu.
        $this->assertNotNull(
            Route::getRoutes()->getByName('contoh-a.barang.index'),
            'Rute module contoh belum terdaftar; penyedia layanan module tidak memuat berkas rutenya.',
        );
    }

    public function test_pengguna_tanpa_izin_ditolak_pada_rute_module(): void
    {
        $this->actingAs($this->tanpaIzin)
            ->getJson('/contoh-a/barang')
            ->assertForbidden();
    }

    public function test_middleware_menolak_sebelum_controller_module_sempat_berjalan(): void
    {
        // Test di atas tidak cukup sendirian, dan ini diketahui dari percobaan: dengan
        // penolakan di middleware dihapus, test itu tetap hijau karena controller module
        // ikut memeriksa izin. Dua lapis memang disengaja, tetapi lapis pertama harus bisa
        // dibuktikan sendiri — kalau tidak, hilangnya lapis pertama tidak akan terlihat
        // sampai ada satu module yang lupa memeriksa di controllernya.
        Route::middleware(['web', 'auth', 'konteks-module:contoh-a'])
            ->get('/uji/tanpa-pemeriksaan-controller', fn (): JsonResponse => new JsonResponse(['sampai' => true]));

        $this->actingAs($this->tanpaIzin)
            ->getJson('/uji/tanpa-pemeriksaan-controller')
            ->assertForbidden();
    }

    public function test_pengguna_dengan_izin_dilayani_tanpa_token_apa_pun(): void
    {
        $respons = $this->actingAs($this->pemilik)
            ->getJson('/contoh-a/barang')
            ->assertOk();

        // Data awal module ikut terpasang saat tenant mendaftar; dua baris itu membuktikan
        // rute benar-benar berjalan sampai ke tabel module, bukan sekadar mengembalikan 200.
        $this->assertCount(2, $respons->json('data'));
    }

    public function test_atribut_permintaan_memakai_kunci_yang_sama_dengan_app_lama(): void
    {
        $atribut = $this->konteksPermintaan();

        $kunci = array_keys($atribut);
        sort($kunci);

        $this->assertSame(
            self::KUNCI_APP_LAMA,
            $kunci,
            'Kunci atribut berubah. App lama membaca kunci ini langsung; menggantinya membuat pembacaannya diam-diam menghasilkan null.',
        );
    }

    public function test_isi_konteks_diambil_dari_sesi_core_bukan_dari_token(): void
    {
        $membership = $this->pemilik->activeMembership();
        $atribut = $this->konteksPermintaan();

        $this->assertSame($membership->tenant_id, $atribut['coreerp.tenant_id']);
        $this->assertSame((string) $this->pemilik->id, $atribut['coreerp.user_id']);
        $this->assertSame($this->entitasLegalId, $atribut['coreerp.legal_entity_id']);
        $this->assertSame($this->unitOperasiId, $atribut['coreerp.org_unit_id']);
        $this->assertContains('contoh-a.barang.read', $atribut['coreerp.permissions']);
        $this->assertSame(
            ['legal_entity_id' => $this->entitasLegalId, 'operating_unit_ids' => [$this->unitOperasiId]],
            $atribut['coreerp.data_policies'][self::KEBIJAKAN]['scope_grants'][0],
        );
    }

    public function test_izin_yang_diisi_hanya_milik_module_yang_dilayani(): void
    {
        $atribut = $this->konteksPermintaan();

        // Awalan module lain tidak boleh ikut masuk. Kalau ia ikut, sebuah module bisa
        // membaca izin module lain dan memutuskan sesuatu atas namanya.
        foreach ($atribut['coreerp.permissions'] as $kode) {
            $this->assertStringStartsWith('contoh-a.', $kode);
        }
    }

    public function test_konteks_menjawab_izin_yang_dipegang_dan_menolak_yang_tidak(): void
    {
        Route::middleware(['web', 'auth', 'konteks-module:contoh-a'])
            ->get('/uji/izin', fn (KonteksPermintaan $akses): JsonResponse => new JsonResponse([
                'dipegang' => $akses->punyaIzin('contoh-a.barang.read'),
                'tidak_dipegang' => $akses->punyaIzin('contoh-a.barang.hapus'),
                'pengguna' => $akses->penggunaId(),
            ]));

        $respons = $this->actingAs($this->pemilik)->getJson('/uji/izin')->assertOk();

        $this->assertTrue($respons->json('dipegang'));
        $this->assertFalse($respons->json('tidak_dipegang'));
        $this->assertSame((string) $this->pemilik->id, $respons->json('pengguna'));
    }

    public function test_tanpa_middleware_konteks_jawabannya_tidak_punya_izin(): void
    {
        // Gagal menutup. Sebuah rute module yang lupa dipasangi middleware konteks harus
        // menjawab "tidak punya izin", bukan "punya semua izin" — dan pembacaan id pengguna
        // harus melempar, bukan mengembalikan string kosong yang lalu masuk ke jejak audit.
        Route::middleware(['web', 'auth'])
            ->get('/uji/tanpa-konteks', function (KonteksPermintaan $akses): JsonResponse {
                $melempar = false;

                try {
                    $akses->penggunaId();
                } catch (RuntimeException) {
                    $melempar = true;
                }

                return new JsonResponse([
                    'punya_izin' => $akses->punyaIzin('contoh-a.barang.read'),
                    'izin' => $akses->izin(),
                    'kebijakan' => $akses->kebijakanData(),
                    'pengguna_melempar' => $melempar,
                ]);
            });

        $respons = $this->actingAs($this->pemilik)->getJson('/uji/tanpa-konteks')->assertOk();

        $this->assertFalse($respons->json('punya_izin'));
        $this->assertSame([], $respons->json('izin'));
        $this->assertSame([], $respons->json('kebijakan'));
        $this->assertTrue($respons->json('pengguna_melempar'));
    }

    /**
     * Atribut `coreerp.*` yang benar-benar terpasang pada sebuah permintaan rute module.
     *
     * Rute bantu ini memakai grup middleware yang sama dengan rute module contoh. Ia ada
     * karena controller module hanya mengembalikan datanya sendiri, sedangkan yang perlu
     * dilihat di sini adalah isi tas atributnya.
     *
     * @return array<string, mixed>
     */
    private function konteksPermintaan(): array
    {
        Route::middleware(['web', 'auth', 'konteks-module:contoh-a'])
            ->get('/uji/konteks', function (Request $permintaan): JsonResponse {
                $terpilih = [];

                foreach ($permintaan->attributes->all() as $kunci => $nilai) {
                    if (str_starts_with((string) $kunci, 'coreerp.')) {
                        $terpilih[(string) $kunci] = $nilai;
                    }
                }

                return new JsonResponse(['atribut' => $terpilih]);
            });

        return (array) $this->actingAs($this->pemilik)
            ->getJson('/uji/konteks')
            ->assertOk()
            ->json('atribut');
    }

    private function organisasi(string $tenantId, string $nama, string $klasifikasi): string
    {
        $id = (string) Str::ulid();

        DB::table('organizations')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $nama,
            'classification' => $klasifikasi,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Satu kebijakan data dengan lingkup terbatas, supaya isinya bisa dibedakan dari kosong.
     *
     * Kebijakan sengaja dipasang setelah pendaftaran tenant. Pendaftaran memberi pemilik
     * seluruh lingkup kebijakan app yang di-entitle, dan lingkup "semua" tidak membuktikan
     * bahwa batas organisasinya ikut terbawa ke atribut permintaan.
     */
    private function beriKebijakanData(TenantMembership $membership): void
    {
        DB::table('app_data_policies')->insert([
            'code' => self::KEBIJAKAN,
            'app_id' => 'contoh-a',
            'name' => 'Barang terlihat menurut unit',
            'protected_permissions' => json_encode(['contoh-a.barang.read'], JSON_THROW_ON_ERROR),
            'requires_legal_entity' => true,
            'requires_operating_unit' => true,
            'allows_descendants' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_assignment_data_policy_scopes')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $membership->tenant_id,
            'role_assignment_id' => $membership->roleAssignments()->firstOrFail()->id,
            'policy_code' => self::KEBIJAKAN,
            'legal_entity_id' => $this->entitasLegalId,
            'organization_id' => $this->unitOperasiId,
            'hierarchy_id' => null,
            'hierarchy_version_id' => null,
            'include_descendants' => false,
            'valid_from' => now(),
            'valid_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Katalog module contoh, dengan rantai empat lapis yang sama seperti app sungguhan.
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
            // Module berbagi database dengan Core; kolom ini masih wajib diisi (F2-12).
            'database' => 'core_erp',
            'has_ui' => true,
            'description' => 'Module contoh untuk menguji konteks permintaan.',
            'navigation' => [
                'rail' => [['id' => 'master', 'label' => 'Master data']],
                'sidebar' => [
                    'master' => [
                        ['id' => 'barang', 'label' => 'Barang', 'permission' => 'contoh-a.barang.read'],
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
