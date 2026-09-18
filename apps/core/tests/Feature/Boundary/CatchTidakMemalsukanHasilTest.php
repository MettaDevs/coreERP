<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Sebuah `catch` tidak boleh memulangkan nilai yang juga sah.
 *
 * ## Yang dijaga, dan kenapa bukan "jangan menelan kesalahan"
 *
 * Aturan yang lebih tua berbunyi: setiap `catch` harus menangani, melempar ulang, atau mencatat.
 * Aturan itu meloloskan bentuk yang paling sering menipu — `catch (Throwable) { Log::warning(…);
 * return []; }` — karena ia sudah mencatat. Yang rusak bukan kesunyiannya; yang rusak adalah
 * **nilai kembalinya**. Larik kosong juga berarti "memang tidak ada satu pun", `null` juga berarti
 * "memang tidak ada", dan sejak titik itu pemanggil kehilangan kemampuan membedakan keduanya. Ia
 * lalu menyatakan sesuatu yang tidak pernah ia baca dari mana pun.
 *
 * Log tidak menutup jarak itu. Log dibaca orang yang sudah curiga; nilai kembali dibaca kode, saat
 * itu juga, dan kode tidak pernah curiga.
 *
 * ## Akibatnya bukan teori
 *
 * Tiga contoh dari repo ini, ketiganya diperbaiki bersamaan dengan lahirnya penjaga ini:
 *
 * - Layar rincian lingkungan berbunyi "sudah punya database sendiri, tetapi belum satu pun module
 *   dipasang di dalamnya" tepat ketika `core_module_installations` tidak dapat dibaca.
 * - `internal/v1` menjawab `modules: []` pada keadaan yang sama, dan kontraknya sendiri terpaksa
 *   menuliskan bahwa dua keadaan itu tidak dapat dibedakan.
 * - Layout Word/Excel yang sah tetapi isinya tidak terbaca lolos sebagai layout tanpa placeholder
 *   asing, lalu tercetak kosong tepat di bagian yang seharusnya terisi.
 *
 * ## Yang tidak dilarang
 *
 * `return false` sengaja tidak ikut. Pada fungsi yang memang menjawab ya/tidak, `false` adalah
 * jawaban, bukan ketidaktahuan yang menyamar: salinan yang gagal memang tidak jadi tersalin.
 * Larangan yang merah pada kasus benar adalah larangan yang akan dimatikan orang.
 *
 * Sisi TypeScript dijaga terpisah oleh `no-restricted-syntax` di `eslint.config.js`, dengan daftar
 * nilai yang sama dan alasan yang sama.
 */
class CatchTidakMemalsukanHasilTest extends TestCase
{
    /**
     * Folder kode yang dipindai, relatif terhadap akar repo.
     *
     * Berkas test tidak ikut. Sebuah `catch` di dalam test hampir selalu bagian dari skenario yang
     * sedang dibuktikan, dan memindainya hanya menghasilkan kebisingan yang menutupi kode nyata.
     */
    private const AKAR = [
        'apps/core/app',
        'apps/control-plane/app',
        'modules',
    ];

    /**
     * Berkas yang nilai sahnya memang tidak menyesatkan pemanggilnya.
     *
     * Satu baris per berkas beserta alasannya, dan alasannya harus menjelaskan kenapa nilai itu
     * **tidak** menyesatkan — bukan kenapa memperbaikinya merepotkan. Yang merepotkan tempatnya
     * di GAP_DIKETAHUI, dengan akibatnya ditulis apa adanya.
     *
     * Entri yang berkasnya sudah tidak melanggar lagi membuat test ini merah. Itu disengaja:
     * pengecualian basi adalah izin yang menganggur, dan izin yang menganggur akan dipakai kode
     * berikutnya yang kebetulan mendarat di berkas yang sama.
     *
     * @var array<string, string>
     */
    private const DIKECUALIKAN = [
        'apps/core/app/Support/Observabilitas/JejakAktif.php' => 'Pelapor kesalahan tidak boleh melempar dari dalam penanganan kesalahan; jejak yang tidak terbaca membuat laporannya kehilangan tautan, bukan membuat laporannya berbohong.',
        'apps/core/app/Support/Observabilitas/LaporanKesalahan.php' => 'Sama: satu bagian laporan yang gagal disusun tidak boleh menghapus kesalahan asli yang sedang dilaporkan.',
        'apps/core/app/Support/Observabilitas/PelaporKesalahan.php' => 'Sama, dan paling keras: kelas ini dipanggil dari penangan kesalahan Laravel itu sendiri.',
        'apps/core/app/Support/Observabilitas/SqlTerbaca.php' => 'Query yang gagal dirapikan dipulangkan apa adanya oleh pemanggilnya; tidak ada fakta yang dinyatakan.',
        'apps/core/app/Support/Observabilitas/TersangkaPemotongan.php' => 'Tebakan penyebab pemotongan yang gagal disusun hanya menghilangkan petunjuk tambahan pada laporan.',
        'apps/control-plane/app/Environments/InstalledModules.php' => 'Hanya pada pembacaan nama katalog: namanya jatuh ke id module, jadi katalog yang gagal dibaca menghasilkan tabel berisi id — terlihat, dan tidak mengaku apa pun. Pembacaan daftar pemasangannya sendiri memulangkan null.',
        'apps/core/app/Console/Commands/Concerns/HoldsEnvironmentOperation.php' => '`null` di sini berarti operasinya tidak jadi dibuka, dan jalur suksesnya tidak pernah memulangkan null. Sebabnya juga sudah dicetak ke operator sebelum baris itu.',
        'apps/core/app/Http/Controllers/Internal/EnvironmentProvisioningController.php' => '`null` justru dipakai sebagai "tidak tahu": jalur suksesnya selalu memulangkan larik, dan pemanggilnya menerjemahkan null menjadi `modules_unreadable` pada jawabannya.',
    ];

    /**
     * Gap yang diketahui, dimiliki, dan belum ditutup — bukan pengecualian.
     *
     * Bedanya dengan DIKECUALIKAN bukan administratif. Di sini nilai sah itu **memang**
     * menyesatkan pemanggilnya; yang menahan perbaikannya adalah keputusan lain yang harus
     * diambil lebih dulu, dan keputusan itu disebut namanya. Daftar ini dimaksudkan menyusut.
     *
     * Aturan basinya sama: entri yang berkasnya sudah bersih membuat test ini merah.
     *
     * @var array<string, string>
     */
    private const GAP_DIKETAHUI = [
        'modules/apperp/management-aset/src/Services/KalenderFiskalAset.php' => 'Kalender fiskal yang gagal dibaca jatuh ke tahun kalender tanpa satu pun kesalahan yang terlihat, dan seluruh jadwal penyusutan aset bergeser lima bulan. Akibatnya sudah dipaku angkanya di NoInternalHttpTest. Menutupnya berarti memutuskan apa yang harus terjadi pada dokumen yang terlanjur memakai tanggal yang salah — keputusan pemilik module, bukan keputusan penjaga ini.',
        'modules/apperp/management-aset/src/Services/PembuatAset.php' => 'Lapis kedua dari gap yang sama, pada jalur yang memanggil kalender itu. Ditutup bersama yang di atas, bukan sendiri. Sampai 18 September 2026 ia berada di AssetController; register aset dipecah pada hari itu dan pembentukan asetnya pindah ke service ini.',
        'apps/core/app/Support/Modules/ModuleRegistry.php' => 'Manifest yang tidak terurai membuat modulenya lenyap dari registry, jadi rutenya 404 tanpa ada yang menyebut sebabnya. Melewatinya adalah keputusan yang sudah dipaku ModuleRegistryTest::test_manifest_rusak_dilewati_tanpa_menjatuhkan_runtime; membalikkannya berarti memutuskan apakah satu manifest rusak boleh menahan seluruh runtime menyala.',
        'apps/control-plane/app/Dns/CloudflareSettings.php' => 'Bentuk yang sama dengan InvitationCode di bawah: APP_KEY yang berganti sesudah token disimpan membuat token yang ada berbunyi persis sama dengan token yang memang belum disetel, dan `configured()` menjawab false untuk keduanya. Memisahkannya berarti memutuskan apa yang harus dilihat operator ketika kredensial yang tersimpan tidak lagi dapat dibuka.',
        'apps/control-plane/app/Registry/RegistrySettings.php' => 'Sama, pada kredensial robot registry. Halaman Pengaturan menyebut sebabnya kepada operator, tetapi nilai kembalinya tetap tidak dapat dibedakan pemanggil mana pun.',
        'apps/core/app/Models/InvitationCode.php' => 'Kode undangan yang gagal didekripsi berbunyi sama dengan undangan yang memang tidak menyimpan kode — dan sama pula dengan "Anda tidak berhak melihatnya", karena pemanggilnya memulangkan null untuk ketiganya. Memisahkannya mengubah bentuk jawaban layar akses.',
    ];

    /**
     * Nilai yang dilarang dipulangkan dari dalam `catch`, dalam ejaan token PHP-nya.
     *
     * @var list<string>
     */
    private const NILAI_TERLARANG = ['null', '[]', 'array()', "''", '""', '0'];

    /**
     * Tidak ada `catch` yang memulangkan nilai yang juga sah.
     */
    public function test_tidak_ada_catch_yang_memulangkan_nilai_sah(): void
    {
        $berkas = $this->berkasPhp();

        $this->assertNotSame([], $berkas, 'Tidak satu pun berkas PHP terbaca; pemindaiannya salah alamat dan hasil hijaunya tidak berarti apa-apa.');

        $pelanggaran = [];
        $pengecualianTerpakai = [];

        foreach ($berkas as $jalur => $absolut) {
            $temuan = self::pelanggaranPada((string) file_get_contents($absolut));

            if ($temuan === []) {
                continue;
            }

            if (array_key_exists($jalur, self::DIKECUALIKAN) || array_key_exists($jalur, self::GAP_DIKETAHUI)) {
                $pengecualianTerpakai[] = $jalur;

                continue;
            }

            foreach ($temuan as $satu) {
                $pelanggaran[] = $jalur.':'.$satu['baris'].' memulangkan '.$satu['nilai'];
            }
        }

        sort($pelanggaran);

        $this->assertSame([], $pelanggaran, implode("\n", [
            'Ada `catch` yang memulangkan nilai yang juga sah:',
            ...$pelanggaran,
            '',
            'Nilai itu tidak dapat dibedakan pemanggil dari jawaban yang sungguhan. Ia akan',
            'menyatakan "tidak ada satu pun" pada keadaan yang sebenarnya "tidak terbaca", dan',
            'kalimat itu tidak pernah dibaca dari tabel, berkas, atau jawaban mana pun.',
            '',
            'Pilihannya tiga: lempar ulang; pulangkan bentuk yang menyatakan ketidaktahuan —',
            '`null` bila larik kosong sudah punya arti, atau objek hasil yang membawa sebabnya;',
            'atau, bila nilai sah itu memang tidak menyesatkan siapa pun, daftarkan berkasnya pada',
            'DIKECUALIKAN beserta alasan yang menjelaskan kenapa. Bila ia menyesatkan tetapi',
            'perbaikannya menunggu keputusan lain, tempatnya GAP_DIKETAHUI — dengan keputusan itu',
            'disebut namanya, bukan dengan alasan bahwa memperbaikinya merepotkan.',
        ]));

        $terdaftar = [...array_keys(self::DIKECUALIKAN), ...array_keys(self::GAP_DIKETAHUI)];
        $basi = array_values(array_diff($terdaftar, $pengecualianTerpakai));

        $this->assertSame([], $basi, implode("\n", [
            'Pengecualian yang tidak lagi menunjuk pelanggaran mana pun: '.implode(', ', $basi).'.',
            'Berkasnya sudah bersih, jadi entri ini sekarang hanya izin yang menganggur — dan izin',
            'yang menganggur akan dipakai kode berikutnya yang mendarat di berkas yang sama.',
            'Hapus entrinya.',
        ]));
    }

    /**
     * Penjaga di atas hijau karena tidak ada pelanggaran, bukan karena ia tidak bisa melihat.
     *
     * Tanpa test ini, "hijau" dan "buta" terlihat persis sama dari luar. Potongan kode ditulis di
     * sini, bukan disisipkan ke berkas sungguhan, supaya pembuktiannya ikut berjalan di CI setiap
     * hari — bukan hanya sekali di tangan orang yang menulisnya.
     */
    public function test_pemindai_merah_pada_bentuk_yang_benar_benar_memalsukan(): void
    {
        $kasus = [
            'null polos' => ['<?php try { a(); } catch (Throwable) { return null; }', 'null'],
            'larik kosong' => ['<?php try { a(); } catch (Throwable) { return []; }', '[]'],
            'array() lama' => ['<?php try { a(); } catch (Throwable) { return array(); }', 'array()'],
            'sesudah mencatat' => ['<?php try { a(); } catch (Throwable $e) { Log::warning("gagal"); return []; }', '[]'],
            'string kosong' => ['<?php try { a(); } catch (Throwable) { return ""; }', '""'],
            'nol' => ['<?php try { a(); } catch (Throwable) { return 0; }', '0'],
            'huruf besar' => ['<?php try { a(); } catch (Throwable) { return NULL; }', 'null'],
            'sesudah baris lain' => ['<?php try { a(); } catch (Throwable $e) { $ini = 1; report($e); return null; }', 'null'],
        ];

        foreach ($kasus as $nama => [$kode, $nilai]) {
            $temuan = self::pelanggaranPada($kode);

            $this->assertCount(1, $temuan, "Bentuk \"{$nama}\" tidak terbaca sebagai pelanggaran. Penjaga di atas hijau karena buta, bukan karena bersih.");
            $this->assertSame($nilai, $temuan[0]['nilai'], "Bentuk \"{$nama}\" terbaca, tetapi nilainya dilaporkan salah.");
        }
    }

    /**
     * Yang tidak boleh ikut merah, dan tiap barisnya punya sebab.
     *
     * Penjaga yang merah pada kode yang benar akan dimatikan, dan yang mati bersamanya adalah
     * seluruh larangan lain yang menumpang pada aturan yang sama.
     */
    public function test_pemindai_tidak_merah_pada_bentuk_yang_jujur(): void
    {
        $jujur = [
            'melempar ulang' => '<?php try { a(); } catch (Throwable $e) { throw new RuntimeException("gagal", 0, $e); }',
            'catch kosong' => '<?php try { a(); } catch (Throwable) { }',
            'false dari fungsi ya/tidak' => '<?php try { a(); } catch (Throwable) { return false; }',
            'nilai yang menyatakan kegagalan' => '<?php try { a(); } catch (Throwable $e) { return Hasil::gagal($e); }',
            'return di luar catch' => '<?php function f() { try { return g(); } catch (Throwable $e) { throw $e; } } function h() { return []; }',
            'komentar yang menceritakannya' => "<?php\n// Dulu blok ini catch (Throwable) { return null; } dan itu menyesatkan.\nfunction f(): array { return [1]; }",
            'string yang memuat bentuknya' => '<?php try { a(); } catch (Throwable $e) { throw new RuntimeException("jangan return null; di sini"); }',
            'return null di dalam closure' => '<?php try { a(); } catch (Throwable $e) { $f = function () { return null; }; throw $e; }',
        ];

        foreach ($jujur as $nama => $kode) {
            $this->assertSame([], self::pelanggaranPada($kode), "Bentuk \"{$nama}\" dilaporkan sebagai pelanggaran, padahal ia jujur. Penjaga yang merah pada kode yang benar akan dimatikan orang.");
        }
    }

    /**
     * Pelanggaran pada satu isi berkas PHP, lewat tokenizer PHP sendiri.
     *
     * Bukan ekspresi reguler: komentar yang menceritakan jalur lama, string yang memuat kata
     * `return null`, dan `catch` bersarang semuanya akan salah dibaca oleh pencocokan teks — dan
     * penjaga yang merah pada komentar akan mendapatkan komentarnya dihapus, yaitu satu-satunya
     * tempat yang menjelaskan kenapa jalur itu ditinggalkan.
     *
     * Hanya `return` di badan `catch` itu sendiri yang dihitung. Yang berada di dalam closure di
     * dalamnya milik fungsi lain, dengan pemanggil lain, dan bukan urusan penjaga ini.
     *
     * @return list<array{baris: int, nilai: string}>
     */
    public static function pelanggaranPada(string $kode): array
    {
        $tokens = token_get_all($kode);
        $jumlah = count($tokens);
        $hasil = [];

        for ($i = 0; $i < $jumlah; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
                continue;
            }

            $j = $i;

            while ($j < $jumlah && $tokens[$j] !== '{') {
                $j++;
            }

            if ($j >= $jumlah) {
                break;
            }

            $dalam = 0;

            for ($k = $j; $k < $jumlah; $k++) {
                $token = $tokens[$k];

                if ($token === '{') {
                    $dalam++;

                    continue;
                }

                if ($token === '}') {
                    $dalam--;

                    if ($dalam === 0) {
                        break;
                    }

                    continue;
                }

                // Hanya badan catch itu sendiri. `{` berikutnya berarti kita sudah masuk closure,
                // `if`, atau blok lain yang punya pemanggilnya sendiri.
                if ($dalam !== 1 || ! is_array($token) || $token[0] !== T_RETURN) {
                    continue;
                }

                $nilai = self::nilaiYangDipulangkan($tokens, $k, $jumlah);

                if ($nilai !== null && in_array($nilai, self::NILAI_TERLARANG, true)) {
                    $hasil[] = ['baris' => $token[2], 'nilai' => $nilai];
                }
            }
        }

        return $hasil;
    }

    /**
     * Ejaan ternormalkan dari nilai sebuah `return`, atau `null` bila ia bukan literal tunggal.
     *
     * Ekspresi apa pun yang lebih panjang daripada satu literal bukan urusan penjaga ini: ia
     * mungkin `Hasil::gagal($e)`, mungkin `$bawaan`, dan keduanya menyatakan sesuatu.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nilaiYangDipulangkan(array $tokens, int $mulai, int $jumlah): ?string
    {
        $bagian = [];

        for ($i = $mulai + 1; $i < $jumlah; $i++) {
            $token = $tokens[$i];

            if ($token === ';') {
                break;
            }

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $bagian[] = is_array($token) ? $token[1] : $token;

            if (count($bagian) > 3) {
                return null;
            }
        }

        $teks = strtolower(implode('', $bagian));

        return $teks === '' ? null : $teks;
    }

    /**
     * Berkas PHP yang dipindai, dipetakan jalur relatif ke jalur absolutnya.
     *
     * @return array<string, string>
     */
    private function berkasPhp(): array
    {
        // tests/Feature/Boundary -> tests -> core -> apps -> akar repo
        $akarRepo = str_replace('\\', '/', dirname(__DIR__, 5));
        $hasil = [];

        foreach (self::AKAR as $relatif) {
            $folder = $akarRepo.'/'.$relatif;

            if (! is_dir($folder)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $berkas) {
                if (! $berkas instanceof SplFileInfo) {
                    continue;
                }

                $jalur = str_replace('\\', '/', $berkas->getPathname());

                if ($berkas->getExtension() !== 'php' || str_contains($jalur, '/vendor/') || str_contains($jalur, '/tests/')) {
                    continue;
                }

                $hasil[str_replace($akarRepo.'/', '', $jalur)] = $jalur;
            }
        }

        ksort($hasil);

        return $hasil;
    }
}
