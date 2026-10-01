<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Menemukan module dengan memindai folder, bukan membaca daftar yang ditulis tangan.
 *
 * Daftar yang ditulis tangan adalah berkas pusat yang diperebutkan semua orang: setiap
 * module baru menyentuhnya, setiap cabang membentrokkannya, dan sebuah module yang lupa
 * didaftarkan gagal dengan cara yang membingungkan. Itu salah satu penyakit sistem lama.
 *
 * ## Kenapa akarnya lebih dari satu
 *
 * `modules/` di akar repo hanya berisi module yang **dijual**. Bahan uji penjaga batas —
 * `contoh-a` dan `contoh-b` — dulu tinggal di sana juga, dan itu berarti satu-satunya hal yang
 * memisahkan "Contoh A" dari layar klien adalah sebuah pemangkasan saat membangun image. Sejak
 * 18 September 2026 keduanya pindah ke `apps/core/tests/Fixtures/modules`, tempat yang memang
 * tidak pernah ikut ke image: tahap akhir Dockerfile membuang seluruh `apps/core/tests`.
 *
 * Registry karena itu memindai **daftar** akar, bukan satu. Lingkungan test menambahkan akar
 * bahan uji lewat `config('modules.akar')`; produksi tidak pernah menyebutnya, jadi tidak ada
 * jalan bagi bahan uji untuk ikut walaupun berkasnya tersalin karena kekeliruan.
 *
 * Akar yang sama disebut dua kali tidak menghasilkan module ganda: yang dipindai berkasnya, dan
 * `glob` atas pola yang sama memulangkan berkas yang sama.
 */
final class ModuleRegistry
{
    /** @var list<ModuleManifest>|null */
    private ?array $module = null;

    /** @var list<ModuleManifest>|null */
    private ?array $modulesIncludingMoved = null;

    /** @var list<string> */
    private readonly array $root;

    /**
     * @param  string|list<string>  $root  Satu folder module, atau beberapa.
     */
    public function __construct(string|array $root)
    {
        $this->root = array_values(array_unique(is_string($root) ? [$root] : $root));
    }

    /**
     * Semua module yang ditemukan, diurutkan menurut id supaya keluarannya tetap sama
     * antar sistem berkas.
     *
     * @return list<ModuleManifest>
     */
    public function all(): array
    {
        if ($this->module !== null) {
            return $this->module;
        }

        $found = [];

        foreach ($this->manifestFiles() as $file) {
            $manifest = $this->read($file);

            if ($manifest !== null) {
                $found[] = $manifest;
            }
        }

        usort($found, static fn (ModuleManifest $a, ModuleManifest $b): int => strcmp($a->id, $b->id));

        return $this->module = $found;
    }

    /**
     * Semua module termasuk yang sedang dipindah masuk.
     *
     * Bedanya dengan `all()` penting dan bukan kenyamanan: **"belum boleh dipasang untuk
     * tenant" tidak sama dengan "kodenya tidak boleh dimuat".** Module yang sedang dipindah
     * belum boleh muncul di katalog, belum boleh dipasang, dan belum boleh menerima data
     * tenant — itu yang dijaga `all()`. Tetapi kodenya harus tetap bisa dimuat, karena
     * kalau tidak, tidak ada satu pun testnya yang bisa berjalan, dan pemindahannya
     * dikerjakan tanpa jaring pengaman sampai hari terakhir.
     *
     * Dipakai hanya untuk mendaftarkan penyedia layanan module. Jangan dipakai untuk
     * katalog, pemasangan, atau apa pun yang menyentuh data tenant.
     *
     * @return list<ModuleManifest>
     */
    public function allIncludingMoved(): array
    {
        if ($this->modulesIncludingMoved !== null) {
            return $this->modulesIncludingMoved;
        }

        $found = [];

        foreach ($this->manifestFiles() as $file) {
            $manifest = $this->read($file, ignoreMovedList: true);

            if ($manifest !== null) {
                $found[] = $manifest;
            }
        }

        usort($found, static fn (ModuleManifest $a, ModuleManifest $b): int => strcmp($a->id, $b->id));

        return $this->modulesIncludingMoved = $found;
    }

    public function cari(string $id): ?ModuleManifest
    {
        foreach ($this->all() as $module) {
            if ($module->id === $id) {
                return $module;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function manifestFiles(): array
    {
        $result = [];

        foreach ($this->root as $root) {
            $file = glob($root.'/*/*/app.yaml');

            if ($file === false) {
                continue;
            }

            foreach ($file as $item) {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Id module lain yang wajib terpasang lebih dulu.
     *
     * @param  array<mixed>  $content
     * @return list<string>
     */
    private function dependency(array $content): array
    {
        // Kuncinya `dependsOn`, bukan `depends_on`, dan itu koreksi terhadap keadaan sebelumnya.
        //
        // Manifest app yang belum dipindah — dan validasi payload provider di
        // `AppCatalogRequest` — memakai `dependsOn` berisi **peta** id ke rentang versi:
        //
        //     dependsOn:
        //       human-resources: ^0.1
        //
        // Registry ini dulu membaca `depends_on` dan mengambil **nilai**-nya. Ketiga module di
        // repo kebetulan menulis `depends_on: []`, dan daftar kosong tidak dapat dibedakan dari
        // peta kosong — jadi tidak ada yang gagal, dan tidak ada yang menyadarinya. Begitu
        // sebuah module benar-benar menyatakan dependency, katalog akan mencatatnya sementara
        // runtime membaca kosong: `InstallModule` berhenti menuntut prasyaratnya. Tenant memakai
        // module yang kekurangan module lain yang dibutuhkannya, tanpa satu pun kesalahan.
        $list = $content['dependsOn'] ?? [];

        // `??` di atas sudah menyingkirkan null, jadi yang tersisa diperiksa hanya kosongnya.
        if ($list === []) {
            return [];
        }

        // Bentuk yang salah dilempar, bukan dianggap kosong. Manifest yang dependency-nya tidak
        // terbaca adalah manifest yang prasyaratnya tidak dijaga siapa pun, dan itu lebih buruk
        // daripada module yang menolak dimuat.
        if (! is_array($list) || array_is_list($list)) {
            throw new \RuntimeException(sprintf(
                'Manifest module menulis `dependsOn` sebagai daftar; yang benar peta id module ke rentang versi, '.
                'misalnya `dependsOn:%s  human-resources: ^0.1`. Bentuk daftar terbaca kosong dan membuat '.
                'prasyaratnya tidak dijaga siapa pun.',
                PHP_EOL,
            ));
        }

        return array_values(array_filter(
            array_map(static fn ($key): string => is_string($key) ? $key : '', array_keys($list)),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Awalan tabel yang dinyatakan manifest, atau string kosong bila tidak ada.
     *
     * @param  array<mixed>  $content
     */
    private function tablePrefixes(array $content): string
    {
        return isset($content['table_prefix']) && is_string($content['table_prefix']) ? $content['table_prefix'] : '';
    }

    private function read(string $file, bool $ignoreMovedList = false): ?ModuleManifest
    {
        try {
            /** @var mixed $content */
            $content = Yaml::parseFile($file);
        } catch (ParseException) {
            return null;
        }

        if (! is_array($content)) {
            return null;
        }

        $id = isset($content['id']) && is_string($content['id']) ? $content['id'] : '';

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
        // belum siap. Alasan lengkapnya ada di `ModulesBeingMoved`.
        if (! $ignoreMovedList && ModulesBeingMoved::default()->marks(basename(dirname($file)))) {
            return null;
        }

        // Module yang **tidak** sedang dipindah wajib menyatakan awalan tabelnya. Tanpa awalan,
        // tabelnya memakai nama apa adanya dan bertabrakan dengan milik Core. Ini dijaga
        // `ModulesBeingMovedTest` supaya tidak ada module yang lenyap tanpa suara.
        if ($this->tablePrefixes($content) === '') {
            return null;
        }

        return new ModuleManifest(
            id: $id,
            nama: isset($content['name']) && is_string($content['name']) ? $content['name'] : $id,
            versi: isset($content['version']) && is_string($content['version']) ? $content['version'] : '0.0.0',
            penerbit: isset($content['publisher']) && is_string($content['publisher']) ? $content['publisher'] : '',
            jenis: isset($content['kind']) && is_string($content['kind']) ? $content['kind'] : 'business-app',
            awalanTabel: $this->tablePrefixes($content),
            folder: dirname($file),
            dependency: $this->dependency($content),
        );
    }
}
