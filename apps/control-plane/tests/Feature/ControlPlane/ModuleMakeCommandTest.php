<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Console\Commands\ModuleMakeCommand;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Perintah `module:make`, dijalankan atas repo tiruan di folder sementara.
 *
 * **Kenapa repo tiruan, dan bukan repo sungguhan.** Perintah ini menulis ke tiga tempat yang
 * ikut ter-commit: `modules/<penerbit>/<module>/`, tabel pemetaan pada `modules/README.md`,
 * dan blok `require` pada `composer.json` Core. Menjalankannya atas repo sungguhan berarti
 * sebuah run yang gagal di tengah meninggalkan module setengah jadi yang lalu terbaca
 * `module:list`, penjaga batas, Pint, dan PHPStan — dan yang paling berbahaya, entri
 * `composer.json` yang menunjuk folder yang sudah dihapus.
 *
 * **Cetakannya sungguhan, bukan tiruan.** `modules/_template/` yang asli disalin ke dalam repo
 * tiruan, jadi test ini ikut gagal bila cetakannya dihapus, berpindah, atau kehilangan salah
 * satu berkas yang membuat module baru lulus penjaga batas. Cetakan tiruan akan membuat test
 * ini hijau selamanya sambil menguji sesuatu yang tidak pernah dipakai siapa pun.
 *
 * Perintahnya dijalankan langsung, bukan lewat `Artisan::call`. Penemuan perintah bersandar
 * pada `app_path()`, yang ikut berpindah bersama base path repo tiruan; menjalankannya
 * langsung membuat test ini menguji perintahnya, bukan penemuannya.
 */
class ModuleMakeCommandTest extends TestCase
{
    private string $akarSementara;

    private string $basePathAsli;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePathAsli = $this->app->basePath();
        $this->akarSementara = sys_get_temp_dir().'/coreerp-module-make-'.bin2hex(random_bytes(6));

        $this->siapkanRepoTiruan();
        $this->app->setBasePath($this->akarSementara.'/apps/control-plane');
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->basePathAsli);
        $this->hapusFolder($this->akarSementara);

        parent::tearDown();
    }

    public function test_module_dibuat_dari_cetakan_tanpa_satu_pun_penanda_tersisa(): void
    {
        $keluaran = $this->jalankan(['module' => 'kelola-contoh', '--nama' => 'Kelola Contoh', '--awalan' => 'kelola_']);

        $this->assertSame(0, $keluaran['status'], $keluaran['teks']);

        $folder = $this->akarSementara.'/modules/apperp/kelola-contoh';
        $berkas = $this->berkasDi($folder);

        $this->assertSame([
            'app.yaml',
            'composer.json',
            'routes/web.php',
            'src/Http/Controllers/HalamanContohController.php',
            'src/Models/Contoh.php',
            'src/ModuleServiceProvider.php',
            'tests/Feature/PenyaringanTenantTest.php',
            'ui/Pages/Daftar.tsx',
        ], array_values(array_filter($berkas, static fn (string $jalur): bool => ! str_starts_with($jalur, 'database/migrations/'))));

        // README cetakan bercerita tentang cetakannya, bukan tentang module yang dibuat
        // darinya; ikut tersalin, ia menjadi dokumen yang salah alamat.
        $this->assertFileDoesNotExist($folder.'/README.md');

        $tersisa = [];

        foreach ($berkas as $jalur) {
            $isi = (string) file_get_contents($folder.'/'.$jalur);

            foreach (['change-me', 'ChangeMe', 'change_me_', 'Change Me', 'penerbit-contoh', 'PenerbitContoh'] as $penanda) {
                if (str_contains($isi, $penanda) || str_contains($jalur, $penanda)) {
                    $tersisa[] = $jalur.' masih memuat "'.$penanda.'"';
                }
            }
        }

        $this->assertSame([], $tersisa, implode("\n", [
            'Ada penanda cetakan yang tidak terganti.',
            'Penanda yang tertinggal tidak gagal dengan sendirinya: ia menjadi nama tabel, kode izin,',
            'atau namespace yang salah, dan salahnya baru terlihat saat migration jalan.',
        ]));
    }

    public function test_tiap_bentuk_penanda_diganti_menurut_bentuknya_sendiri(): void
    {
        $this->jalankan(['module' => 'kelola-contoh', '--nama' => 'Kelola Contoh', '--awalan' => 'kelola_']);

        $folder = $this->akarSementara.'/modules/apperp/kelola-contoh';

        $manifest = Yaml::parseFile($folder.'/app.yaml');
        $this->assertIsArray($manifest);

        $this->assertSame('kelola-contoh', $manifest['id'] ?? null);
        $this->assertSame('Kelola Contoh', $manifest['name'] ?? null);
        $this->assertSame('apperp', $manifest['publisher'] ?? null);
        $this->assertSame('kelola_', $manifest['table_prefix'] ?? null);

        $composer = json_decode((string) file_get_contents($folder.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);

        $this->assertSame('apperp/kelola-contoh', $composer['name'] ?? null);
        $this->assertSame(
            ['Modules\\Apperp\\KelolaContoh\\' => 'src/'],
            $composer['autoload']['psr-4'] ?? null,
        );

        $this->assertStringContainsString(
            'namespace Modules\\Apperp\\KelolaContoh\\Models;',
            (string) file_get_contents($folder.'/src/Models/Contoh.php'),
        );

        $this->assertStringContainsString(
            "protected \$table = 'kelola_m_contoh';",
            (string) file_get_contents($folder.'/src/Models/Contoh.php'),
        );

        $this->assertStringContainsString(
            "'web', 'auth', 'konteks-module:kelola-contoh'",
            (string) file_get_contents($folder.'/routes/web.php'),
        );
    }

    /**
     * Cap waktu migration diambil dari waktu pembuatan, bukan dari cetakannya.
     *
     * Cap waktu yang diwarisi apa adanya membuat setiap module yang pernah dibuat dari cetakan
     * ini membawa cap yang sama, sehingga urutan migration di antara mereka ditentukan urutan
     * abjad nama berkas — bukan urutan yang dimaksudkan siapa pun.
     */
    public function test_migration_memakai_cap_waktu_pembuatan_dan_nama_tabel_berawalan(): void
    {
        $this->jalankan(['module' => 'kelola-contoh', '--awalan' => 'kelola_']);

        $migration = array_values(array_filter(
            $this->berkasDi($this->akarSementara.'/modules/apperp/kelola-contoh'),
            static fn (string $jalur): bool => str_starts_with($jalur, 'database/migrations/'),
        ));

        $this->assertCount(1, $migration);
        $this->assertMatchesRegularExpression(
            '/^database\/migrations\/'.now()->format('Y_m_d').'_\d{6}_create_kelola_m_contoh_table\.php$/',
            $migration[0],
        );
    }

    public function test_awalan_tabel_didaftarkan_pada_tabel_pemetaan_readme(): void
    {
        $this->jalankan(['module' => 'kelola-contoh', '--awalan' => 'kelola_']);

        $baris = preg_split('/\R/', (string) file_get_contents($this->akarSementara.'/modules/README.md'));
        $this->assertIsArray($baris);

        $tabel = array_values(array_filter($baris, static fn (string $b): bool => str_starts_with($b, '| `apperp/')));

        // Disisipkan menurut urutan, bukan ditempel di ujung: tabel yang urutannya acak membuat
        // tabrakan awalan lebih sulit terlihat justru saat peninjauan, yaitu satu-satunya saat
        // tabel ini berguna.
        $this->assertSame([
            '| `apperp/contoh-a` | `Modules\Apperp\ContohA\` | `contoh_a_` |',
            '| `apperp/kelola-contoh` | `Modules\Apperp\KelolaContoh\` | `kelola_` |',
            '| `apperp/zeta` | `Modules\Apperp\Zeta\` | `zeta_` |',
        ], $tabel);
    }

    public function test_package_module_didaftarkan_pada_composer_core(): void
    {
        $this->jalankan(['module' => 'kelola-contoh', '--awalan' => 'kelola_']);

        $isi = (string) file_get_contents($this->akarSementara.'/apps/control-plane/composer.json');

        $composer = json_decode($isi, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);

        $require = $composer['require'] ?? [];
        $this->assertIsArray($require);

        $this->assertSame(
            ['php', 'apperp/contoh-a', 'apperp/kelola-contoh', 'apperp/zeta', 'laravel/framework'],
            array_keys($require),
            'Package module disisipkan di luar urutan sort-packages Composer.',
        );
        $this->assertSame('@dev', $require['apperp/kelola-contoh'] ?? null);
    }

    /**
     * @return list<array{string}>
     */
    public static function idYangTidakSah(): array
    {
        return [
            'huruf besar' => ['Kelola-Contoh'],
            'garis bawah' => ['kelola_contoh'],
            'terlalu pendek' => ['ab'],
            'diawali tanda hubung' => ['-contoh'],
            'diakhiri tanda hubung' => ['contoh-'],
            'tanda hubung ganda' => ['kelola--contoh'],
            'spasi' => ['kelola contoh'],
            'kata terlarang PHP' => ['list'],
            'penanda cetakan' => ['change-me'],
        ];
    }

    /**
     * Masukan yang tidak sah ditolak, dan penolakannya tidak meninggalkan apa pun.
     *
     * Yang diperiksa bukan hanya kode keluarnya. Sebuah perintah yang menolak **setelah**
     * menulis folder atau menyunting `composer.json` meninggalkan repo dalam keadaan yang
     * lebih buruk daripada sebelum ia dijalankan.
     */
    #[DataProvider('idYangTidakSah')]
    public function test_id_yang_tidak_sah_ditolak_tanpa_meninggalkan_jejak(string $id): void
    {
        $readmeSebelum = (string) file_get_contents($this->akarSementara.'/modules/README.md');
        $composerSebelum = (string) file_get_contents($this->akarSementara.'/apps/control-plane/composer.json');

        $keluaran = $this->jalankan(['module' => $id]);

        $this->assertSame(1, $keluaran['status'], 'Id "'.$id.'" seharusnya ditolak, bukan diterima.');
        $this->assertDirectoryDoesNotExist($this->akarSementara.'/modules/apperp/'.$id);
        $this->assertSame($readmeSebelum, (string) file_get_contents($this->akarSementara.'/modules/README.md'));
        $this->assertSame($composerSebelum, (string) file_get_contents($this->akarSementara.'/apps/control-plane/composer.json'));
    }

    public function test_awalan_tabel_yang_tidak_sah_ditolak(): void
    {
        $keluaran = $this->jalankan(['module' => 'kelola-contoh', '--awalan' => 'Contoh']);

        $this->assertSame(1, $keluaran['status']);
        $this->assertStringContainsString('Awalan tabel "Contoh" tidak sah', $keluaran['teks']);
    }

    /**
     * Awalan yang saling menelan ditolak, bukan hanya yang sama persis.
     *
     * Kepemilikan tabel diperiksa dengan awalan, jadi `contoh_a_` dan `contoh_` membuat tabel
     * satu module terbaca sebagai milik module lain — dan penjaga batas tabel akan menyalahkan
     * module yang keliru.
     */
    public function test_awalan_yang_bertabrakan_dengan_module_lain_ditolak(): void
    {
        $keluaran = $this->jalankan(['module' => 'kelola-contoh', '--awalan' => 'contoh_a_lagi_']);

        $this->assertSame(1, $keluaran['status']);
        $this->assertStringContainsString('bertabrakan dengan "contoh_a_"', $keluaran['teks']);
        $this->assertDirectoryDoesNotExist($this->akarSementara.'/modules/apperp/kelola-contoh');
    }

    public function test_id_module_yang_sudah_dipakai_ditolak(): void
    {
        $keluaran = $this->jalankan(['module' => 'contoh-a', '--awalan' => 'lain_']);

        $this->assertSame(1, $keluaran['status']);
        $this->assertStringContainsString('sudah dipakai modules/apperp/contoh-a', $keluaran['teks']);
    }

    /**
     * Module yang sudah ada ditolak, bukan ditimpa.
     *
     * Berkas penanda di bawah adalah pekerjaan orang lain. Ia harus utuh sesudahnya.
     */
    public function test_folder_module_yang_sudah_ada_tidak_pernah_ditimpa(): void
    {
        $folder = $this->akarSementara.'/modules/apperp/kelola-contoh';
        mkdir($folder, 0o755, true);
        file_put_contents($folder.'/app.yaml', "id: kelola-contoh\npekerjaan: milik orang lain\n");

        $keluaran = $this->jalankan(['module' => 'kelola-contoh', '--awalan' => 'kelola_']);

        $this->assertSame(1, $keluaran['status']);
        $this->assertStringContainsString('sudah ada', $keluaran['teks']);
        $this->assertSame(
            "id: kelola-contoh\npekerjaan: milik orang lain\n",
            (string) file_get_contents($folder.'/app.yaml'),
        );
    }

    /**
     * Seluruh keberatan dilaporkan sekaligus, bukan satu lalu berhenti.
     *
     * Orang yang salah menulis id **dan** awalan akan menjalankan perintah ini dua kali untuk
     * mengetahui keduanya, dan yang kedua kalinya ia sudah mengira masukannya benar.
     */
    public function test_keberatan_dilaporkan_sekaligus(): void
    {
        $keluaran = $this->jalankan(['module' => 'Kelola Contoh', '--awalan' => 'Salah', '--nama' => 'Nama: salah']);

        $this->assertSame(1, $keluaran['status']);
        $this->assertStringContainsString('Id module', $keluaran['teks']);
        $this->assertStringContainsString('Awalan tabel', $keluaran['teks']);
        $this->assertStringContainsString('Nama tampilan', $keluaran['teks']);
    }

    public function test_penerbit_lain_menentukan_folder_dan_namespace(): void
    {
        $keluaran = $this->jalankan(['module' => 'kelola-contoh', '--penerbit' => 'mitra-luar', '--awalan' => 'kelola_']);

        $this->assertSame(0, $keluaran['status'], $keluaran['teks']);

        $folder = $this->akarSementara.'/modules/mitra-luar/kelola-contoh';

        $this->assertStringContainsString(
            'namespace Modules\\MitraLuar\\KelolaContoh\\Models;',
            (string) file_get_contents($folder.'/src/Models/Contoh.php'),
        );
        $this->assertStringContainsString(
            '| `mitra-luar/kelola-contoh` | `Modules\MitraLuar\KelolaContoh\` | `kelola_` |',
            (string) file_get_contents($this->akarSementara.'/modules/README.md'),
        );
    }

    /**
     * Menjalankan perintahnya dan memulangkan kode keluar beserta keluarannya.
     *
     * @param  array<string, string>  $masukan
     * @return array{status: int, teks: string}
     */
    private function jalankan(array $masukan): array
    {
        $perintah = new ModuleMakeCommand;
        $perintah->setLaravel($this->app);

        $keluaran = new BufferedOutput;
        $status = $perintah->run(new ArrayInput($masukan, $perintah->getDefinition()), $keluaran);

        return ['status' => $status, 'teks' => $keluaran->fetch()];
    }

    /**
     * Repo tiruan: cetakan sungguhan, dua module yang sudah ada, README, dan composer.json.
     */
    private function siapkanRepoTiruan(): void
    {
        $cetakanAsli = dirname($this->basePathAsli, 2).'/modules/_template';
        $this->assertDirectoryExists($cetakanAsli, 'Cetakan module tidak ada; tidak ada yang bisa diuji.');
        $this->salinFolder($cetakanAsli, $this->akarSementara.'/modules/_template');

        $this->tulis($this->akarSementara.'/modules/apperp/contoh-a/app.yaml', implode("\n", [
            'id: contoh-a',
            'publisher: apperp',
            'table_prefix: contoh_a_',
            '',
        ]));

        $this->tulis($this->akarSementara.'/modules/apperp/zeta/app.yaml', implode("\n", [
            'id: zeta',
            'publisher: apperp',
            'table_prefix: zeta_',
            '',
        ]));

        $this->tulis($this->akarSementara.'/modules/README.md', implode("\n", [
            '# Modules',
            '',
            '## Bentuk folder',
            '',
            '| Yang dilarang | Sebab |',
            '| --- | --- |',
            '| `vendor/` | dependency diselesaikan sekali di akar repo |',
            '',
            '## Namespace dan awalan tabel',
            '',
            '| Folder | Namespace PHP | Awalan tabel |',
            '| --- | --- | --- |',
            '| `apperp/contoh-a` | `Modules\Apperp\ContohA\` | `contoh_a_` |',
            '| `apperp/zeta` | `Modules\Apperp\Zeta\` | `zeta_` |',
            '',
            '## Rujukan',
            '',
        ]));

        $this->tulis($this->akarSementara.'/apps/control-plane/composer.json', implode("\n", [
            '{',
            '    "name": "coreerp/control-plane",',
            '    "require": {',
            '        "php": "^8.4",',
            '        "apperp/contoh-a": "@dev",',
            '        "apperp/zeta": "@dev",',
            '        "laravel/framework": "^13.17"',
            '    },',
            '    "minimum-stability": "stable"',
            '}',
            '',
        ]));
    }

    /**
     * Jalur relatif tiap berkas di bawah sebuah folder, terurut.
     *
     * @return list<string>
     */
    private function berkasDi(string $folder): array
    {
        if (! is_dir($folder)) {
            return [];
        }

        $hasil = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $berkas) {
            if ($berkas->isFile()) {
                $hasil[] = str_replace('\\', '/', substr($berkas->getPathname(), strlen($folder) + 1));
            }
        }

        sort($hasil);

        return $hasil;
    }

    private function salinFolder(string $asal, string $tujuan): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($asal, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $berkas) {
            if (! $berkas->isFile()) {
                continue;
            }

            $relatif = substr($berkas->getPathname(), strlen($asal) + 1);
            $this->tulis($tujuan.'/'.str_replace('\\', '/', $relatif), (string) file_get_contents($berkas->getPathname()));
        }
    }

    private function tulis(string $jalur, string $isi): void
    {
        if (! is_dir(dirname($jalur))) {
            mkdir(dirname($jalur), 0o755, true);
        }

        file_put_contents($jalur, $isi);
    }

    private function hapusFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($folder);
    }
}
