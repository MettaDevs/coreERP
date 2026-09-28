<?php

namespace Tests\Concerns;

use Symfony\Component\Yaml\Yaml;

/**
 * Mencocokkan data dengan skema di kontrak OpenAPI yang ditulis tangan.
 *
 * Tanpa ini jawaban endpoint dan kontraknya dapat menyimpang tanpa satu test pun merah: test
 * memeriksa beberapa field yang ia kenal, sementara kontrak menjanjikan bentuk lengkap. Yang
 * diperiksa: kunci wajib, kunci di luar kontrak bila `additionalProperties: false`, tipe, enum,
 * pola, dan jumlah minimum isi larik. Itu bukan validator JSON Schema lengkap — cukup untuk
 * menangkap penyimpangan yang lazim terjadi.
 */
trait CocokDenganKontrak
{
    /** @var array<string, array<string, mixed>> */
    private static array $kontrakTermuat = [];

    protected function assertCocokSkema(mixed $data, string $skema, string $berkas = 'contracts/openapi-internal.yaml'): void
    {
        $spec = self::$kontrakTermuat[$berkas] ??= Yaml::parseFile(base_path($berkas));
        $this->periksaSkema($data, ['$ref' => '#/components/schemas/'.$skema], $spec, $skema);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $spec
     */
    private function periksaSkema(mixed $data, array $schema, array $spec, string $jalur): void
    {
        if (isset($schema['$ref'])) {
            $nama = substr((string) $schema['$ref'], strlen('#/components/schemas/'));
            $this->periksaSkema($data, $spec['components']['schemas'][$nama], $spec, $jalur);

            return;
        }

        $jenis = (array) ($schema['type'] ?? []);
        if ($data === null) {
            $this->assertContains('null', $jenis, $jalur.' tidak boleh null.');

            return;
        }
        if (isset($schema['enum'])) {
            $this->assertContains($data, $schema['enum'], $jalur.' di luar enum.');
        }
        $cocok = match (true) {
            is_string($data) => in_array('string', $jenis, true),
            is_int($data) => in_array('integer', $jenis, true),
            is_bool($data) => in_array('boolean', $jenis, true),
            is_array($data) && $data !== [] && array_is_list($data) => in_array('array', $jenis, true),
            is_array($data) => in_array('object', $jenis, true) || in_array('array', $jenis, true),
            default => false,
        };
        $this->assertTrue($jenis === [] || $cocok, $jalur.' bertipe salah.');

        if (is_string($data) && isset($schema['pattern'])) {
            $this->assertMatchesRegularExpression('/'.str_replace('/', '\/', (string) $schema['pattern']).'/', $data, $jalur.' tidak cocok pola.');
        }
        if (! is_array($data)) {
            return;
        }
        if (in_array('array', $jenis, true) && array_is_list($data)) {
            $this->assertGreaterThanOrEqual($schema['minItems'] ?? 0, count($data), $jalur.' terlalu sedikit.');
            foreach ($data as $i => $item) {
                $this->periksaSkema($item, $schema['items'] ?? [], $spec, $jalur.'['.$i.']');
            }

            return;
        }

        foreach ($schema['required'] ?? [] as $kunci) {
            $this->assertArrayHasKey($kunci, $data, $jalur.'.'.$kunci.' wajib ada.');
        }
        $properti = $schema['properties'] ?? [];
        if (($schema['additionalProperties'] ?? true) === false) {
            $this->assertSame([], array_values(array_diff(array_keys($data), array_keys($properti))), $jalur.' membawa kunci di luar kontrak.');
        }
        foreach ($properti as $kunci => $sub) {
            if (array_key_exists($kunci, $data)) {
                $this->periksaSkema($data[$kunci], $sub, $spec, $jalur.'.'.$kunci);
            }
        }
    }
}
