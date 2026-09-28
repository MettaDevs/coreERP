<?php

declare(strict_types=1);

namespace Tests\Unit\Modules;

use App\Support\Modules\ModuleManifestFiles;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Penggabungan `app.yaml` dengan berkas fitur di `manifest/`, tanpa database dan tanpa Laravel.
 *
 * Bahwa module aset yang sungguhan sampai utuh ke katalog dibuktikan `RegisterAppManifestModuleTest`,
 * dan bahwa rantai izinnya tetap diperiksa dibuktikan `SusunanManifestModulTest`. Yang di sini
 * aturan penggabungannya sendiri: apa yang disambung, dalam urutan apa, dan apa yang ditolak.
 */
class ModuleManifestFilesTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = rtrim(sys_get_temp_dir(), '/\\').'/coreerp-manifest-files-'.bin2hex(random_bytes(6));
        mkdir($this->folder, 0o777, true);
        $this->write('app.yaml', [
            'id: modul-uji',
            'name: Modul Uji',
            'ui:',
            '  navigation:',
            '    rail:',
            '      - id: master',
            '        label: Master data',
            'security:',
            '  entry_points:',
            '    - code: modul-uji.barang.form',
            '      name: Layar barang',
            '      type: form',
        ]);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->folder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->folder);

        parent::tearDown();
    }

    public function test_module_without_manifest_folder_is_read_from_app_yaml_alone(): void
    {
        $this->assertSame(Yaml::parseFile($this->folder.'/app.yaml'), ModuleManifestFiles::read($this->folder));
    }

    public function test_lists_in_manifest_folder_follow_app_yaml_in_path_order(): void
    {
        $this->write('manifest/master/barang.yaml', [
            'security:',
            '  entry_points:',
            '    - code: modul-uji.barang.api',
            '      name: API barang',
            '      type: api',
            '  permissions:',
            '    - code: modul-uji.barang.read',
            '      name: Lihat barang',
            '      entry_point: modul-uji.barang.form',
            '      access: read',
            'number_sequences:',
            '  references:',
            '    - code: modul-uji.barang',
            '      name: Kode barang',
        ]);
        $this->write('manifest/gudang.yaml', [
            'security:',
            '  permissions:',
            '    - code: modul-uji.gudang.read',
            '      name: Lihat gudang',
            '      entry_point: modul-uji.barang.form',
            '      access: read',
        ]);
        $this->write('manifest/reports/daftar-barang.yaml', [
            'reports:',
            '  - code: modul-uji.daftar-barang',
            '    name: Daftar barang',
        ]);

        $manifest = ModuleManifestFiles::read($this->folder);

        // `manifest/gudang.yaml` lebih dulu dari `manifest/master/barang.yaml` karena jalurnya
        // diurutkan; `app.yaml` selalu paling depan.
        $this->assertSame(['modul-uji.barang.form', 'modul-uji.barang.api'], array_column($manifest['security']['entry_points'], 'code'));
        $this->assertSame(['modul-uji.gudang.read', 'modul-uji.barang.read'], array_column($manifest['security']['permissions'], 'code'));
        $this->assertSame(['modul-uji.barang'], array_column($manifest['number_sequences']['references'], 'code'));
        $this->assertSame(['modul-uji.daftar-barang'], array_column($manifest['reports'], 'code'));
        // Selain daftar berkode, isinya tetap milik `app.yaml`.
        $this->assertSame(['id', 'name', 'ui', 'security', 'number_sequences', 'reports'], array_keys($manifest));
        $this->assertSame('Master data', $manifest['ui']['navigation']['rail'][0]['label']);
    }

    public function test_code_declared_twice_is_rejected_naming_both_files(): void
    {
        $this->write('manifest/barang.yaml', [
            'security:',
            '  entry_points:',
            '    - code: modul-uji.barang.form',
            '      name: Layar barang lagi',
            '      type: form',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kode `modul-uji.barang.form` pada `security.entry_points` dinyatakan dua kali: di `app.yaml` dan `manifest/barang.yaml`.');

        ModuleManifestFiles::read($this->folder);
    }

    public function test_identity_or_menu_in_manifest_folder_is_rejected(): void
    {
        $this->write('manifest/barang.yaml', [
            'ui:',
            '  navigation:',
            '    rail: []',
            'security:',
            '  roles:',
            '    - code: modul-uji.gudang-admin',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`manifest/barang.yaml` memuat `ui`, `security.roles`, yang tidak dibaca dari berkas di `manifest/`.');

        ModuleManifestFiles::read($this->folder);
    }

    public function test_list_written_as_a_map_is_rejected(): void
    {
        $this->write('manifest/barang.yaml', [
            'reports:',
            '  code: modul-uji.daftar-barang',
            '  name: Daftar barang',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`manifest/barang.yaml`: `reports` harus berupa daftar.');

        ModuleManifestFiles::read($this->folder);
    }

    public function test_yml_file_is_rejected_instead_of_skipped(): void
    {
        $this->write('manifest/barang.yml', ['reports: []']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`manifest/barang.yml` tidak dibaca; berkas manifest memakai akhiran `.yaml`.');

        ModuleManifestFiles::read($this->folder);
    }

    /** @param list<string> $lines */
    private function write(string $name, array $lines): void
    {
        $path = $this->folder.'/'.$name;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, implode("\n", $lines)."\n");
    }
}
