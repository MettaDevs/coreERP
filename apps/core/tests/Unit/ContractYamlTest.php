<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

/**
 * Kontrak YAML harus terbaca oleh parser yang ketat — sumber pecahannya maupun hasil rakitannya.
 *
 * `check-contract-coverage.py` dan `bundle.py` memakai PyYAML, yang membaca
 * `{ description: NPWP, bila diisi. }` tanpa keluhan — sebagai deskripsi "NPWP" ditambah kunci
 * liar "bila diisi." bernilai kosong. Pemeriksa tetap hijau, sementara pembaca kontrak yang memakai
 * parser lain gagal memuatnya atau membangun skema yang salah. Itu sudah sekali lolos ke `main`.
 */
class ContractYamlTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function kontrak(): array
    {
        $akar = dirname(__DIR__, 2).'/contracts';
        $berkas = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar, RecursiveDirectoryIterator::SKIP_DOTS)) as $item) {
            if ($item->isFile() && $item->getExtension() === 'yaml') {
                $relatif = str_replace('\\', '/', substr($item->getPathname(), strlen($akar) + 1));
                $berkas[$relatif] = [$item->getPathname()];
            }
        }
        ksort($berkas);

        return $berkas;
    }

    #[DataProvider('kontrak')]
    public function test_kontrak_terbaca_parser_yaml_yang_ketat(string $berkas): void
    {
        $this->assertIsArray(Yaml::parseFile($berkas));
    }

    public function test_daftar_berkas_kontrak_tidak_kosong(): void
    {
        // Pemindaian folder yang salah jalur akan menghasilkan nol kasus dan tetap hijau.
        $this->assertArrayHasKey('openapi-internal.yaml', self::kontrak());
        $this->assertArrayHasKey('terbit/integrasi-finance.yaml', self::kontrak());
        $this->assertArrayHasKey('internal/integrasi-finance.yaml', self::kontrak());
    }
}
