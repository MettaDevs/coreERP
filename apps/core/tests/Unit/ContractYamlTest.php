<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Kontrak YAML harus terbaca oleh parser yang ketat.
 *
 * `check-contract-coverage.py` memakai PyYAML, yang membaca `{ description: NPWP, bila diisi. }`
 * tanpa keluhan — sebagai deskripsi "NPWP" ditambah kunci liar "bila diisi." bernilai kosong.
 * Pemeriksa cakupan tetap hijau, sementara pembaca kontrak yang memakai parser lain gagal memuatnya
 * atau membangun skema yang salah. Itu sudah sekali lolos ke `main`.
 */
class ContractYamlTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function kontrak(): array
    {
        return [
            'openapi-internal' => ['openapi-internal.yaml'],
            'asyncapi' => ['asyncapi.yaml'],
        ];
    }

    #[DataProvider('kontrak')]
    public function test_kontrak_terbaca_parser_yaml_yang_ketat(string $berkas): void
    {
        $isi = Yaml::parseFile(dirname(__DIR__, 2).'/contracts/'.$berkas);

        $this->assertIsArray($isi);
    }
}
