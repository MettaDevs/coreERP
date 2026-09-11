<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModulSedangDipindah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Katalog didaftarkan dari module yang ada di dalam repo, bukan dari jalur berkas yang
 * diketik pemakai.
 *
 * Dua hal yang dijaga di sini, dan keduanya adalah hal yang bisa rusak diam-diam:
 *
 * 1. **Tidak ada kelompok manifest yang hilang.** Sebuah kelompok yang lupa dikirim ke
 *    action tidak membuat perintah gagal — ia hanya membuat katalog kekurangan baris, dan
 *    yang menemukannya adalah tenant yang izinnya tiba-tiba tidak ada.
 * 2. **Menjalankannya dua kali sama dengan sekali.** Pembaruan on-premise dijalankan admin
 *    pelanggan yang tidak punya cara mengetahui apakah perintahnya sudah pernah jalan.
 */
class RegisterAppManifestModuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Jumlah baris tiap kelompok manifest aset sebelum module dipindah ke dalam repo.
     *
     * Angka ini bukan hiasan: "sama persis dengan sebelum pemindahan" hanya dapat dibuktikan
     * bila ada angka sebelumnya yang tertulis. Bila salah satunya berubah, yang berubah
     * adalah `app.yaml` module — dan perubahan itu harus disengaja, bukan efek samping.
     */
    private const JUMLAH_SEBELUM_PEMINDAHAN = [
        'entry_points' => 65,
        'permissions' => 122,
        'privileges' => 64,
        'duties' => 36,
        'number_sequence_references' => 29,
        'workflow_types' => 2,
        'reports' => 2,
    ];

    private string $akarSementara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akarSementara = sys_get_temp_dir().'/coreerp-daftar-manifest-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->hapusFolder($this->akarSementara);

        parent::tearDown();
    }

    public function test_seluruh_kelompok_manifest_module_terdaftar_tanpa_ada_yang_tertinggal(): void
    {
        $this->salinModulAset('aset');

        $this->artisan('app:register-manifest')->assertSuccessful();

        $manifest = $this->manifestAset();

        $diManifest = [
            'entry_points' => count($manifest['security']['entry_points']),
            'permissions' => count($manifest['security']['permissions']),
            'privileges' => count($manifest['security']['privileges']),
            'duties' => count($manifest['security']['duties']),
            'number_sequence_references' => count($manifest['number_sequences']['references']),
            'workflow_types' => count($manifest['workflow_types']),
            'reports' => count($manifest['reports']),
        ];

        $this->assertSame(
            self::JUMLAH_SEBELUM_PEMINDAHAN,
            $diManifest,
            'Isi app.yaml module aset berubah jumlahnya dibanding sebelum pemindahan. Kalau '
            .'perubahan itu disengaja, angka acuan di test ini yang harus ikut diperbarui; '
            .'kalau tidak, ada baris manifest yang hilang atau tergandakan saat pemindahan.',
        );

        $this->assertSame(
            $diManifest,
            $this->jumlahDiKatalog('management-aset'),
            'Ada kelompok manifest yang tidak sampai ke katalog. Perintah pendaftaran tidak '
            .'gagal ketika satu kelompok tidak dikirim — katalog hanya kekurangan baris, dan '
            .'yang menemukannya adalah tenant yang izinnya tiba-tiba tidak ada.',
        );
    }

    public function test_menjalankan_pendaftaran_dua_kali_menghasilkan_katalog_yang_sama_persis(): void
    {
        $this->salinModulAset('aset');

        $this->artisan('app:register-manifest')->assertSuccessful();
        $sesudahSekali = $this->isiKatalog('management-aset');

        $this->artisan('app:register-manifest')->assertSuccessful();
        $sesudahDuaKali = $this->isiKatalog('management-aset');

        $this->assertSame(
            $sesudahSekali,
            $sesudahDuaKali,
            'Menjalankan pendaftaran dua kali mengubah isi katalog. Pembaruan on-premise '
            .'dijalankan admin pelanggan yang tidak punya cara tahu apakah perintahnya sudah '
            .'pernah jalan; kalau menjalankannya ulang menggandakan atau menggeser baris, '
            .'satu-satunya cara pulih adalah membereskan database pelanggan dengan tangan.',
        );
    }

    public function test_module_yang_sedang_dipindah_masuk_tidak_didaftarkan_ke_katalog(): void
    {
        $namaFolderDipindah = array_key_first(ModulSedangDipindah::bawaan()->semua());

        if ($namaFolderDipindah === null) {
            $this->markTestSkipped('Tidak ada module yang sedang dipindah, jadi tidak ada yang bisa dibuktikan tertahan.');
        }

        $this->salinModulAset($namaFolderDipindah);

        $this->artisan('app:register-manifest')->assertSuccessful();

        $this->assertDatabaseMissing('apps', ['id' => 'management-aset']);
        $this->assertSame(
            0,
            DB::table('permissions')->where('app_id', 'management-aset')->count(),
            'Module yang masih ada di daftar pemindahan masuk ke katalog. Katalog adalah '
            .'daftar yang boleh dipasang untuk tenant, sedangkan module yang sedang dipindah '
            .'belum tentu menyaring datanya dengan tenant_id; memasangnya berarti menaruh '
            .'data tenant di tabel yang tidak dijaga siapa pun.',
        );
    }

    public function test_module_contoh_tidak_pernah_masuk_katalog_pelanggan(): void
    {
        // Registry sungguhan, bukan salinan: kedua module contoh memang ada di repo.
        $this->artisan('app:register-manifest')->assertSuccessful();

        $this->assertDatabaseMissing('apps', ['id' => 'contoh-a']);
        $this->assertDatabaseMissing('apps', ['id' => 'contoh-b']);
    }

    public function test_module_yang_tidak_dilayani_ditolak_dengan_menyebut_yang_tersedia(): void
    {
        $this->salinModulAset('aset');

        $this->artisan('app:register-manifest', ['module' => 'app-yang-tidak-ada'])
            ->assertFailed();

        $this->assertDatabaseMissing('apps', ['id' => 'app-yang-tidak-ada']);
    }

    public function test_katalog_module_tidak_menyimpan_nama_database_sendiri(): void
    {
        $this->salinModulAset('aset');

        $this->artisan('app:register-manifest')->assertSuccessful();

        $this->assertNull(
            DB::table('apps')->where('id', 'management-aset')->value('database_name'),
            'Katalog mencatat nama database untuk sebuah module. Module berjalan di dalam '
            .'runtime Core dan memakai database Core; nama yang tercatat di sini adalah '
            .'database yang tidak pernah dibuat siapa pun, dan siapa pun yang mempercayainya '
            .'akan mencari data di tempat yang kosong.',
        );
    }

    /**
     * Menyalin manifest module aset yang sungguhan ke akar module sementara.
     *
     * Manifestnya dipakai apa adanya karena yang dibuktikan test ini justru jumlah barisnya.
     * Nama foldernya yang dibuat berbeda: nama folder itulah yang menentukan apakah sebuah
     * module masih terhitung sedang dipindah masuk.
     */
    private function salinModulAset(string $namaFolder): void
    {
        $tujuan = $this->akarSementara.'/apperp/'.$namaFolder;

        if (! is_dir($tujuan)) {
            mkdir($tujuan, 0777, true);
        }

        copy($this->berkasManifestAset(), $tujuan.'/app.yaml');

        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($this->akarSementara));
    }

    private function berkasManifestAset(): string
    {
        return dirname(base_path(), 2).'/modules/apperp/management-aset/app.yaml';
    }

    /** @return array<string, mixed> */
    private function manifestAset(): array
    {
        /** @var array<string, mixed> $isi */
        $isi = Yaml::parseFile($this->berkasManifestAset());

        return $isi;
    }

    /** @return array<string, int> */
    private function jumlahDiKatalog(string $appId): array
    {
        return [
            'entry_points' => DB::table('app_entry_points')->where('app_id', $appId)->count(),
            'permissions' => DB::table('permissions')->where('app_id', $appId)->count(),
            'privileges' => DB::table('security_privileges')->where('app_id', $appId)->count(),
            'duties' => DB::table('security_duties')->where('app_id', $appId)->count(),
            'number_sequence_references' => DB::table('app_number_sequence_references')->where('app_id', $appId)->count(),
            'workflow_types' => DB::table('workflow_types')->where('app_id', $appId)->count(),
            'reports' => DB::table('app_reports')->where('app_id', $appId)->count(),
        ];
    }

    /**
     * Isi katalog yang dibandingkan antar dua kali penjalanan.
     *
     * Yang dibandingkan kodenya, bukan hanya jumlahnya: sebuah penjalanan yang menghapus satu
     * baris lalu menambah baris lain menghasilkan jumlah yang sama dengan keadaan yang sama
     * sekali berbeda.
     *
     * @return array<string, list<string>>
     */
    private function isiKatalog(string $appId): array
    {
        $kode = static fn (string $tabel): array => DB::table($tabel)
            ->where('app_id', $appId)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        return [
            'entry_points' => $kode('app_entry_points'),
            'permissions' => $kode('permissions'),
            'privileges' => $kode('security_privileges'),
            'duties' => $kode('security_duties'),
            'number_sequence_references' => $kode('app_number_sequence_references'),
            'workflow_types' => $kode('workflow_types'),
            'reports' => $kode('app_reports'),
            'data_policies' => $kode('app_data_policies'),
            // Identitas baris ikut dibandingkan, bukan hanya kodenya. Baris yang kodenya sama
            // tetapi id-nya berganti tiap penjalanan tetap merupakan baris yang lain bagi
            // segala hal yang menunjuknya — layout laporan tenant, misalnya, ikut tergantung.
            'id_workflow_types' => $this->identitas('workflow_types', $appId),
            'id_reports' => $this->identitas('app_reports', $appId),
            'privilege_permissions' => DB::table('security_privilege_permissions')
                ->orderBy('privilege_code')->orderBy('permission_code')
                ->get()
                ->map(static fn (object $baris): string => $baris->privilege_code.' -> '.$baris->permission_code)
                ->all(),
            'duty_privileges' => DB::table('security_duty_privileges')
                ->orderBy('duty_code')->orderBy('privilege_code')
                ->get()
                ->map(static fn (object $baris): string => $baris->duty_code.' -> '.$baris->privilege_code)
                ->all(),
        ];
    }

    /** @return array<string, string> */
    private function identitas(string $tabel, string $appId): array
    {
        return DB::table($tabel)
            ->where('app_id', $appId)
            ->orderBy('code')
            ->pluck('id', 'code')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function hapusFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        foreach (scandir($folder) ?: [] as $isi) {
            if ($isi === '.' || $isi === '..') {
                continue;
            }

            $jalur = $folder.'/'.$isi;
            is_dir($jalur) ? $this->hapusFolder($jalur) : unlink($jalur);
        }

        rmdir($folder);
    }
}
