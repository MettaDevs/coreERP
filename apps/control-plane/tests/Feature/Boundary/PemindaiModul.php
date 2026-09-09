<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\ModulSedangDipindah;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Pemindaian berkas modul, dipakai bersama oleh penjaga batas dan oleh pemeriksaan basi.
 *
 * Sebelumnya tiap penjaga membawa pemindainya sendiri. Itu tidak apa-apa selama tidak ada
 * yang perlu menanyakan hal yang sama dua kali. Pemeriksaan basi mengubah itu: ia harus
 * memindai modul yang dikecualikan dengan aturan **yang persis sama** seperti penjaganya,
 * karena kalau tidak, ia bisa menyatakan sebuah modul "sudah bersih" sementara penjaganya
 * masih akan merah begitu pengecualiannya dibuang. Dua salinan aturan yang harus selalu sama
 * adalah dua salinan yang akan menyimpang, jadi aturannya dipindahkan ke sini.
 *
 * Akar folder modul sengaja bisa diganti lewat konstruktor. Itu bukan kelonggaran yang tidak
 * terpakai: test yang membuktikan "melonggarkan untuk satu modul tidak melonggarkan untuk
 * modul lain" butuh dua modul palsu yang hampir identik, dan menuliskannya ke `modules/`
 * sungguhan berarti sebuah run yang gagal di tengah bisa meninggalkan modul palsu yang lalu
 * terbaca `module:list`, penjaga lain, Pint, dan PHPStan. Modul palsu itu dibuat di folder
 * sementara, jadi kemungkinan tersebut tidak ada sejak awal.
 */
final class PemindaiModul
{
    public function __construct(private readonly string $akar) {}

    /**
     * Pemindai untuk folder `modules/` repo ini.
     */
    public static function padaRepo(): self
    {
        // tests/Feature/Boundary -> tests -> control-plane -> apps -> akar repo
        return new self(dirname(__DIR__, 5).'/modules');
    }

    public function akar(): string
    {
        return $this->akar;
    }

    /**
     * Modul di bawah `<akar>/<publisher>/<modul>/`, dipetakan nama folder ke jalur foldernya.
     *
     * `composer.json` dipakai sebagai penanda "ini folder modul" karena setiap modul wajib
     * punya satu, dan karena penjaga namespace memang tidak pernah membaca `app.yaml`.
     *
     * @return array<string, string>
     */
    public function folderModul(): array
    {
        $hasil = [];

        foreach (glob($this->akar.'/*/*/composer.json') ?: [] as $manifest) {
            $folder = dirname($manifest);
            $hasil[basename($folder)] = $folder;
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * Modul yang punya folder migration, beserta awalan tabel yang dinyatakan manifestnya.
     *
     * Modul yang sedang dipindah tidak ikut. Lihat penjelasan asimetrinya di
     * `ModuleTableBoundaryTest`; ringkasnya, penjaga tabel **menjalankan** migration, jadi
     * ia tidak bisa sekadar mengabaikan hasilnya seperti dua penjaga pembaca berkas.
     *
     * @return list<array{id: string, nama: string, awalan: string, migrations: string}>
     */
    public function modulDenganMigration(ModulSedangDipindah $dipindah): array
    {
        $modules = [];

        foreach (glob($this->akar.'/*/*/app.yaml') ?: [] as $manifest) {
            $folder = dirname($manifest);
            $nama = basename($folder);
            $migrations = $folder.'/database/migrations';

            if ($dipindah->menandai($nama) || ! is_dir($migrations)) {
                continue;
            }

            /** @var array<string, mixed> $isi */
            $isi = Yaml::parseFile($manifest);

            $modules[] = [
                'id' => (string) ($isi['id'] ?? $nama),
                'nama' => $nama,
                'awalan' => (string) ($isi['table_prefix'] ?? ''),
                'migrations' => $migrations,
            ];
        }

        return $modules;
    }

    /**
     * Nama namespace sebuah folder modul, misalnya `Apperp\ContohA`.
     */
    public static function namespaceModul(string $folder): string
    {
        return self::studly(basename(dirname($folder))).'\\'.self::studly(basename($folder));
    }

    /**
     * Berkas PHP di dalam sebuah folder modul.
     *
     * @return list<SplFileInfo>
     */
    public function berkasPhp(string $folder, bool $tanpaMigration = false): array
    {
        if (! is_dir($folder)) {
            return [];
        }

        $berkas = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if (! $item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }

            if ($tanpaMigration && str_contains(str_replace('\\', '/', $item->getPathname()), '/database/migrations/')) {
                continue;
            }

            $berkas[] = $item;
        }

        return $berkas;
    }

    /**
     * Berkas modul yang menyebut namespace modul lain.
     *
     * @return list<string>
     */
    public function pelanggaranNamespace(string $folder): array
    {
        $namespace = self::namespaceModul($folder);
        $pelanggaran = [];

        foreach ($this->berkasPhp($folder) as $berkas) {
            $isi = (string) file_get_contents($berkas->getPathname());

            foreach (self::namespaceYangDisebut($isi) as $disebut) {
                if ($disebut !== $namespace) {
                    $pelanggaran[] = sprintf('%s menyebut %s', self::jalurRingkas($berkas->getPathname()), $disebut);
                }
            }
        }

        return array_values(array_unique($pelanggaran));
    }

    /**
     * Berkas modul yang menyentuh kelas Core di luar kontrak.
     *
     * @return list<string>
     */
    public function pelanggaranKelasCore(string $folder): array
    {
        $pelanggaran = [];

        foreach ($this->berkasPhp($folder) as $berkas) {
            $isi = (string) file_get_contents($berkas->getPathname());

            foreach (self::kelasCoreYangDisebut($isi) as $kelas) {
                $pelanggaran[] = self::jalurRingkas($berkas->getPathname()).' menyebut '.$kelas;
            }
        }

        return array_values(array_unique($pelanggaran));
    }

    /**
     * Berkas modul yang memakai query builder mentah.
     *
     * Migration tidak ikut diperiksa: ia memang menulis SQL langsung dan berjalan sebelum ada
     * tenant mana pun, jadi tidak masuk akal menuntutnya tersaring.
     *
     * @return list<string>
     */
    public function pelanggaranQueryMentah(string $folder): array
    {
        $pelanggaran = [];

        foreach ($this->berkasPhp($folder, tanpaMigration: true) as $berkas) {
            $isi = (string) file_get_contents($berkas->getPathname());

            foreach (self::queryMentahYangDipakai($isi) as $pola) {
                $pelanggaran[] = self::jalurRingkas($berkas->getPathname()).' memakai '.$pola;
            }
        }

        return array_values(array_unique($pelanggaran));
    }

    /**
     * Berkas modul yang masih melompat ke Core lewat HTTP.
     *
     * **Yang dilarang, dan kenapa batasnya di situ.** Bukan kata `Http::`, melainkan dua hal
     * yang keduanya diperlukan sebuah lompatan HTTP ke Core: klien yang membuka koneksi, dan
     * setelan yang menyebut alamat serta kredensial Core. Salah satu saja sudah cukup untuk
     * merah, karena masing-masing sendirian sudah tidak punya alasan hidup di dalam satu
     * runtime — Core berada di proses yang sama, jadi tidak ada yang perlu dihubungi dan tidak
     * ada token service yang perlu dibawa.
     *
     * Dua hal dipindai, bukan satu, karena masing-masing menutup lubang yang dilewatkan yang
     * lain. Memindai klien saja melewatkan orang yang memakai Guzzle langsung lewat variabel;
     * memindai alamat saja melewatkan orang yang menuliskan URL-nya di berkas setelan.
     *
     * **Berkas test tidak ikut dipindai.** Yang dijaga adalah kode yang berjalan di produksi.
     * `Http::fake` dan `Http::preventStrayRequests()` di dalam test bukan lompatan; keduanya
     * justru alat yang membuat ketiadaan lompatan bisa dibuktikan, dan melarangnya akan
     * menghapus persis penjagaan yang diminta task ini. Pemalsuan yang sudah tidak dipakai
     * siapa pun memang menyesatkan, tetapi itu kebersihan test dan bukan batas arsitektur.
     *
     * **Komentar tidak ikut dihitung, dan ini bukan kelonggaran melainkan syarat.** Satu-satunya
     * `Http::` yang tersisa di `src/` module aset hari ini berada di dalam docblock yang
     * menceritakan jalur lama. Penjaga yang mencocokkan teks mentah akan merah karena kalimat
     * sejarah, dan orang yang membacanya akan menghapus kalimat itu — bukan memperbaiki apa
     * pun. Karena itu berkasnya dibaca sebagai token PHP dan komentarnya dibuang lebih dulu,
     * sehingga yang tersisa hanya kode yang benar-benar dijalankan.
     *
     * @return list<string>
     */
    public function pelanggaranLompatanHttp(string $folder): array
    {
        $pelanggaran = [];

        foreach ($this->berkasPhp($folder) as $berkas) {
            $jalur = str_replace('\\', '/', $berkas->getPathname());

            if (str_contains($jalur, '/tests/')) {
                continue;
            }

            $isi = (string) file_get_contents($berkas->getPathname());

            foreach (self::lompatanHttpYangDipakai($isi) as $pola) {
                $pelanggaran[] = self::jalurRingkas($berkas->getPathname()).' memakai '.$pola;
            }
        }

        return array_values(array_unique($pelanggaran));
    }

    /**
     * Seluruh pelanggaran berkas sebuah modul, lintas ketiga pemeriksaan pembaca berkas.
     *
     * `pelanggaranLompatanHttp()` sengaja **tidak** ikut di sini. Daftar ini adalah dasar
     * pemeriksaan basi `ModulSedangDipindah`, dan pemeriksaan itu hanya boleh menghitung
     * dimensi yang memang dikecualikan daftar tersebut. Penjaga lompatan HTTP tidak
     * mengecualikan siapa pun, jadi memasukkannya ke sini akan membuat "modul ini sudah bersih"
     * berarti sesuatu yang lain daripada "pengecualiannya sudah boleh dibuang".
     *
     * Dipakai pemeriksaan basi. Sengaja digabung, bukan diperiksa satu per satu: sebuah entri
     * pengecualian berlaku untuk satu modul secara utuh, jadi ia baru boleh dinyatakan basi
     * bila modulnya bersih pada **semua** dimensi. Kalau tiap dimensi memeriksa basi
     * sendiri-sendiri, modul yang namespace-nya sudah dibereskan tetapi penyaringan tenant-nya
     * belum akan dituntut membuang entrinya, dan penjaga berikutnya langsung merah.
     *
     * @return list<string>
     */
    public function pelanggaranBerkas(string $folder): array
    {
        return array_values(array_merge(
            $this->pelanggaranNamespace($folder),
            $this->pelanggaranKelasCore($folder),
            $this->pelanggaranQueryMentah($folder),
        ));
    }

    /**
     * Nama module yang disebut sebuah isi berkas, misalnya `Apperp\ContohA`.
     *
     * Garis miring ganda ikut dicocokkan karena nama kelas di dalam string PHP ditulis
     * dengan garis miring ganda.
     *
     * @return list<string>
     */
    public static function namespaceYangDisebut(string $isi): array
    {
        // Lookbehind-nya penting. Tanpa itu, pola ini juga cocok di tengah
        // App\\Support\\Modules\\Contracts\\..., lalu membaca "Contracts" sebagai nama
        // publisher — sebuah module yang tidak pernah ada. Yang dicari hanya `Modules` di
        // awal sebuah nama, bukan sebagai potongan di tengahnya. Nama yang diawali satu
        // garis miring — bentuk lengkap seperti \\Modules\\Apperp\\... — tetap ditangkap,
        // karena itu justru bentuk yang paling mungkin dipakai untuk menembus batas.
        preg_match_all('/(?<![A-Za-z0-9_]\\\\)(?<![A-Za-z0-9_])Modules\\\\{1,2}([A-Za-z0-9_]+)\\\\{1,2}([A-Za-z0-9_]+)/', $isi, $cocok, PREG_SET_ORDER);

        $hasil = array_map(
            static fn (array $bagian): string => $bagian[1].'\\'.$bagian[2],
            $cocok,
        );

        $hasil = array_values(array_unique($hasil));
        sort($hasil);

        return $hasil;
    }

    /**
     * Kelas Core yang disebut sebuah isi berkas, kecuali yang memang dikontrakkan.
     *
     * Yang diizinkan hanya `App\\Support\\Modules\\Contracts`, dan itu satu-satunya
     * kalimat aturannya. Sebelumnya seluruh `App\\Support\\Modules` diizinkan supaya model
     * module bisa menyebut `TenantScope` — dan itu berarti kelas apa pun yang kelak ditaruh
     * di folder itu ikut boleh disentuh module, tanpa ada yang menahan dan tanpa ada yang
     * memutuskan. Sekarang model memakai trait `MilikTenant` dan seeder mewarisi
     * `SeederModule`, keduanya di dalam `Contracts`, jadi aturannya bisa kembali sempit.
     *
     * @return list<string>
     */
    public static function kelasCoreYangDisebut(string $isi): array
    {
        preg_match_all('/App(?:\\\\{1,2}[A-Za-z0-9_]+)+/', $isi, $cocok);

        $hasil = [];

        foreach ($cocok[0] as $nama) {
            $rapi = str_replace('\\\\', '\\', $nama);

            if (str_starts_with($rapi, 'App\\Support\\Modules\\Contracts\\')) {
                continue;
            }

            $hasil[] = $rapi;
        }

        $hasil = array_values(array_unique($hasil));
        sort($hasil);

        return $hasil;
    }

    /**
     * Pola query builder mentah yang dipakai sebuah isi berkas.
     *
     * @return list<string>
     */
    public static function queryMentahYangDipakai(string $isi): array
    {
        $hasil = [];

        foreach (['DB::table(', 'DB::select(', 'DB::statement('] as $pola) {
            if (str_contains($isi, $pola)) {
                $hasil[] = $pola;
            }
        }

        return $hasil;
    }

    /**
     * Pola lompatan HTTP ke Core yang dipakai sebuah isi berkas.
     *
     * Dipisahkan sebagai fungsi statis, seperti tiga pemeriksaan tetangganya, supaya ia bisa
     * diuji atas potongan kode yang ditulis di dalam test. Tanpa itu, satu-satunya cara
     * membuktikan penjaga ini bisa merah adalah menyisipkan pelanggaran ke berkas modul
     * sungguhan — sesuatu yang hanya bisa dilakukan orang, tidak bisa dilakukan alur CI.
     *
     * @return list<string>
     */
    public static function lompatanHttpYangDipakai(string $isi): array
    {
        $kode = self::kodeTanpaKomentar($isi);
        $hasil = [];

        // Klien yang membuka koneksi keluar.
        //
        // Lookbehind-nya hanya menolak huruf, angka, dan garis bawah — **bukan** garis miring
        // terbalik. Versi pertama menolak garis miring juga, dan itu melewatkan justru bentuk
        // yang paling mungkin dipakai untuk menembus batas:
        // `\Illuminate\Support\Facades\Http::get(...)` yang ditulis lengkap tanpa `use`. Lubang
        // itu ditemukan dengan menyisipkan pemanggilan sungguhan ke berkas module dan melihat
        // penjaganya tetap tidak menyebutnya. Yang ditolak sekarang hanya nama lain yang
        // kebetulan berakhiran Http, misalnya `ClientHttp::`.
        $klien = [
            'Http::' => '/(?<![A-Za-z0-9_])Http::/',
            'GuzzleHttp' => '/(?<![A-Za-z0-9_])GuzzleHttp\\\\{1,2}/',
            'curl_init(' => '/(?<![A-Za-z0-9_])curl_(init|exec|setopt)\s*\(/',
            'fsockopen(' => '/(?<![A-Za-z0-9_])fsockopen\s*\(/',
            'file_get_contents( ke URL' => '/(?<![A-Za-z0-9_])(file_get_contents|fopen)\s*\(\s*[\'"]https?:\/\//',
        ];

        // Alamat dan kredensial Core. Semuanya hanya berguna untuk satu hal: menghubungi Core
        // dari luar prosesnya. Di dalam satu runtime, menyebutnya berarti jalur lamanya kembali.
        $alamat = [
            'services.coreerp' => '/services\.coreerp/',
            'COREERP_URL' => '/COREERP_(URL|BASE_URL|SERVICE_TOKEN|APP_ID)/',
            'X-CoreERP-Service-Token' => '/X-CoreERP-Service-Token/i',
            '/api/internal/v1/' => '#/api/internal/v1/#',
        ];

        foreach (array_merge($klien, $alamat) as $nama => $pola) {
            if (preg_match($pola, $kode) === 1) {
                $hasil[] = $nama;
            }
        }

        return $hasil;
    }

    /**
     * Isi berkas PHP tanpa komentarnya, dirangkai kembali menjadi teks.
     *
     * String literal sengaja **dipertahankan**: alamat endpoint dan kunci setelan Core justru
     * hidup di dalam string, dan membuangnya akan membuat separuh aturan tidak bisa ditegakkan.
     * Yang dibuang hanya komentar, karena cerita tentang jalur lama bukan jalur lama.
     *
     * Berkas yang tidak bisa ditokenisasi dikembalikan apa adanya. Itu pilihan yang disengaja:
     * lebih baik penjaga ini terlalu galak pada berkas yang rusak daripada diam-diam melewatkan
     * seluruh isinya.
     */
    private static function kodeTanpaKomentar(string $isi): string
    {
        try {
            $token = @token_get_all($isi);
        } catch (\Throwable) {
            return $isi;
        }

        $kode = '';

        foreach ($token as $bagian) {
            if (is_string($bagian)) {
                $kode .= $bagian;

                continue;
            }

            if (in_array($bagian[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                // Diganti satu spasi, bukan dihapus, supaya dua token yang mengapitnya tidak
                // menempel dan membentuk pola yang tidak pernah ditulis siapa pun.
                $kode .= ' ';

                continue;
            }

            $kode .= $bagian[1];
        }

        return $kode;
    }

    public static function jalurRingkas(string $jalur): string
    {
        $jalur = str_replace('\\', '/', $jalur);
        $potong = strpos($jalur, '/modules/');

        return $potong === false ? $jalur : substr($jalur, $potong + 1);
    }

    private static function studly(string $nama): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $nama)));
    }
}
