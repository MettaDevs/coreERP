<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModulSedangDipindah;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Penjaga susunan manifest module: `app.yaml` dan tabel awalan pada `modules/README.md`.
 *
 * ## Kenapa penjaganya di sini, bukan di pemeriksa repo lama
 *
 * F6-04 langkah 2 menyebut `app-erp-ci-workflows/actions/validate-app-repository/` sebagai
 * tempat aturan ini dipasang. Berkas itu peninggalan masa setiap app punya repo sendiri, dan
 * hari ini **tidak satu pun alur di repo ini memanggilnya** — `.github/workflows/` tidak
 * pernah menyebut namanya. Menulis aturan di sana berarti menulis pemeriksa yang tidak
 * dijalankan siapa pun: hijau selamanya, karena tidak pernah berjalan.
 *
 * Aturannya karena itu dipasang di tempat ia bisa gagal, yaitu bersama penjaga susunan yang
 * lain di folder ini, yang memang dijalankan setiap kali suite berjalan.
 *
 * ## Kenapa ketiga aturan ini gagal dalam diam kalau tidak dijaga
 *
 * 1. **Manifest sah.** `ModuleRegistry::baca()` mengisi sendiri setiap kunci yang tidak ada:
 *    `name` jatuh ke `id`, `version` ke `0.0.0`, `publisher` ke string kosong, `kind` ke
 *    `business-app`. Tidak ada yang melempar. Dan karena registry mencari module lewat `id`
 *    sementara seluruh pemindaian folder memakai nama folder, `id` yang berbeda dari nama
 *    foldernya membuat module itu sekadar "tidak ditemukan" — bukan galat, hanya menu yang
 *    tidak muncul.
 * 2. **Rantai keamanan lengkap.** Permission yang tidak masuk satu pun privilege, atau
 *    privilege yang tidak masuk satu pun duty, adalah izin yang tidak dapat diberikan kepada
 *    siapa pun lewat peran. Yang terlihat bukan pesan galat, melainkan pengguna dengan layar
 *    kosong dan tidak ada yang bisa menjelaskan kenapa.
 * 3. **Awalan tabel terdaftar.** Tabel pemetaan di `modules/README.md` ada supaya tabrakan
 *    awalan ketahuan saat peninjauan, bukan saat migrasi jalan. Dokumen yang menyimpang dari
 *    manifest justru kebalikannya: ia meyakinkan orang berikutnya bahwa sebuah awalan masih
 *    bebas padahal sudah dipakai, dan tidak ada satu pun test yang gagal karenanya.
 *
 * Aturan keempat yang disebut F6-04 — "kontrak ada bila memang ada permukaan yang dipanggil
 * dari luar runtime" — sengaja **tidak** ada di sini. Lihat `modules/apperp/management-aset/
 * contracts/README.md`: sejak F3-23 berkas di folder itu berstatus dokumentasi, bukan janji
 * kepada pemanggil luar, dan hari ini tidak ada satu pun module yang punya permukaan semacam
 * itu. Penjaga atas aturan yang batasnya belum jelas lebih berbahaya daripada catatan.
 *
 * ## Bentuk test
 *
 * Setiap aturan ditulis sebagai fungsi yang mengembalikan daftar pelanggaran atas sebuah akar
 * `modules/`, lalu dipakai dua kali: sekali atas repo sungguhan, dan sekali atas manifest
 * palsu di folder sementara yang membuktikan aturannya benar-benar bisa merah. Manifest palsu
 * **tidak** ditulis ke `modules/` sungguhan; sisa dari run yang gagal di tengah akan terbaca
 * `module:list`, penjaga lain, Pint, dan PHPStan.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel, jadi ia memakai TestCase polos
 * PHPUnit, sama seperti tetangganya.
 */
class SusunanManifestModulTest extends TestCase
{
    /**
     * Kunci yang wajib ada pada setiap `app.yaml`, kecuali `table_prefix`.
     *
     * `table_prefix` diperiksa terpisah karena ia punya satu pengecualian yang sah: module
     * yang sedang dipindah masuk dan belum dibentuk ulang. Daftarnya dibaca dari
     * `ModulSedangDipindah`, satu tempat yang sama dengan penjaga batas lain — daftar batas
     * module yang hidup di dua tempat akan menyimpang.
     *
     * @var list<string>
     */
    private const KUNCI_WAJIB = ['id', 'name', 'version', 'publisher', 'kind'];

    /**
     * Judul bagian tempat tabel pemetaan awalan tabel hidup.
     *
     * Pemindaian dibatasi pada bagian ini, bukan seluruh berkas, karena README memuat tabel
     * lain — daftar folder yang dilarang, misalnya — dan membaca semuanya sebagai pemetaan
     * awalan akan membuat penjaga ini merah karena baris yang bukan urusannya.
     */
    private const JUDUL_TABEL = 'Namespace dan awalan tabel';

    /**
     * Akar folder sementara tempat manifest palsu dibuat, atau null bila belum ada.
     */
    private ?string $akarSementara = null;

    protected function tearDown(): void
    {
        // Dijalankan PHPUnit walau test-nya gagal atau melempar di tengah. Pembersihan yang
        // hanya ditulis di akhir badan test tidak berjalan justru pada saat ia paling
        // dibutuhkan.
        if ($this->akarSementara !== null) {
            $this->hapusFolder($this->akarSementara);
            $this->akarSementara = null;
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------------
    // Aturan 1: manifest sah
    // -----------------------------------------------------------------------------

    public function test_tiap_manifest_menyatakan_kunci_wajib_dan_cocok_dengan_foldernya(): void
    {
        $akar = self::akarRepo();
        $manifest = $this->manifest($akar);

        $this->assertNotSame([], $manifest, 'Tidak ada satu pun manifest module yang terbaca; pemindaiannya salah alamat.');

        $this->assertSame([], $this->pelanggaranKunci($akar, ModulSedangDipindah::bawaan()), implode("\n", [
            'Ada manifest module yang tidak sah.',
            'Ketidakcocokan di sini tidak berbunyi: ModuleRegistry mengisi sendiri kunci yang hilang,',
            'dan module yang id-nya berbeda dari nama foldernya hanya "tidak ditemukan" — tanpa galat,',
            'tanpa menu, dan tanpa ada yang gagal.',
        ]));
    }

    /**
     * Aturan di atas dibuktikan bisa merah, pada manifest palsu di folder sementara.
     *
     * Repo apa adanya hijau, jadi tanpa test ini tidak ada yang tahu apakah pemeriksanya
     * benar-benar melihat sesuatu atau hanya kebetulan tidak menemukan apa pun.
     */
    public function test_kunci_yang_hilang_dan_nama_yang_tidak_cocok_membuat_merah(): void
    {
        $akar = $this->akarSementaraBaru();

        $this->tulisManifest($akar, 'apperp', 'modul-cacat', [
            'id: modul-lain',
            'name: Modul Cacat',
            'publisher: penerbit-lain',
            'table_prefix: cacat_',
        ]);

        $this->assertSame(
            [
                'modules/apperp/modul-cacat/app.yaml tidak menyatakan "version".',
                'modules/apperp/modul-cacat/app.yaml tidak menyatakan "kind".',
                'modules/apperp/modul-cacat/app.yaml: id "modul-lain" tidak sama dengan nama foldernya "modul-cacat".',
                'modules/apperp/modul-cacat/app.yaml: publisher "penerbit-lain" tidak sama dengan nama folder induknya "apperp".',
            ],
            $this->pelanggaranKunci($akar, ModulSedangDipindah::bawaan()),
        );
    }

    /**
     * `table_prefix` wajib, dan pengecualiannya hanya module yang terdaftar sedang dipindah.
     *
     * Dua modul palsu yang isinya identik sampai ke barisnya, beda hanya pada nama folder dan
     * pada apakah namanya terdaftar. Kalau penandaannya bocor, keduanya akan lolos.
     */
    public function test_awalan_tabel_wajib_kecuali_untuk_modul_yang_sedang_dipindah(): void
    {
        $akar = $this->akarSementaraBaru();
        $acak = bin2hex(random_bytes(4));
        $ditandai = 'pindah-ditandai-'.$acak;
        $tanpaTanda = 'pindah-tanpa-tanda-'.$acak;

        foreach ([$ditandai, $tanpaTanda] as $nama) {
            $this->tulisManifest($akar, 'apperp', $nama, [
                'id: '.$nama,
                'name: Modul Palsu',
                'version: 0.1.0',
                'publisher: apperp',
                'kind: internal-fixture',
            ]);
        }

        $dipindah = ModulSedangDipindah::buatan([
            $ditandai => ['alasan' => 'Modul palsu milik test ini.', 'tenggat' => '2999-12-31'],
        ]);

        $this->assertSame(
            [sprintf('modules/apperp/%s/app.yaml tidak menyatakan "table_prefix" dan tidak terdaftar sedang dipindah.', $tanpaTanda)],
            $this->pelanggaranKunci($akar, $dipindah),
            'Penandaan harus melonggarkan module yang ditandai saja; kalau ia bocor, keduanya akan lolos.',
        );

        $this->assertCount(
            2,
            $this->pelanggaranKunci($akar, ModulSedangDipindah::buatan([])),
            'Tanpa penandaan, keduanya harus merah; jadi yang membedakan memang penandaannya.',
        );
    }

    // -----------------------------------------------------------------------------
    // Aturan 2: rantai keamanan lengkap
    // -----------------------------------------------------------------------------

    public function test_rantai_keamanan_tiap_manifest_utuh(): void
    {
        ['pelanggaran' => $pelanggaran, 'angka' => $angka] = $this->periksaRantaiKeamanan(self::akarRepo());

        $this->assertGreaterThan(0, $angka['modul'], 'Tidak ada manifest ber-blok security yang diperiksa; penjaga ini akan lulus tanpa menguji apa pun.');
        $this->assertGreaterThan(0, $angka['permission'], 'Tidak ada permission yang terbaca; rantai yang diperiksa kosong.');
        $this->assertGreaterThan(0, $angka['duty'], 'Tidak ada duty yang terbaca; ujung rantainya tidak ada.');

        $this->assertSame([], $pelanggaran, implode("\n", [
            'Rantai keamanan sebuah module putus.',
            'Empat lapisnya harus tersambung utuh: entry point dilindungi permission, permission masuk',
            'privilege, privilege masuk duty, dan duty itulah yang disusun tenant menjadi security role.',
            'Rantai yang putus berarti izin yang tidak pernah bisa diberikan kepada siapa pun — dan itu',
            'tidak terlihat sampai ada pengguna yang layarnya kosong tanpa satu pun pesan galat.',
        ]));
    }

    /**
     * Kelima bentuk rantai putus dibuktikan bisa merah sekaligus, pada satu manifest palsu.
     *
     * Ditulis dalam satu manifest, bukan lima, karena yang perlu dibuktikan bukan hanya bahwa
     * masing-masing terdeteksi melainkan bahwa keduanya tidak saling menutupi: pemeriksaan
     * keanggotaan tidak boleh diam hanya karena ada rujukan yang menggantung.
     */
    public function test_rantai_keamanan_yang_putus_membuat_merah(): void
    {
        $akar = $this->akarSementaraBaru();

        $this->tulisManifest($akar, 'apperp', 'modul-rantai', [
            'id: modul-rantai',
            'name: Modul Rantai',
            'version: 0.1.0',
            'publisher: apperp',
            'kind: internal-fixture',
            'table_prefix: rantai_',
            'security:',
            '  entry_points:',
            '    - code: modul-rantai.barang.form',
            '      name: Layar barang',
            '  permissions:',
            '    - code: modul-rantai.barang.read',
            '      entry_point: modul-rantai.barang.form',
            '    - code: modul-rantai.barang.create',
            '      entry_point: modul-rantai.barang.api',
            '    - code: modul-rantai.barang.update',
            '  privileges:',
            '    - code: modul-rantai.barang.maintain',
            '      permissions:',
            '        - modul-rantai.barang.read',
            '        - modul-rantai.barang.hapus',
            '    - code: modul-rantai.barang.retire',
            '      permissions:',
            '        - modul-rantai.barang.create',
            '  duties:',
            '    - code: modul-rantai.barang.manage',
            '      privileges:',
            '        - modul-rantai.barang.maintain',
            '        - modul-rantai.barang.arsipkan',
        ]);

        ['pelanggaran' => $pelanggaran, 'angka' => $angka] = $this->periksaRantaiKeamanan($akar);

        $this->assertSame([
            'modules/apperp/modul-rantai: permission "modul-rantai.barang.create" menunjuk entry_point "modul-rantai.barang.api" yang tidak ada.',
            'modules/apperp/modul-rantai: permission "modul-rantai.barang.update" tidak menyebut entry_point.',
            'modules/apperp/modul-rantai: privilege "modul-rantai.barang.maintain" menunjuk permission "modul-rantai.barang.hapus" yang tidak ada.',
            'modules/apperp/modul-rantai: duty "modul-rantai.barang.manage" menunjuk privilege "modul-rantai.barang.arsipkan" yang tidak ada.',
            'modules/apperp/modul-rantai: permission "modul-rantai.barang.update" tidak masuk satu pun privilege.',
            'modules/apperp/modul-rantai: privilege "modul-rantai.barang.retire" tidak masuk satu pun duty.',
        ], $pelanggaran);

        $this->assertSame(
            ['modul' => 1, 'entry_point' => 1, 'permission' => 3, 'privilege' => 2, 'duty' => 1],
            $angka,
        );
    }

    /**
     * Manifest tanpa blok `security` tidak diperiksa, dan itu keputusan yang perlu ditulis.
     *
     * `contoh-a` dan `contoh-b` memang tidak punya blok itu. Menuntut mereka memilikinya
     * berarti menuntut module contoh membawa empat lapis izin yang tidak dipakai satu pun
     * test — dan aturan yang dipenuhi asal ada isinya adalah aturan yang tidak menjaga apa
     * pun. Yang dijaga di sini adalah rantai yang **sudah dinyatakan**, harus utuh.
     */
    public function test_manifest_tanpa_blok_keamanan_dilewati_tanpa_membuat_merah(): void
    {
        $akar = $this->akarSementaraBaru();

        $this->tulisManifest($akar, 'apperp', 'modul-polos', [
            'id: modul-polos',
            'name: Modul Polos',
            'version: 0.1.0',
            'publisher: apperp',
            'kind: internal-fixture',
            'table_prefix: polos_',
        ]);

        ['pelanggaran' => $pelanggaran, 'angka' => $angka] = $this->periksaRantaiKeamanan($akar);

        $this->assertSame([], $pelanggaran);
        $this->assertSame(0, $angka['modul'], 'Manifest tanpa blok security tidak boleh dihitung sebagai module yang diperiksa.');
    }

    // -----------------------------------------------------------------------------
    // Aturan 3: awalan tabel terdaftar pada modules/README.md
    // -----------------------------------------------------------------------------

    public function test_tabel_awalan_pada_readme_sama_dengan_manifest(): void
    {
        $akar = self::akarRepo();
        $readme = $akar.'/README.md';

        $this->assertFileExists($readme);

        $baris = $this->barisTabelAwalan((string) file_get_contents($readme));
        $this->assertNotSame([], $baris, sprintf(
            'Tabel pemetaan di bawah "%s" tidak terbaca, jadi penjaga ini tidak mengukur apa pun.',
            self::JUDUL_TABEL,
        ));

        $this->assertSame([], $this->pelanggaranTabelAwalan($akar, (string) file_get_contents($readme)), implode("\n", [
            'Tabel awalan tabel di modules/README.md menyimpang dari manifest.',
            'Tabel itu ada supaya tabrakan awalan ketahuan saat peninjauan, bukan saat migrasi jalan.',
            'Baris yang kurang membuat awalan yang sudah dipakai tampak masih bebas; baris yang menyebut',
            'module yang tidak ada membuat awalan yang bebas tampak sudah terpakai. Keduanya menyesatkan',
            'orang berikutnya, dan tidak ada satu pun test lain yang gagal karenanya.',
        ]));
    }

    /**
     * Ketiga bentuk penyimpangan dibuktikan bisa merah: kurang, berbeda, dan berlebih.
     *
     * Manifestnya palsu dan teks README-nya pun ditulis di sini, supaya yang diuji adalah
     * aturannya dan bukan isi repo hari ini.
     */
    public function test_tabel_awalan_yang_menyimpang_membuat_merah(): void
    {
        $akar = $this->akarSementaraBaru();

        foreach ([['modul-kurang', 'kurang_'], ['modul-beda', 'beda_']] as [$nama, $awalan]) {
            $this->tulisManifest($akar, 'apperp', $nama, [
                'id: '.$nama,
                'name: Modul Palsu',
                'version: 0.1.0',
                'publisher: apperp',
                'kind: internal-fixture',
                'table_prefix: '.$awalan,
            ]);
        }

        $readme = implode("\n", [
            '## '.self::JUDUL_TABEL,
            '',
            '| Folder | Namespace PHP | Awalan tabel |',
            '| --- | --- | --- |',
            '| `apperp/modul-beda` | `Modules\Apperp\ModulBeda\` | `salah_` |',
            '| `apperp/modul-hantu` | `Modules\Apperp\ModulHantu\` | `hantu_` |',
            '',
            '## Bagian lain',
            '',
            '| `apperp/di-luar-tabel` | `Modules\Apperp\DiLuarTabel\` | `luar_` |',
        ]);

        $this->assertSame([
            'modules/apperp/modul-beda menyatakan table_prefix "beda_", tetapi modules/README.md menulis "salah_".',
            'modules/apperp/modul-kurang menyatakan table_prefix "kurang_", tetapi tidak ada di tabel modules/README.md.',
            'modules/README.md menyebut module "apperp/modul-hantu" yang tidak ada di modules/.',
        ], $this->pelanggaranTabelAwalan($akar, $readme));
    }

    /**
     * Kolom namespace ikut diperiksa, dan itu bukan tambahan yang tidak diminta.
     *
     * README menyatakan sendiri bahwa namespace diturunkan `StudlyCase` dari nama folder.
     * Sebuah baris yang menyimpang dari aturannya sendiri menyesatkan dengan cara yang persis
     * sama seperti awalan yang salah, dan sama-sama tidak membuat apa pun gagal.
     */
    public function test_kolom_namespace_yang_salah_membuat_merah(): void
    {
        $akar = $this->akarSementaraBaru();

        $this->tulisManifest($akar, 'apperp', 'modul-namespace', [
            'id: modul-namespace',
            'name: Modul Namespace',
            'version: 0.1.0',
            'publisher: apperp',
            'kind: internal-fixture',
            'table_prefix: ns_',
        ]);

        $readme = implode("\n", [
            '## '.self::JUDUL_TABEL,
            '',
            '| Folder | Namespace PHP | Awalan tabel |',
            '| --- | --- | --- |',
            '| `apperp/modul-namespace` | `Modules\Apperp\ModulNameSpace\` | `ns_` |',
        ]);

        $this->assertSame(
            ['modules/README.md menulis namespace "Modules\Apperp\ModulNameSpace\\" untuk module "apperp/modul-namespace"; yang benar "Modules\Apperp\ModulNamespace\\".'],
            $this->pelanggaranTabelAwalan($akar, $readme),
        );
    }

    // -----------------------------------------------------------------------------
    // Pemeriksa
    // -----------------------------------------------------------------------------

    /**
     * Manifest module di bawah sebuah akar, dipetakan `<penerbit>/<folder>` ke isinya.
     *
     * @return array<string, array<mixed>>
     */
    /**
     * Dependency ditulis dengan satu kunci dan satu bentuk: `dependsOn`, peta id ke rentang versi.
     *
     * Dua bentuk untuk satu jawaban pasti menyimpang, dan penyimpangan yang ini tidak berbunyi.
     * Sampai 10 September 2026 ketiga manifest di repo menulis `depends_on: []` sementara
     * `ModuleRegistry` mengambil **nilai**-nya dan katalog provider membaca `dependsOn` berisi
     * **peta**. Daftar kosong tidak dapat dibedakan dari peta kosong, jadi tidak ada yang gagal.
     * Begitu sebuah module benar-benar menyatakan dependency, katalog mencatatnya sementara
     * runtime membaca kosong: `InstallModule` berhenti menuntut prasyaratnya, dan
     * `EditionResolver` berhenti menariknya ke dalam image edisi. Pelanggan menerima image yang
     * kekurangan module yang dibutuhkan module lain, tanpa satu pun kesalahan.
     */
    public function test_dependency_memakai_satu_kunci_dan_satu_bentuk(): void
    {
        $this->assertSame([], $this->pelanggaranDependency(dirname(__DIR__, 5).'/modules'));
    }

    public function test_dependency_berbentuk_daftar_atau_berkunci_lama_membuat_merah(): void
    {
        $akar = $this->akarSementaraBaru();

        $this->tulisManifest($akar, 'apperp', 'modul-kunci-lama', [
            'id: modul-kunci-lama', 'name: Modul Kunci Lama', 'version: 0.1.0',
            'publisher: apperp', 'kind: business-app', 'table_prefix: lama_',
            'depends_on: []',
        ]);
        $this->tulisManifest($akar, 'apperp', 'modul-bentuk-daftar', [
            'id: modul-bentuk-daftar', 'name: Modul Bentuk Daftar', 'version: 0.1.0',
            'publisher: apperp', 'kind: business-app', 'table_prefix: daftar_',
            'dependsOn:', '  - modul-kunci-lama',
        ]);

        $pelanggaran = $this->pelanggaranDependency($akar);
        sort($pelanggaran);

        $this->assertSame([
            'modules/apperp/modul-bentuk-daftar/app.yaml menulis "dependsOn" sebagai daftar; yang benar peta id module ke rentang versi.',
            'modules/apperp/modul-kunci-lama/app.yaml memakai kunci "depends_on"; yang dibaca katalog dan runtime adalah "dependsOn".',
        ], $pelanggaran);
    }

    /**
     * Manifest yang dependency-nya salah bentuk ditolak runtime, bukan dianggap kosong.
     *
     * Penjaga di atas menahannya pada pull request. Ini menahannya pada saat pemuatan, untuk
     * manifest yang datang dari tempat lain — bundle edisi, atau module yang disalin tangan ke
     * server pelanggan.
     */
    public function test_registry_menolak_dependency_berbentuk_daftar(): void
    {
        $akar = $this->akarSementaraBaru();

        $this->tulisManifest($akar, 'apperp', 'modul-bentuk-daftar', [
            'id: modul-bentuk-daftar', 'name: Modul Bentuk Daftar', 'version: 0.1.0',
            'publisher: apperp', 'kind: business-app', 'table_prefix: daftar_',
            'dependsOn:', '  - modul-lain',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sebagai daftar');

        (new ModuleRegistry($akar))->semuaTermasukYangSedangDipindah();
    }

    /**
     * Kunci dan bentuk dependency tiap manifest.
     *
     * @return list<string>
     */
    private function pelanggaranDependency(string $akar): array
    {
        $pelanggaran = [];

        foreach ($this->manifest($akar) as $jalur => $isi) {
            $relatif = 'modules/'.$jalur.'/app.yaml';

            if (array_key_exists('depends_on', $isi)) {
                $pelanggaran[] = sprintf(
                    '%s memakai kunci "depends_on"; yang dibaca katalog dan runtime adalah "dependsOn".',
                    $relatif,
                );
            }

            $depends = $isi['dependsOn'] ?? null;

            if (is_array($depends) && $depends !== [] && array_is_list($depends)) {
                $pelanggaran[] = sprintf(
                    '%s menulis "dependsOn" sebagai daftar; yang benar peta id module ke rentang versi.',
                    $relatif,
                );
            }
        }

        return $pelanggaran;
    }

    /**
     * Module tidak membawa alur CI-nya sendiri.
     *
     * GitHub hanya menjalankan alur dari `.github/workflows/` **di akar repo**. Sebuah alur di
     * dalam `modules/<penerbit>/<module>/.github/workflows/` karena itu tidak pernah berjalan —
     * ia terlihat seperti pemeriksaan yang menjaga module itu, dan tidak menjaga apa pun.
     *
     * Ini peninggalan masa tiap app punya repo sendiri, dan ia ikut mendarat bersama subtree
     * setiap kali sebuah module dipindah masuk. Modul aset membawanya sampai 10 September 2026,
     * dan alur itu masih memanggil `app-erp-ci-workflows` — repo pemeriksa bersama yang sudah
     * digantikan satu alur di akar pada F6-04.
     *
     * Yang berbahaya bukan berkasnya, melainkan keyakinan yang ia tumbuhkan: orang yang
     * melihatnya menyimpulkan module ini punya pemeriksaannya sendiri, lalu berhenti mencari.
     */
    public function test_module_tidak_membawa_alur_ci_sendiri(): void
    {
        $this->assertSame([], $this->alurSendiri(dirname(__DIR__, 5).'/modules'));
    }

    public function test_alur_ci_di_dalam_module_membuat_merah(): void
    {
        $akar = $this->akarSementaraBaru();

        $this->tulisManifest($akar, 'apperp', 'modul-beralur', [
            'id: modul-beralur', 'name: Modul Beralur', 'version: 0.1.0',
            'publisher: apperp', 'kind: business-app', 'table_prefix: alur_',
            'dependsOn: {}',
        ]);

        $folder = $akar.'/apperp/modul-beralur/.github/workflows';
        mkdir($folder, 0o777, true);
        file_put_contents($folder.'/ci.yml', "name: CI\non: [push]\n");

        $this->assertSame(
            ['modules/apperp/modul-beralur/.github/workflows/ci.yml'],
            $this->alurSendiri($akar),
        );
    }

    /**
     * Berkas alur yang tinggal di dalam folder module.
     *
     * @return list<string>
     */
    private function alurSendiri(string $akar): array
    {
        $ditemukan = [];

        foreach (glob($akar.'/*/*/.github/workflows/*') ?: [] as $berkas) {
            if (! is_file($berkas)) {
                continue;
            }

            $jalur = str_replace(chr(92), '/', $berkas);
            $ditemukan[] = 'modules/'.substr($jalur, strpos($jalur, basename(dirname($berkas, 4)).'/'.basename(dirname($berkas, 3))));
        }

        sort($ditemukan);

        return $ditemukan;
    }

    private function manifest(string $akar): array
    {
        $hasil = [];

        foreach (glob($akar.'/*/*/app.yaml') ?: [] as $berkas) {
            /** @var mixed $isi */
            $isi = Yaml::parseFile($berkas);
            $folder = dirname($berkas);

            $hasil[basename(dirname($folder)).'/'.basename($folder)] = is_array($isi) ? $isi : [];
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * Aturan 1: kunci wajib ada, dan nama-nama yang harus cocok memang cocok.
     *
     * @return list<string>
     */
    private function pelanggaranKunci(string $akar, ModulSedangDipindah $dipindah): array
    {
        $pelanggaran = [];

        foreach ($this->manifest($akar) as $jalur => $isi) {
            [$penerbit, $folder] = explode('/', $jalur, 2);

            foreach (self::KUNCI_WAJIB as $kunci) {
                if (self::teks($isi[$kunci] ?? null) === '') {
                    $pelanggaran[] = sprintf('modules/%s/app.yaml tidak menyatakan "%s".', $jalur, $kunci);
                }
            }

            // Satu-satunya pengecualian yang sah. Daftarnya dibaca dari `ModulSedangDipindah`
            // supaya tidak ada dua daftar yang bisa menyimpang; `ModulSedangDipindahTest`
            // memeriksa hal yang sama dari sisi lain, yaitu bahwa entrinya tidak basi.
            if (self::teks($isi['table_prefix'] ?? null) === '' && ! $dipindah->menandai($folder)) {
                $pelanggaran[] = sprintf('modules/%s/app.yaml tidak menyatakan "table_prefix" dan tidak terdaftar sedang dipindah.', $jalur);
            }

            $id = self::teks($isi['id'] ?? null);

            if ($id !== '' && $id !== $folder) {
                $pelanggaran[] = sprintf('modules/%s/app.yaml: id "%s" tidak sama dengan nama foldernya "%s".', $jalur, $id, $folder);
            }

            $penerbitManifest = self::teks($isi['publisher'] ?? null);

            if ($penerbitManifest !== '' && $penerbitManifest !== $penerbit) {
                $pelanggaran[] = sprintf('modules/%s/app.yaml: publisher "%s" tidak sama dengan nama folder induknya "%s".', $jalur, $penerbitManifest, $penerbit);
            }
        }

        return $pelanggaran;
    }

    /**
     * Aturan 2: keempat lapis pada blok `security` tersambung utuh.
     *
     * Angka ikut dikembalikan supaya penjaga atas repo sungguhan bisa membuktikan ia memang
     * membaca sesuatu. Penjaga yang hijau karena tidak menemukan apa pun untuk diperiksa
     * tidak dapat dibedakan dari penjaga yang hijau karena semuanya benar.
     *
     * @return array{pelanggaran: list<string>, angka: array{modul: int, entry_point: int, permission: int, privilege: int, duty: int}}
     */
    private function periksaRantaiKeamanan(string $akar): array
    {
        $pelanggaran = [];
        $angka = ['modul' => 0, 'entry_point' => 0, 'permission' => 0, 'privilege' => 0, 'duty' => 0];

        foreach ($this->manifest($akar) as $jalur => $isi) {
            $security = $isi['security'] ?? null;

            if (! is_array($security)) {
                continue;
            }

            $entryPoint = self::daftarEntri($security['entry_points'] ?? null);
            $permission = self::daftarEntri($security['permissions'] ?? null);
            $privilege = self::daftarEntri($security['privileges'] ?? null);
            $duty = self::daftarEntri($security['duties'] ?? null);

            $angka['modul']++;
            $angka['entry_point'] += count($entryPoint);
            $angka['permission'] += count($permission);
            $angka['privilege'] += count($privilege);
            $angka['duty'] += count($duty);

            $kodeEntryPoint = self::kode($entryPoint);
            $kodePermission = self::kode($permission);
            $kodePrivilege = self::kode($privilege);

            // Lapis pertama: permission menunjuk entry point yang ada.
            foreach ($permission as $entri) {
                $kode = self::teks($entri['code'] ?? null);
                $tujuan = self::teks($entri['entry_point'] ?? null);

                if ($tujuan === '') {
                    $pelanggaran[] = sprintf('modules/%s: permission "%s" tidak menyebut entry_point.', $jalur, $kode);

                    continue;
                }

                if (! in_array($tujuan, $kodeEntryPoint, true)) {
                    $pelanggaran[] = sprintf('modules/%s: permission "%s" menunjuk entry_point "%s" yang tidak ada.', $jalur, $kode, $tujuan);
                }
            }

            // Rujukan yang menggantung diperiksa lebih dulu daripada keanggotaan. Sebuah
            // privilege yang menyebut permission karangan akan **menghitung** permission itu
            // sebagai anggota kalau hanya keanggotaan yang diperiksa, jadi tanpa pemeriksaan
            // ini rantai bisa tampak utuh sambil menunjuk sesuatu yang tidak ada.
            $anggotaPermission = self::anggota($privilege, 'permissions');
            $anggotaPrivilege = self::anggota($duty, 'privileges');

            foreach ($anggotaPermission as $kode => $pemilik) {
                if (! in_array($kode, $kodePermission, true)) {
                    $pelanggaran[] = sprintf('modules/%s: privilege "%s" menunjuk permission "%s" yang tidak ada.', $jalur, $pemilik, $kode);
                }
            }

            foreach ($anggotaPrivilege as $kode => $pemilik) {
                if (! in_array($kode, $kodePrivilege, true)) {
                    $pelanggaran[] = sprintf('modules/%s: duty "%s" menunjuk privilege "%s" yang tidak ada.', $jalur, $pemilik, $kode);
                }
            }

            // Lapis kedua dan ketiga: tidak ada yang berhenti di tengah jalan.
            foreach ($kodePermission as $kode) {
                if (! isset($anggotaPermission[$kode])) {
                    $pelanggaran[] = sprintf('modules/%s: permission "%s" tidak masuk satu pun privilege.', $jalur, $kode);
                }
            }

            foreach ($kodePrivilege as $kode) {
                if (! isset($anggotaPrivilege[$kode])) {
                    $pelanggaran[] = sprintf('modules/%s: privilege "%s" tidak masuk satu pun duty.', $jalur, $kode);
                }
            }
        }

        return ['pelanggaran' => $pelanggaran, 'angka' => $angka];
    }

    /**
     * Aturan 3: tabel pemetaan pada `modules/README.md` sama dengan manifest, dua arah.
     *
     * @return list<string>
     */
    private function pelanggaranTabelAwalan(string $akar, string $readme): array
    {
        $tabel = $this->barisTabelAwalan($readme);
        $pelanggaran = [];
        $dinyatakan = [];

        foreach ($this->manifest($akar) as $jalur => $isi) {
            $awalan = self::teks($isi['table_prefix'] ?? null);

            if ($awalan === '') {
                // Module yang sedang dipindah belum punya awalan untuk didaftarkan. Aturan 1
                // yang menuntut awalannya; aturan ini hanya mencocokkan yang sudah dinyatakan.
                continue;
            }

            $dinyatakan[$jalur] = $awalan;

            if (! isset($tabel[$jalur])) {
                $pelanggaran[] = sprintf('modules/%s menyatakan table_prefix "%s", tetapi tidak ada di tabel modules/README.md.', $jalur, $awalan);

                continue;
            }

            if ($tabel[$jalur]['awalan'] !== $awalan) {
                $pelanggaran[] = sprintf('modules/%s menyatakan table_prefix "%s", tetapi modules/README.md menulis "%s".', $jalur, $awalan, $tabel[$jalur]['awalan']);
            }

            $namespace = 'Modules\\'.PemindaiModul::namespaceModul($akar.'/'.$jalur).'\\';

            if ($tabel[$jalur]['namespace'] !== $namespace) {
                $pelanggaran[] = sprintf('modules/README.md menulis namespace "%s" untuk module "%s"; yang benar "%s".', $tabel[$jalur]['namespace'], $jalur, $namespace);
            }
        }

        foreach (array_keys($tabel) as $jalur) {
            if (! isset($dinyatakan[$jalur])) {
                $pelanggaran[] = sprintf('modules/README.md menyebut module "%s" yang tidak ada di modules/.', $jalur);
            }
        }

        return $pelanggaran;
    }

    /**
     * Baris tabel pemetaan, dipetakan `<penerbit>/<folder>` ke namespace dan awalannya.
     *
     * @return array<string, array{namespace: string, awalan: string}>
     */
    private function barisTabelAwalan(string $readme): array
    {
        $mulai = strpos($readme, '## '.self::JUDUL_TABEL);

        if ($mulai === false) {
            return [];
        }

        $bagian = substr($readme, $mulai + 3);
        $akhir = strpos($bagian, "\n## ");
        $bagian = $akhir === false ? $bagian : substr($bagian, 0, $akhir);

        preg_match_all('/^\|\s*`([^`]+)`\s*\|\s*`([^`]+)`\s*\|\s*`([^`]+)`\s*\|/m', $bagian, $cocok, PREG_SET_ORDER);

        $hasil = [];

        foreach ($cocok as $baris) {
            $hasil[$baris[1]] = ['namespace' => $baris[2], 'awalan' => $baris[3]];
        }

        return $hasil;
    }

    // -----------------------------------------------------------------------------
    // Alat
    // -----------------------------------------------------------------------------

    private static function akarRepo(): string
    {
        // tests/Feature/Boundary -> tests -> core -> apps -> akar repo
        return dirname(__DIR__, 5).'/modules';
    }

    /**
     * Nilai skalar sebuah kunci manifest sebagai teks, atau string kosong bila tidak berguna.
     *
     * Angka ikut diterima, dan itu perlu: `version: 1.0` dibaca YAML sebagai float, dan
     * menolaknya sebagai "tidak dinyatakan" akan memberi pesan gagal yang menyesatkan
     * penulisnya ke arah yang salah.
     */
    private static function teks(mixed $nilai): string
    {
        return is_scalar($nilai) ? trim((string) $nilai) : '';
    }

    /**
     * Entri sebuah daftar pada blok `security`, hanya yang berbentuk peta.
     *
     * @return list<array<mixed>>
     */
    private static function daftarEntri(mixed $nilai): array
    {
        if (! is_array($nilai)) {
            return [];
        }

        return array_values(array_filter($nilai, 'is_array'));
    }

    /**
     * Kode setiap entri sebuah daftar.
     *
     * @param  list<array<mixed>>  $daftar
     * @return list<string>
     */
    private static function kode(array $daftar): array
    {
        $kode = [];

        foreach ($daftar as $entri) {
            $nilai = self::teks($entri['code'] ?? null);

            if ($nilai !== '') {
                $kode[] = $nilai;
            }
        }

        return $kode;
    }

    /**
     * Kode yang disebut sebuah lapis, dipetakan ke kode lapis di atasnya yang menyebutnya.
     *
     * Pemilik yang disimpan adalah yang pertama menyebut. Itu cukup: pesan gagal perlu
     * menunjuk satu tempat yang bisa dibuka orang, bukan seluruh tempat.
     *
     * @param  list<array<mixed>>  $daftar
     * @return array<string, string>
     */
    private static function anggota(array $daftar, string $kunci): array
    {
        $anggota = [];

        foreach ($daftar as $entri) {
            $pemilik = self::teks($entri['code'] ?? null);
            $isi = $entri[$kunci] ?? null;

            if (! is_array($isi)) {
                continue;
            }

            foreach ($isi as $nilai) {
                $kode = self::teks($nilai);

                if ($kode !== '' && ! isset($anggota[$kode])) {
                    $anggota[$kode] = $pemilik;
                }
            }
        }

        return $anggota;
    }

    private function akarSementaraBaru(): string
    {
        $akar = rtrim(sys_get_temp_dir(), '/\\').'/coreerp-manifest-'.bin2hex(random_bytes(6));
        mkdir($akar, 0o777, true);
        $this->akarSementara = $akar;

        return $akar;
    }

    /**
     * Manifest palsu di folder sementara, bukan di `modules/` sungguhan.
     *
     * @param  list<string>  $baris
     */
    private function tulisManifest(string $akar, string $penerbit, string $folder, array $baris): void
    {
        $tujuan = $akar.'/'.$penerbit.'/'.$folder;
        mkdir($tujuan, 0o777, true);
        file_put_contents($tujuan.'/app.yaml', implode("\n", $baris)."\n");
    }

    private function hapusFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($folder);
    }
}
