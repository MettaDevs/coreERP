<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Platform\Reporting\Support\DaftarLaporanModul;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModulSedangDipindah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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
 *
 * **Tidak ada lagi angka tetap.** Sampai 28 September 2026 test ini juga memaku jumlah tiap
 * kelompok sebagai angka yang ditulis tangan, penjaga pemindahan module aset ke dalam repo pada
 * 10 September. Pemindahan itu sudah lama selesai, sedangkan angkanya menjadi satu tempat yang wajib
 * disunting setiap PR yang menambah izin atau laporan, dan setiap dua PR seperti itu bentrok di
 * sini. Yang tetap dijaga adalah hal pertama di atas: jumlahnya dihitung dari berkas manifest
 * module itu sendiri dan dari definisi laporannya, lalu harus sama dengan isi katalog.
 */
class RegisterAppManifestModuleTest extends TestCase
{
    use RefreshDatabase;

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

        // Laporan tidak ditulis di manifest: katalognya dibaca dari definisi laporan module.
        $diManifest = [
            ...$this->countEntriesInManifestFiles(),
            'reports' => count(app(DaftarLaporanModul::class)->untuk('management-aset')?->catalog() ?? []),
        ];

        foreach ($diManifest as $kelompok => $jumlah) {
            $this->assertGreaterThan(0, $jumlah, "Tidak ada {$kelompok} yang terbaca dari berkas manifest; hitungannya salah alamat.");
        }

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

    /**
     * Laporan module punya satu sumber: definisinya, dibaca lewat `PenyediaLaporanModul::catalog()`.
     * Blok `reports` yang masih ditulis di manifest ditolak, bukan diabaikan atau digabung,
     * karena dua sumber untuk satu katalog pasti menyimpang.
     */
    public function test_reports_block_in_module_manifest_is_rejected(): void
    {
        $this->salinModulAset('aset');
        file_put_contents(
            $this->akarSementara.'/apperp/aset/app.yaml',
            "reports:\n  - code: management-aset.laporan-tulisan-tangan\n",
            FILE_APPEND,
        );

        $this->artisan('app:register-manifest')
            ->expectsOutputToContain('masih memuat blok `reports`')
            ->assertFailed();

        $this->assertDatabaseMissing('apps', ['id' => 'management-aset']);
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
     * Manifestnya, `app.yaml` beserta folder `manifest/`, dipakai apa adanya karena yang
     * dibuktikan test ini justru jumlah barisnya.
     * Nama foldernya yang dibuat berbeda: nama folder itulah yang menentukan apakah sebuah
     * module masih terhitung sedang dipindah masuk.
     */
    private function salinModulAset(string $namaFolder): void
    {
        $tujuan = $this->akarSementara.'/apperp/'.$namaFolder;

        if (! is_dir($tujuan)) {
            mkdir($tujuan, 0777, true);
        }

        copy($this->assetModuleFolder().'/app.yaml', $tujuan.'/app.yaml');
        $this->copyFolder($this->assetModuleFolder().'/manifest', $tujuan.'/manifest');

        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($this->akarSementara));
    }

    private function assetModuleFolder(): string
    {
        return dirname(base_path(), 2).'/modules/apperp/management-aset';
    }

    /**
     * Jumlah baris tiap kelompok, dihitung langsung dari setiap berkas manifest module aset.
     *
     * Sengaja tidak memakai `ModuleManifestFiles`. Kalau pembaca itu melewatkan sebuah berkas,
     * perintah pendaftaran dan hitungan ini akan sama-sama kekurangan baris, dan test tetap hijau.
     *
     * @return array<string, int>
     */
    private function countEntriesInManifestFiles(): array
    {
        $files = [$this->assetModuleFolder().'/app.yaml'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->assetModuleFolder().'/manifest', RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'yaml') {
                $files[] = $file->getPathname();
            }
        }

        $counts = array_fill_keys(['data_policies', 'entry_points', 'permissions', 'privileges', 'duties', 'number_sequence_references', 'workflow_types'], 0);

        foreach ($files as $path) {
            /** @var array<string, mixed> $content */
            $content = Yaml::parseFile($path);
            $counts['data_policies'] += count($content['security']['data_policies'] ?? []);
            $counts['entry_points'] += count($content['security']['entry_points'] ?? []);
            $counts['permissions'] += count($content['security']['permissions'] ?? []);
            $counts['privileges'] += count($content['security']['privileges'] ?? []);
            $counts['duties'] += count($content['security']['duties'] ?? []);
            $counts['number_sequence_references'] += count($content['number_sequences']['references'] ?? []);
            $counts['workflow_types'] += count($content['workflow_types'] ?? []);
        }

        return $counts;
    }

    private function copyFolder(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $target = $to.'/'.substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? mkdir($target, 0o777, true) : copy($item->getPathname(), $target);
        }
    }

    /** @return array<string, int> */
    private function jumlahDiKatalog(string $appId): array
    {
        return [
            'data_policies' => DB::table('app_data_policies')->where('app_id', $appId)->count(),
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
