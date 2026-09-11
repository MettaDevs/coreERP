<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

/**
 * Membuat module baru dari cetakan `modules/_template`.
 *
 * **Kenapa perintah, bukan "salin foldernya lalu ganti namanya".** Sebuah module baru harus
 * benar di enam tempat sekaligus sebelum satu pun penjaga batas hijau: id manifest sama dengan
 * nama foldernya, publisher sama dengan nama folder induknya, namespace PSR-4 sama dengan
 * keduanya dalam StudlyCase, awalan tabel dinyatakan manifest **dan** terdaftar di tabel
 * pemetaan `modules/README.md`, dan package-nya terdaftar di `composer.json` Core. Tidak satu
 * pun dari keenamnya gagal dengan sendirinya saat disalin dengan tangan — yang gagal adalah
 * penjaga batas, berjam-jam kemudian, dengan pesan yang menunjuk gejala dan bukan sebabnya.
 *
 * **Penggantian dilakukan per bentuk, bukan satu `str_replace` buta.** Id module, namanya
 * dalam StudlyCase, nama tampilannya, dan awalan tabelnya adalah empat bentuk berbeda dari
 * satu nama, dan masing-masing hidup di tempat yang berbeda: `change-me` pada kode izin,
 * `ChangeMe` pada namespace, `Change Me` pada judul, `change_me_` pada nama tabel. Mengganti
 * salah satunya dengan pola yang salah menghasilkan nama tabel atau kode izin yang rusak, dan
 * rusaknya baru terlihat saat migration jalan. Penggantiannya karena itu dilakukan sekali
 * jalan dengan `strtr`, yang mencocokkan penanda terpanjang lebih dulu dan tidak pernah
 * mengganti hasil penggantiannya sendiri.
 *
 * **Masukan yang tidak sah ditolak, bukan dibersihkan diam-diam.** Sebuah id yang "diperbaiki"
 * perintah ini menghasilkan module yang namanya bukan nama yang diminta orangnya, dan
 * perbedaan itu baru ketahuan setelah folder, manifest, dan tabel pemetaan sudah ditulis.
 *
 * **Module yang sudah ada tidak pernah ditimpa.** Menolak berarti kehilangan satu percobaan;
 * menimpa berarti kehilangan pekerjaan orang lain.
 */
final class ModuleMakeCommand extends Command
{
    protected $signature = 'module:make
        {module : Id module baru, huruf kecil dan tanda hubung, misalnya kelola-contoh}
        {--penerbit=apperp : Id penerbit, sekaligus nama folder induknya di modules/}
        {--nama= : Nama tampilan module; bawaannya diturunkan dari id module}
        {--awalan= : Awalan tabel, diakhiri garis bawah; bawaannya diturunkan dari id module}';

    protected $description = 'Buat module baru dari cetakan modules/_template';

    /**
     * Berkas cetakan yang tidak ikut tersalin.
     *
     * `README.md` bercerita tentang cetakannya — penanda apa yang diganti, dan kenapa foldernya
     * tidak boleh disalin dengan tangan. Ikut tersalin, ia menjadi dokumen yang salah alamat di
     * module baru, dan dokumen yang salah alamat lebih buruk daripada tidak ada.
     *
     * @var list<string>
     */
    private const BERKAS_TIDAK_DISALIN = ['README.md'];

    /**
     * Kata yang tidak boleh menjadi penggal namespace PHP.
     *
     * Sebuah module bernama `list` menghasilkan `Modules\Apperp\List\` — nama yang tidak bisa
     * diurai PHP sama sekali, sehingga kegagalannya bukan test merah melainkan galat sintaks
     * pada setiap berkas module. Daftarnya sengaja pendek: hanya kata yang mungkin terpikir
     * sebagai nama module.
     *
     * @var list<string>
     */
    private const KATA_TERLARANG = [
        'array', 'class', 'default', 'echo', 'exit', 'for', 'foreach', 'function', 'global',
        'if', 'include', 'interface', 'list', 'match', 'namespace', 'new', 'print', 'return',
        'static', 'switch', 'trait', 'use', 'while',
    ];

    /** Panjang maksimum awalan tabel, supaya nama indeks tidak melewati batas identifier PostgreSQL. */
    private const PANJANG_AWALAN_MAKSIMUM = 16;

    public function handle(): int
    {
        $akar = dirname(base_path(), 2);
        $cetakan = $akar.'/modules/_template';

        if (! is_dir($cetakan)) {
            $this->error(sprintf('Cetakan module tidak ada di %s. Tanpa cetakannya tidak ada yang bisa disalin.', $cetakan));

            return self::FAILURE;
        }

        // Hanya spasi di ujung yang dibuang. Huruf besar **tidak** dikecilkan diam-diam:
        // sebuah id yang "diperbaiki" di sini menghasilkan module yang namanya bukan nama yang
        // diminta orangnya, dan perbedaan itu baru ketahuan setelah folder, manifest, dan tabel
        // pemetaan sudah ditulis.
        $module = trim((string) $this->argument('module'));
        $penerbit = trim((string) $this->option('penerbit'));
        $awalan = trim((string) $this->option('awalan'));
        $nama = trim((string) $this->option('nama'));

        $awalan = $awalan === '' ? str_replace('-', '_', $module).'_' : $awalan;
        $nama = $nama === '' ? self::judul($module) : $nama;

        $moduleYangAda = $this->moduleYangAda($akar);
        $tujuan = $akar.'/modules/'.$penerbit.'/'.$module;

        $keberatan = $this->keberatan($module, $penerbit, $nama, $awalan, $tujuan, $moduleYangAda);

        if ($keberatan !== []) {
            $this->error('Module tidak dibuat. Yang harus dibereskan lebih dulu:');

            foreach ($keberatan as $baris) {
                $this->line('  - '.$baris);
            }

            return self::FAILURE;
        }

        $penanda = [
            // Diurutkan dari yang paling panjang supaya mudah dibaca; `strtr` sendiri sudah
            // mencocokkan penanda terpanjang lebih dulu, apa pun urutan penulisannya.
            'PenerbitContoh' => self::studly($penerbit),
            'penerbit-contoh' => $penerbit,
            'change_me_' => $awalan,
            'ChangeMe' => self::studly($module),
            'Change Me' => $nama,
            'change-me' => $module,
        ];

        $ditulis = $this->salinCetakan($cetakan, $tujuan, $penanda);

        $this->info(sprintf('Module %s/%s dibuat dari cetakan:', $penerbit, $module));

        foreach ($ditulis as $berkas) {
            $this->line('  modules/'.$penerbit.'/'.$module.'/'.$berkas);
        }

        $this->daftarkanAwalanPadaReadme($akar, $penerbit, $module, $awalan);
        $paket = $this->daftarkanPadaComposer($penerbit, $module);

        $this->newLine();
        $this->line('Satu langkah tersisa, dan ia tidak bisa dilewati: kelas module belum bisa dimuat');
        $this->line('sampai Composer memasang package-nya dari repository path di composer.json.');
        $this->newLine();
        $this->line('  cd apps/control-plane');
        $this->line('  composer update '.$paket);
        $this->line('  php artisan module:list');
        $this->line('  vendor/bin/phpunit tests/Feature/Boundary');
        $this->newLine();
        $this->line('Sesudah itu isinya yang diganti: lihat modules/_template/README.md dan');
        $this->line('docs/apps/membangun-app-baru.md untuk urutan tahapnya.');

        return self::SUCCESS;
    }

    /**
     * Alasan module ini tidak boleh dibuat, seluruhnya sekaligus.
     *
     * Dikumpulkan, bukan dilaporkan satu per satu lalu berhenti: orang yang salah menulis id
     * **dan** awalan akan menjalankan perintah ini dua kali untuk mengetahui keduanya.
     *
     * @param  array<string, string>  $moduleYangAda
     * @return list<string>
     */
    private function keberatan(
        string $module,
        string $penerbit,
        string $nama,
        string $awalan,
        string $tujuan,
        array $moduleYangAda,
    ): array {
        $keberatan = [];
        $pola = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

        if (preg_match($pola, $module) !== 1 || strlen($module) < 3 || strlen($module) > 40) {
            $keberatan[] = sprintf(
                'Id module "%s" tidak sah. Yang berlaku: 3 sampai 40 huruf kecil, angka, dan tanda hubung '.
                'di antaranya, misalnya "kelola-contoh". Id inilah yang menjadi nama folder, awalan tiap '.
                'kode izin, dan jalur rutenya.',
                $module,
            );
        }

        if (preg_match($pola, $penerbit) !== 1 || strlen($penerbit) < 3 || strlen($penerbit) > 40) {
            $keberatan[] = sprintf('Id penerbit "%s" tidak sah; aturannya sama dengan id module.', $penerbit);
        }

        foreach (['module' => $module, 'penerbit' => $penerbit] as $peran => $nilai) {
            if (in_array($nilai, self::KATA_TERLARANG, true)) {
                $keberatan[] = sprintf(
                    'Id %s "%s" adalah kata yang tidak boleh menjadi penggal namespace PHP; namespace '.
                    'module tidak akan bisa diurai sama sekali.',
                    $peran,
                    $nilai,
                );
            }
        }

        if ($module === 'change-me' || $penerbit === 'penerbit-contoh') {
            $keberatan[] = 'Penanda cetakan tidak boleh dipakai sebagai nama sungguhan; registry menolak module ber-id "change-me".';
        }

        if (preg_match('/^[a-z][a-z0-9_]*_$/', $awalan) !== 1 || strlen($awalan) > self::PANJANG_AWALAN_MAKSIMUM) {
            $keberatan[] = sprintf(
                'Awalan tabel "%s" tidak sah. Yang berlaku: huruf kecil, angka, dan garis bawah, diawali '.
                'huruf, diakhiri garis bawah, paling panjang %d karakter — misalnya "contoh_".',
                $awalan,
                self::PANJANG_AWALAN_MAKSIMUM,
            );
        }

        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\-]*$/u', $nama) !== 1 || mb_strlen($nama) > 60) {
            $keberatan[] = sprintf(
                'Nama tampilan "%s" tidak sah. Ia ditulis apa adanya ke app.yaml, jadi yang diterima hanya '.
                'huruf, angka, spasi, titik, dan tanda hubung, paling panjang 60 karakter.',
                $nama,
            );
        }

        if (is_dir($tujuan)) {
            $keberatan[] = sprintf(
                '%s sudah ada. Perintah ini tidak pernah menimpa: kehilangan satu percobaan lebih murah '.
                'daripada kehilangan pekerjaan orang lain.',
                self::jalurRingkas($tujuan),
            );
        }

        foreach ($moduleYangAda as $jalur => $awalanTerpakai) {
            [, $namaFolder] = explode('/', $jalur, 2);

            if ($namaFolder === $module) {
                $keberatan[] = sprintf('Id module "%s" sudah dipakai modules/%s; id module unik di seluruh runtime.', $module, $jalur);
            }

            // Bukan hanya kesamaan persis. Kepemilikan tabel diperiksa dengan awalan, jadi
            // "aset_" dan "aset_lama_" saling menelan: tabel milik yang satu terbaca sebagai
            // milik yang lain, dan penjaga batas tabel akan menyalahkan module yang keliru.
            if ($awalanTerpakai !== '' && (str_starts_with($awalanTerpakai, $awalan) || str_starts_with($awalan, $awalanTerpakai))) {
                $keberatan[] = sprintf(
                    'Awalan tabel "%s" bertabrakan dengan "%s" milik modules/%s. Awalan yang saling menelan '.
                    'membuat tabel satu module terbaca sebagai milik module lain.',
                    $awalan,
                    $awalanTerpakai,
                    $jalur,
                );
            }
        }

        return $keberatan;
    }

    /**
     * Menyalin cetakan ke folder module baru, mengganti tiap penanda di jalur maupun isinya.
     *
     * @param  array<string, string>  $penanda
     * @return list<string> jalur relatif berkas yang ditulis, terurut
     */
    private function salinCetakan(string $cetakan, string $tujuan, array $penanda): array
    {
        $ditulis = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cetakan, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $berkas) {
            if (! $berkas->isFile()) {
                continue;
            }

            $relatif = str_replace('\\', '/', substr($berkas->getPathname(), strlen($cetakan) + 1));

            if (in_array($relatif, self::BERKAS_TIDAK_DISALIN, true)) {
                continue;
            }

            $tujuanRelatif = $this->jalurTujuan(strtr($relatif, $penanda));
            $jalur = $tujuan.'/'.$tujuanRelatif;

            if (! is_dir(dirname($jalur))) {
                mkdir(dirname($jalur), 0o755, true);
            }

            file_put_contents($jalur, strtr((string) file_get_contents($berkas->getPathname()), $penanda));
            $ditulis[] = $tujuanRelatif;
        }

        sort($ditulis);

        return $ditulis;
    }

    /**
     * Jalur relatif sebuah berkas di module baru.
     *
     * Cap waktu pada nama migration diganti waktu sekarang. Membiarkan cap waktu cetakan
     * berarti setiap module yang pernah dibuat darinya membawa cap waktu yang sama, dan urutan
     * migration di antara mereka ditentukan urutan abjad nama berkas — bukan urutan yang
     * dimaksudkan siapa pun.
     */
    private function jalurTujuan(string $relatif): string
    {
        if (! str_starts_with($relatif, 'database/migrations/')) {
            return $relatif;
        }

        $nama = basename($relatif);
        $baru = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', now()->format('Y_m_d_His').'_', $nama, 1);

        return dirname($relatif).'/'.($baru ?? $nama);
    }

    /**
     * Module yang sudah ada, dipetakan "<penerbit>/<module>" ke awalan tabelnya.
     *
     * @return array<string, string>
     */
    private function moduleYangAda(string $akar): array
    {
        $hasil = [];

        foreach (glob($akar.'/modules/*/*/app.yaml') ?: [] as $manifest) {
            $folder = dirname($manifest);
            $jalur = basename(dirname($folder)).'/'.basename($folder);

            $isi = Yaml::parseFile($manifest);
            $awalan = is_array($isi) && isset($isi['table_prefix']) && is_string($isi['table_prefix'])
                ? $isi['table_prefix']
                : '';

            $hasil[$jalur] = $awalan;
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * Menambahkan baris module baru ke tabel pemetaan pada `modules/README.md`.
     *
     * Tabel itu bukan dokumentasi yang boleh tertinggal: `SusunanManifestModulTest` menuntutnya
     * sama persis dengan manifest yang ada, jadi module baru tanpa barisnya membuat penjaga
     * batas merah. Ia ada supaya tabrakan awalan ketahuan saat peninjauan, bukan saat migrasi
     * jalan.
     */
    private function daftarkanAwalanPadaReadme(string $akar, string $penerbit, string $module, string $awalan): void
    {
        $berkas = $akar.'/modules/README.md';

        if (! is_file($berkas)) {
            $this->warn(sprintf('%s tidak ada; baris awalan tabel harus ditambahkan sendiri.', self::jalurRingkas($berkas)));

            return;
        }

        $isi = (string) file_get_contents($berkas);
        $baris = sprintf(
            '| `%s/%s` | `Modules\%s\%s\` | `%s` |',
            $penerbit,
            $module,
            self::studly($penerbit),
            self::studly($module),
            $awalan,
        );

        $garis = preg_split('/\R/', $isi);

        if ($garis === false) {
            $this->warn('Isi modules/README.md tidak terbaca; baris awalan tabel harus ditambahkan sendiri.');

            return;
        }

        $indeks = $this->indeksBarisTabel($garis);

        if ($indeks === []) {
            $this->warn('Tabel pemetaan pada modules/README.md tidak ditemukan; barisnya harus ditambahkan sendiri.');

            return;
        }

        $kunci = $penerbit.'/'.$module;
        $sisip = $indeks[count($indeks) - 1] + 1;

        foreach ($indeks as $nomor) {
            if (preg_match('/^\|\s*`([^`]+)`/', $garis[$nomor], $cocok) === 1 && strcmp($cocok[1], $kunci) > 0) {
                $sisip = $nomor;

                break;
            }
        }

        array_splice($garis, $sisip, 0, [$baris]);
        file_put_contents($berkas, implode(self::akhirBaris($isi), $garis));

        $this->line(sprintf('  modules/README.md — baris awalan tabel "%s" ditambahkan', $awalan));
    }

    /**
     * Nomor baris isi tabel pemetaan awalan pada `modules/README.md`.
     *
     * Pemindaian dibatasi pada bagian "Namespace dan awalan tabel" — sama seperti penjaganya —
     * karena README memuat tabel lain, dan menyisipkan baris ke tabel yang salah membuat
     * dokumennya salah sekaligus penjaganya tetap merah.
     *
     * @param  list<string>  $garis
     * @return list<int>
     */
    private function indeksBarisTabel(array $garis): array
    {
        $didalam = false;
        $indeks = [];

        foreach ($garis as $nomor => $baris) {
            if (str_starts_with($baris, '## ')) {
                $didalam = str_starts_with($baris, '## Namespace dan awalan tabel');

                continue;
            }

            // Hanya baris isi yang dihitung: judul dan garis pemisahnya tidak diapit backtick.
            if ($didalam && preg_match('/^\|\s*`[^`]+`\s*\|\s*`[^`]+`\s*\|\s*`[^`]+`\s*\|/', $baris) === 1) {
                $indeks[] = $nomor;
            }
        }

        return $indeks;
    }

    /**
     * Menambahkan package module ke `require` pada `composer.json` Core.
     *
     * Tanpa baris ini, kelas module tidak pernah bisa dimuat: repository path di composer.json
     * hanya memberitahu Composer di mana package module dicari, bukan bahwa ia dipakai.
     * `ModuleAutoloadTest` menuntut tiap kelas module benar-benar bisa dimuat dengan nama yang
     * dijanjikan `composer.json`-nya, jadi module yang tidak terdaftar di sini membuat penjaga
     * itu merah.
     *
     * Berkasnya disunting sebagai teks, bukan diurai lalu ditulis ulang sebagai JSON: menulis
     * ulang seluruh berkas mengubah urutan kunci, tanda kutip, dan lekukannya, sehingga satu
     * baris tambahan muncul sebagai diff seratus baris yang tidak bisa ditinjau.
     *
     * @return string nama package yang didaftarkan
     */
    private function daftarkanPadaComposer(string $penerbit, string $module): string
    {
        $paket = $penerbit.'/'.$module;
        $berkas = base_path('composer.json');
        $isi = (string) file_get_contents($berkas);
        $garis = preg_split('/\R/', $isi);

        if ($garis === false) {
            $this->warn('Isi composer.json tidak terbaca; package module harus didaftarkan sendiri.');

            return $paket;
        }

        $mulai = null;

        foreach ($garis as $nomor => $baris) {
            if (trim($baris) === '"require": {') {
                $mulai = $nomor;

                break;
            }
        }

        if ($mulai === null) {
            $this->warn('Blok "require" pada composer.json tidak ditemukan; package module harus didaftarkan sendiri.');

            return $paket;
        }

        $sisip = null;
        $terakhir = null;

        for ($nomor = $mulai + 1; $nomor < count($garis); $nomor++) {
            if (trim($garis[$nomor]) === '},') {
                break;
            }

            if (preg_match('/^\s*"([^"]+)":/', $garis[$nomor], $cocok) !== 1) {
                continue;
            }

            $terakhir = $nomor;

            // `php` selalu di puncak daftar, sesuai `sort-packages` milik Composer sendiri.
            if ($cocok[1] === 'php') {
                continue;
            }

            if ($sisip === null && strcmp($cocok[1], $paket) > 0) {
                $sisip = $nomor;
            }
        }

        if ($terakhir === null) {
            $this->warn('Daftar "require" pada composer.json kosong; package module harus didaftarkan sendiri.');

            return $paket;
        }

        if ($sisip === null) {
            // Masuk paling bawah: baris yang tadinya terakhir kini butuh koma di ujungnya.
            $garis[$terakhir] = rtrim($garis[$terakhir]).',';
            $sisip = $terakhir + 1;
            $baris = '        "'.$paket.'": "@dev"';
        } else {
            $baris = '        "'.$paket.'": "@dev",';
        }

        array_splice($garis, $sisip, 0, [$baris]);
        file_put_contents($berkas, implode(self::akhirBaris($isi), $garis));

        $this->line(sprintf('  apps/control-plane/composer.json — package "%s" ditambahkan ke require', $paket));

        return $paket;
    }

    /**
     * Akhir baris yang dipakai sebuah berkas.
     *
     * Dibaca dari berkasnya, bukan ditetapkan, supaya menyisipkan satu baris tidak mengubah
     * seluruh berkas menjadi diff yang tidak bisa ditinjau di mesin yang mengecek keluar
     * dengan CRLF.
     */
    private static function akhirBaris(string $isi): string
    {
        return str_contains($isi, "\r\n") ? "\r\n" : "\n";
    }

    private static function studly(string $nama): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $nama)));
    }

    private static function judul(string $nama): string
    {
        return ucwords(str_replace('-', ' ', $nama));
    }

    private static function jalurRingkas(string $jalur): string
    {
        $jalur = str_replace('\\', '/', $jalur);
        $potong = strpos($jalur, '/modules/');

        return $potong === false ? $jalur : substr($jalur, $potong + 1);
    }
}
