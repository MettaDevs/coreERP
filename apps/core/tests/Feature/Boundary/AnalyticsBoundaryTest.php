<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Penjaga engine analitik: Core boleh membaca tabel module, tetapi **hanya lewat dataset yang
 * didaftarkan module** (keputusan pemilik produk 3 Oktober 2026, `docs/dev/02-module-standard.md`
 * bagian *Ownership dan data*). Karena itu kode di `app/Platform/Analytics` tidak boleh menyebut:
 *
 * - namespace `Modules\` — kelas module tidak dikenal Core, dan module bisa tidak terpasang;
 * - nama tabel berawalan salah satu awalan module (`table_prefix` di `app.yaml` setiap module, termasuk
 *   module contoh bahan uji) — nama tabel dan kolom selalu datang dari definisi dataset;
 * - `DB::table(` dan `DB::select…(` di luar kelas yang memang menyusun SQL (`SQL_COMPOSERS`). Query
 *   lewat facade menerima nama tabel apa pun sebagai string, jadi ia jalan pintas paling mudah untuk
 *   membaca tabel module tanpa definisi dataset — dan tanpa scope tenant model.
 *
 * Seperti `LayerDirectionBoundaryTest`, yang dibaca teks berkasnya, termasuk string dan docblock: nama
 * yang ditulis mati di komentar adalah nama yang akan disalin ke kode berikutnya.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel, jadi ia memakai TestCase polos PHPUnit.
 */
class AnalyticsBoundaryTest extends TestCase
{
    /**
     * Kelas yang boleh menyusun SQL lewat facade `DB`, relatif terhadap `app/Platform/Analytics`: compiler
     * (area 3), cache hasil, dan log query (area 9) — yang terakhir menulis tabel `analytics_*` milik Core.
     * Selebihnya membaca lewat model dan definisi dataset. Menambah baris di sini adalah keputusan yang
     * ditinjau, bukan jalan keluar dari test yang merah.
     */
    private const SQL_COMPOSERS = [
        'Query/QueryCompiler.php',
        'Cache/QueryCache.php',
        'Support/QueryLog.php',
    ];

    public function test_analytics_engine_names_no_module_namespace_or_module_table(): void
    {
        $files = self::scan(self::corePath().'/app/Platform/Analytics');
        $prefixes = self::modulePrefixes();

        $this->assertNotSame([], $files, 'Tidak ada berkas engine analitik yang dibaca; penjaga ini akan lulus tanpa menguji apa pun.');
        $this->assertContains('aset_', $prefixes, 'Awalan tabel module tidak terbaca dari app.yaml; penjaga ini akan lulus tanpa menguji apa pun.');

        $this->assertSame([], self::violations($files, $prefixes), implode("\n", [
            'Engine analitik menyebut module secara langsung:',
            '- namespace Modules\\ tidak boleh disebut di App\\Platform\\Analytics;',
            '- nama tabel module tidak boleh ditulis di sana, termasuk di komentar.',
            'Nama tabel dan kolom datang dari dataset yang didaftarkan module (Contracts\\Analytics\\Dataset).',
        ]));
    }

    public function test_detector_catches_namespaces_and_table_names_but_not_lookalikes(): void
    {
        $files = [
            'Query/Pelanggar.php' => <<<'PHP'
            <?php
            namespace App\Platform\Analytics\Query;
            use Modules\Apperp\ManagementAset\Models\Aset;
            use App\Platform\Modules\Contracts\TableFields;
            final class Pelanggar {
                public function x(): void {
                    app('Modules\\Apperp\\HumanResources\\Kontrak');
                    DB::table('aset_tr_aset')->where('hr_pegawai.id', 1);
                    $dataset_version = 'dataset_unknown';
                }
            }
            PHP,
        ];

        $this->assertSame([
            'Query/Pelanggar.php: Modules\Apperp',
            'Query/Pelanggar.php: aset_tr_aset',
            'Query/Pelanggar.php: hr_pegawai',
        ], self::violations($files, ['aset_', 'hr_']), 'Pemeriksa harus menangkap impor, nama di string, dan nama tabel, tanpa salah tangkap pada `dataset_` atau `Modules\\Contracts` milik Core.');
    }

    public function test_only_sql_composing_classes_query_through_the_db_facade(): void
    {
        $files = self::scan(self::corePath().'/app/Platform/Analytics');

        $this->assertNotSame([], $files, 'Tidak ada berkas engine analitik yang dibaca; penjaga ini akan lulus tanpa menguji apa pun.');
        $this->assertSame([], self::facadeQueries($files), implode("\n", [
            'Engine analitik menyusun query lewat facade DB di luar kelas yang memang menyusun SQL.',
            'Baca tabel module lewat dataset (CompiledDataset::baseQuery()) dan tabel Core lewat modelnya.',
        ]));
    }

    public function test_facade_detector_catches_table_and_select_calls_outside_the_allowed_classes(): void
    {
        $source = <<<'PHP'
        <?php
        DB::table('analytics_widgets')->get();
        \Illuminate\Support\Facades\DB::select('select 1');
        DB::selectOne('select 1');
        DB::transaction(fn () => null);
        $builder->getQuery()->table;
        PHP;

        $this->assertSame([
            'Datasets/Pelanggar.php: DB::select(',
            'Datasets/Pelanggar.php: DB::selectOne(',
            'Datasets/Pelanggar.php: DB::table(',
        ], self::facadeQueries(['Datasets/Pelanggar.php' => $source, 'Query/QueryCompiler.php' => $source]), 'Pemeriksa harus menangkap DB::table dan DB::select*, termasuk lewat nama lengkap facade, dan melewati kelas penyusun SQL.');
    }

    /**
     * @param  array<string, string>  $files  jalur relatif => isi berkas
     * @return list<string>
     */
    private static function facadeQueries(array $files): array
    {
        $out = [];

        foreach ($files as $path => $source) {
            if (in_array($path, self::SQL_COMPOSERS, true)) {
                continue;
            }
            preg_match_all('/(?<![\w$>])DB::(table|select\w*)\s*\(/', $source, $matches);
            foreach ($matches[1] as $method) {
                $out[] = "{$path}: DB::{$method}(";
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param  array<string, string>  $files  jalur relatif => isi berkas
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    private static function violations(array $files, array $prefixes): array
    {
        $out = [];

        foreach ($files as $path => $source) {
            // `Modules\` yang tidak didahului huruf atau garis miring terbalik: `App\Platform\Modules\Contracts`
            // milik Core, bukan module.
            preg_match_all('/(?<![\w\\\\])Modules\\\\{1,2}(\w+)/', $source, $matches);
            foreach ($matches[1] as $publisher) {
                $out[] = "{$path}: Modules\\{$publisher}";
            }

            foreach ($prefixes as $prefix) {
                preg_match_all('/\b'.preg_quote($prefix, '/').'[a-z0-9_]*/', $source, $matches);
                foreach ($matches[0] as $table) {
                    $out[] = "{$path}: {$table}";
                }
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * Awalan tabel setiap module, dari `app.yaml` module sungguhan dan module contoh bahan uji.
     *
     * @return list<string>
     */
    private static function modulePrefixes(): array
    {
        $manifests = [
            ...(glob(dirname(self::corePath(), 2).'/modules/*/*/app.yaml') ?: []),
            ...(glob(self::corePath().'/tests/Fixtures/modules/*/*/app.yaml') ?: []),
        ];

        $prefixes = [];
        foreach ($manifests as $manifest) {
            $content = Yaml::parseFile($manifest);
            $prefix = is_array($content) ? ($content['table_prefix'] ?? null) : null;
            if (is_string($prefix) && $prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        $prefixes = array_values(array_unique($prefixes));
        sort($prefixes);

        return $prefixes;
    }

    /**
     * @return array<string, string> jalur relatif => isi berkas
     */
    private static function scan(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    private static function corePath(): string
    {
        // tests/Feature/Boundary -> tests -> core
        return dirname(__DIR__, 3);
    }
}
