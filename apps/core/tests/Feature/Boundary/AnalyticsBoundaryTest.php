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
 *   module contoh bahan uji) — nama tabel dan kolom selalu datang dari definisi dataset.
 *
 * Seperti `LayerDirectionBoundaryTest`, yang dibaca teks berkasnya, termasuk string dan docblock: nama
 * yang ditulis mati di komentar adalah nama yang akan disalin ke kode berikutnya. Area 1 menambah
 * larangan `DB::table(`/`DB::select(` di luar kelas yang memang menyusun SQL.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel, jadi ia memakai TestCase polos PHPUnit.
 */
class AnalyticsBoundaryTest extends TestCase
{
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
