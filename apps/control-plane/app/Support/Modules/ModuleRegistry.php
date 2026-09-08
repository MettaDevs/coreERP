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

    /** @var list<ModuleManifest>|null */
    private ?array $moduleTermasukDipindah = null;

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

    /**
     * Semua module termasuk yang sedang dipindah masuk.
     *
     * Bedanya dengan `semua()` penting dan bukan kenyamanan: **"belum boleh dipasang untuk
     * tenant" tidak sama dengan "kodenya tidak boleh dimuat".** Module yang sedang dipindah
     * belum boleh muncul di katalog, belum boleh dipasang, dan belum boleh menerima data
     * tenant — itu yang dijaga `semua()`. Tetapi kodenya harus tetap bisa dimuat, karena
     * kalau tidak, tidak ada satu pun testnya yang bisa berjalan, dan pemindahannya
     * dikerjakan tanpa jaring pengaman sampai hari terakhir.
     *
     * Dipakai hanya untuk mendaftarkan penyedia layanan module. Jangan dipakai untuk
     * katalog, pemasangan, atau apa pun yang menyentuh data tenant.
     *
     * @return list<ModuleManifest>
     */
    public function semuaTermasukYangSedangDipindah(): array
    {
        if ($this->moduleTermasukDipindah !== null) {
            return $this->moduleTermasukDipindah;
        }

        $ditemukan = [];

        foreach ($this->berkasManifest() as $berkas) {
            $manifest = $this->baca($berkas, abaikanDaftarDipindah: true);

            if ($manifest !== null) {
                $ditemukan[] = $manifest;
            }
        }

        usort($ditemukan, static fn (ModuleManifest $a, ModuleManifest $b): int => strcmp($a->id, $b->id));

        return $this->moduleTermasukDipindah = $ditemukan;
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

    /**
     * Id module lain yang wajib terpasang lebih dulu.
     *
     * @param  array<mixed>  $isi
     * @return list<string>
     */
    private function dependency(array $isi): array
    {
        $daftar = $isi['depends_on'] ?? [];

        if (! is_array($daftar)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($nilai): string => is_string($nilai) ? $nilai : '', $daftar),
            static fn (string $nilai): bool => $nilai !== '',
        ));
    }

    /**
     * Awalan tabel yang dinyatakan manifest, atau string kosong bila tidak ada.
     *
     * @param  array<mixed>  $isi
     */
    private function awalanTabel(array $isi): string
    {
        return isset($isi['table_prefix']) && is_string($isi['table_prefix']) ? $isi['table_prefix'] : '';
    }

    private function baca(string $berkas, bool $abaikanDaftarDipindah = false): ?ModuleManifest
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

        // Module yang sedang dipindah masuk belum boleh dilayani. Ia masih memakai namespace
        // repo asalnya, query mentahnya belum diganti, dan tabelnya belum tentu membawa
        // `tenant_id` — memasangnya untuk tenant sungguhan berarti menaruh data yang tidak
        // tersaring siapa pun.
        //
        // Tandanya daftar yang ditulis sengaja, bukan sifat manifest yang kebetulan. F3-25
        // sempat memakai `table_prefix` yang belum ada sebagai tanda, dan tanda itu runtuh pada
        // F3-04 — task yang justru memberi awalan tabel, dan dengan itu menyalakan module yang
        // belum siap. Alasan lengkapnya ada di `ModulSedangDipindah`.
        if (! $abaikanDaftarDipindah && ModulSedangDipindah::bawaan()->menandai(basename(dirname($berkas)))) {
            return null;
        }

        // Module yang **tidak** sedang dipindah wajib menyatakan awalan tabelnya. Tanpa awalan,
        // tabelnya memakai nama apa adanya dan bertabrakan dengan milik Core. Ini dijaga
        // `ModulSedangDipindahTest` supaya tidak ada module yang lenyap tanpa suara.
        if ($this->awalanTabel($isi) === '') {
            return null;
        }

        return new ModuleManifest(
            id: $id,
            nama: isset($isi['name']) && is_string($isi['name']) ? $isi['name'] : $id,
            versi: isset($isi['version']) && is_string($isi['version']) ? $isi['version'] : '0.0.0',
            penerbit: isset($isi['publisher']) && is_string($isi['publisher']) ? $isi['publisher'] : '',
            jenis: isset($isi['kind']) && is_string($isi['kind']) ? $isi['kind'] : 'business-app',
            awalanTabel: $this->awalanTabel($isi),
            folder: dirname($berkas),
            dependency: $this->dependency($isi),
        );
    }
}
