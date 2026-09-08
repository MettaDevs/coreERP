<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Menemukan module dengan memindai folder, bukan membaca daftar yang ditulis tangan.
 *
 * Daftar yang ditulis tangan adalah berkas pusat yang diperebutkan semua orang: setiap
 * module baru menyentuhnya, setiap cabang membentrokkannya, dan sebuah module yang lupa
 * didaftarkan gagal dengan cara yang membingungkan. Itu salah satu penyakit sistem lama.
 */
final class ModuleRegistry
{
    /** @var list<ModuleManifest>|null */
    private ?array $module = null;

    public function __construct(private readonly string $akar) {}

    /**
     * Semua module yang ditemukan, diurutkan menurut id supaya keluarannya tetap sama
     * antar sistem berkas.
     *
     * @return list<ModuleManifest>
     */
    public function semua(): array
    {
        if ($this->module !== null) {
            return $this->module;
        }

        $ditemukan = [];

        foreach ($this->berkasManifest() as $berkas) {
            $manifest = $this->baca($berkas);

            if ($manifest !== null) {
                $ditemukan[] = $manifest;
            }
        }

        usort($ditemukan, static fn (ModuleManifest $a, ModuleManifest $b): int => strcmp($a->id, $b->id));

        return $this->module = $ditemukan;
    }

    public function cari(string $id): ?ModuleManifest
    {
        foreach ($this->semua() as $module) {
            if ($module->id === $id) {
                return $module;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function berkasManifest(): array
    {
        $pola = $this->akar.'/*/*/app.yaml';
        $berkas = glob($pola);

        return $berkas === false ? [] : $berkas;
    }

    private function baca(string $berkas): ?ModuleManifest
    {
        try {
            /** @var mixed $isi */
            $isi = Yaml::parseFile($berkas);
        } catch (ParseException) {
            return null;
        }

        if (! is_array($isi)) {
            return null;
        }

        $id = isset($isi['id']) && is_string($isi['id']) ? $isi['id'] : '';

        // Cetakan module baru memakai `change-me` sebagai id. Sebuah cetakan yang belum
        // diisi bukan module, dan memuatnya berarti menyalakan folder contoh yang belum
        // dikerjakan siapa pun. Skrip pengembangan di repo erp-dev sudah melakukan hal
        // yang sama untuk app lama.
        if ($id === '' || $id === 'change-me') {
            return null;
        }

        return new ModuleManifest(
            id: $id,
            nama: isset($isi['name']) && is_string($isi['name']) ? $isi['name'] : $id,
            versi: isset($isi['version']) && is_string($isi['version']) ? $isi['version'] : '0.0.0',
            penerbit: isset($isi['publisher']) && is_string($isi['publisher']) ? $isi['publisher'] : '',
            jenis: isset($isi['kind']) && is_string($isi['kind']) ? $isi['kind'] : 'business-app',
            awalanTabel: isset($isi['table_prefix']) && is_string($isi['table_prefix']) ? $isi['table_prefix'] : '',
            folder: dirname($berkas),
        );
    }
}
