<?php

declare(strict_types=1);

namespace App\Platform\Modules\Console;

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
    private const UNCOPIED_FILES = ['README.md'];

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
    private const FORBIDDEN_WORDS = [
        'array', 'class', 'default', 'echo', 'exit', 'for', 'foreach', 'function', 'global',
        'if', 'include', 'interface', 'list', 'match', 'namespace', 'new', 'print', 'return',
        'static', 'switch', 'trait', 'use', 'while',
    ];

    /** Panjang maksimum awalan tabel, supaya nama indeks tidak melewati batas identifier PostgreSQL. */
    private const MAX_PREFIX_LENGTH = 16;

    public function handle(): int
    {
        $root = dirname(base_path(), 2);
        $template = $root.'/modules/_template';

        if (! is_dir($template)) {
            $this->error(sprintf('Cetakan module tidak ada di %s. Tanpa cetakannya tidak ada yang bisa disalin.', $template));

            return self::FAILURE;
        }

        // Hanya spasi di ujung yang dibuang. Huruf besar **tidak** dikecilkan diam-diam:
        // sebuah id yang "diperbaiki" di sini menghasilkan module yang namanya bukan nama yang
        // diminta orangnya, dan perbedaan itu baru ketahuan setelah folder, manifest, dan tabel
        // pemetaan sudah ditulis.
        $module = trim((string) $this->argument('module'));
        $publisher = trim((string) $this->option('penerbit'));
        $prefix = trim((string) $this->option('awalan'));
        $name = trim((string) $this->option('nama'));

        $prefix = $prefix === '' ? str_replace('-', '_', $module).'_' : $prefix;
        $name = $name === '' ? self::title($module) : $name;

        $existingModules = $this->existingModules($root);
        $destination = $root.'/modules/'.$publisher.'/'.$module;

        $objections = $this->objections($module, $publisher, $name, $prefix, $destination, $existingModules);

        if ($objections !== []) {
            $this->error('Module tidak dibuat. Yang harus dibereskan lebih dulu:');

            foreach ($objections as $line) {
                $this->line('  - '.$line);
            }

            return self::FAILURE;
        }

        $marker = [
            // Diurutkan dari yang paling panjang supaya mudah dibaca; `strtr` sendiri sudah
            // mencocokkan penanda terpanjang lebih dulu, apa pun urutan penulisannya.
            'PenerbitContoh' => self::studly($publisher),
            'penerbit-contoh' => $publisher,
            'change_me_' => $prefix,
            'ChangeMe' => self::studly($module),
            'Change Me' => $name,
            'change-me' => $module,
        ];

        $written = $this->copyTemplate($template, $destination, $marker);

        $this->info(sprintf('Module %s/%s dibuat dari cetakan:', $publisher, $module));

        foreach ($written as $file) {
            $this->line('  modules/'.$publisher.'/'.$module.'/'.$file);
        }

        $this->registerPrefixInReadme($root, $publisher, $module, $prefix);
        $package = $this->registerInComposer($publisher, $module);

        $this->newLine();
        $this->line('Satu langkah tersisa, dan ia tidak bisa dilewati: kelas module belum bisa dimuat');
        $this->line('sampai Composer memasang package-nya dari repository path di composer.json.');
        $this->newLine();
        $this->line('  cd apps/core');
        $this->line('  composer update '.$package);
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
     * @param  array<string, string>  $existingModules
     * @return list<string>
     */
    private function objections(
        string $module,
        string $publisher,
        string $name,
        string $prefix,
        string $destination,
        array $existingModules,
    ): array {
        $objections = [];
        $pattern = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

        if (preg_match($pattern, $module) !== 1 || strlen($module) < 3 || strlen($module) > 40) {
            $objections[] = sprintf(
                'Id module "%s" tidak sah. Yang berlaku: 3 sampai 40 huruf kecil, angka, dan tanda hubung '.
                'di antaranya, misalnya "kelola-contoh". Id inilah yang menjadi nama folder, awalan tiap '.
                'kode izin, dan jalur rutenya.',
                $module,
            );
        }

        if (preg_match($pattern, $publisher) !== 1 || strlen($publisher) < 3 || strlen($publisher) > 40) {
            $objections[] = sprintf('Id penerbit "%s" tidak sah; aturannya sama dengan id module.', $publisher);
        }

        foreach (['module' => $module, 'penerbit' => $publisher] as $role => $value) {
            if (in_array($value, self::FORBIDDEN_WORDS, true)) {
                $objections[] = sprintf(
                    'Id %s "%s" adalah kata yang tidak boleh menjadi penggal namespace PHP; namespace '.
                    'module tidak akan bisa diurai sama sekali.',
                    $role,
                    $value,
                );
            }
        }

        if ($module === 'change-me' || $publisher === 'penerbit-contoh') {
            $objections[] = 'Penanda cetakan tidak boleh dipakai sebagai nama sungguhan; registry menolak module ber-id "change-me".';
        }

        if (preg_match('/^[a-z][a-z0-9_]*_$/', $prefix) !== 1 || strlen($prefix) > self::MAX_PREFIX_LENGTH) {
            $objections[] = sprintf(
                'Awalan tabel "%s" tidak sah. Yang berlaku: huruf kecil, angka, dan garis bawah, diawali '.
                'huruf, diakhiri garis bawah, paling panjang %d karakter — misalnya "contoh_".',
                $prefix,
                self::MAX_PREFIX_LENGTH,
            );
        }

        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\-]*$/u', $name) !== 1 || mb_strlen($name) > 60) {
            $objections[] = sprintf(
                'Nama tampilan "%s" tidak sah. Ia ditulis apa adanya ke app.yaml, jadi yang diterima hanya '.
                'huruf, angka, spasi, titik, dan tanda hubung, paling panjang 60 karakter.',
                $name,
            );
        }

        if (is_dir($destination)) {
            $objections[] = sprintf(
                '%s sudah ada. Perintah ini tidak pernah menimpa: kehilangan satu percobaan lebih murah '.
                'daripada kehilangan pekerjaan orang lain.',
                self::shortPath($destination),
            );
        }

        foreach ($existingModules as $path => $usedPrefixes) {
            [, $folderName] = explode('/', $path, 2);

            if ($folderName === $module) {
                $objections[] = sprintf('Id module "%s" sudah dipakai modules/%s; id module unik di seluruh runtime.', $module, $path);
            }

            // Bukan hanya kesamaan persis. Kepemilikan tabel diperiksa dengan awalan, jadi
            // "aset_" dan "aset_lama_" saling menelan: tabel milik yang satu terbaca sebagai
            // milik yang lain, dan penjaga batas tabel akan menyalahkan module yang keliru.
            if ($usedPrefixes !== '' && (str_starts_with($usedPrefixes, $prefix) || str_starts_with($prefix, $usedPrefixes))) {
                $objections[] = sprintf(
                    'Awalan tabel "%s" bertabrakan dengan "%s" milik modules/%s. Awalan yang saling menelan '.
                    'membuat tabel satu module terbaca sebagai milik module lain.',
                    $prefix,
                    $usedPrefixes,
                    $path,
                );
            }
        }

        return $objections;
    }

    /**
     * Menyalin cetakan ke folder module baru, mengganti tiap penanda di jalur maupun isinya.
     *
     * @param  array<string, string>  $marker
     * @return list<string> jalur relatif berkas yang ditulis, terurut
     */
    private function copyTemplate(string $template, string $destination, array $marker): array
    {
        $written = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($template, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($template) + 1));

            if (in_array($relative, self::UNCOPIED_FILES, true)) {
                continue;
            }

            $relativeDestination = $this->destinationPath(strtr($relative, $marker));
            $path = $destination.'/'.$relativeDestination;

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o755, true);
            }

            file_put_contents($path, strtr((string) file_get_contents($file->getPathname()), $marker));
            $written[] = $relativeDestination;
        }

        sort($written);

        return $written;
    }

    /**
     * Jalur relatif sebuah berkas di module baru.
     *
     * Cap waktu pada nama migration diganti waktu sekarang. Membiarkan cap waktu cetakan
     * berarti setiap module yang pernah dibuat darinya membawa cap waktu yang sama, dan urutan
     * migration di antara mereka ditentukan urutan abjad nama berkas — bukan urutan yang
     * dimaksudkan siapa pun.
     */
    private function destinationPath(string $relative): string
    {
        if (! str_starts_with($relative, 'database/migrations/')) {
            return $relative;
        }

        $name = basename($relative);
        $newName = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', now()->format('Y_m_d_His').'_', $name, 1);

        return dirname($relative).'/'.($newName ?? $name);
    }

    /**
     * Module yang sudah ada, dipetakan "<penerbit>/<module>" ke awalan tabelnya.
     *
     * @return array<string, string>
     */
    private function existingModules(string $root): array
    {
        $result = [];

        foreach (glob($root.'/modules/*/*/app.yaml') ?: [] as $manifest) {
            $folder = dirname($manifest);
            $path = basename(dirname($folder)).'/'.basename($folder);

            $content = Yaml::parseFile($manifest);
            $prefix = is_array($content) && isset($content['table_prefix']) && is_string($content['table_prefix'])
                ? $content['table_prefix']
                : '';

            $result[$path] = $prefix;
        }

        ksort($result);

        return $result;
    }

    /**
     * Menambahkan baris module baru ke tabel pemetaan pada `modules/README.md`.
     *
     * Tabel itu bukan dokumentasi yang boleh tertinggal: `SusunanManifestModulTest` menuntutnya
     * sama persis dengan manifest yang ada, jadi module baru tanpa barisnya membuat penjaga
     * batas merah. Ia ada supaya tabrakan awalan ketahuan saat peninjauan, bukan saat migrasi
     * jalan.
     */
    private function registerPrefixInReadme(string $root, string $publisher, string $module, string $prefix): void
    {
        $file = $root.'/modules/README.md';

        if (! is_file($file)) {
            $this->warn(sprintf('%s tidak ada; baris awalan tabel harus ditambahkan sendiri.', self::shortPath($file)));

            return;
        }

        $content = (string) file_get_contents($file);
        $line = sprintf(
            '| `%s/%s` | `Modules\%s\%s\` | `%s` |',
            $publisher,
            $module,
            self::studly($publisher),
            self::studly($module),
            $prefix,
        );

        $lines = preg_split('/\R/', $content);

        if ($lines === false) {
            $this->warn('Isi modules/README.md tidak terbaca; baris awalan tabel harus ditambahkan sendiri.');

            return;
        }

        $index = $this->tableRowIndex($lines);

        if ($index === []) {
            $this->warn('Tabel pemetaan pada modules/README.md tidak ditemukan; barisnya harus ditambahkan sendiri.');

            return;
        }

        $key = $publisher.'/'.$module;
        $insert = $index[count($index) - 1] + 1;

        foreach ($index as $number) {
            if (preg_match('/^\|\s*`([^`]+)`/', $lines[$number], $matches) === 1 && strcmp($matches[1], $key) > 0) {
                $insert = $number;

                break;
            }
        }

        array_splice($lines, $insert, 0, [$line]);
        file_put_contents($file, implode(self::lineEnding($content), $lines));

        $this->line(sprintf('  modules/README.md — baris awalan tabel "%s" ditambahkan', $prefix));
    }

    /**
     * Nomor baris isi tabel pemetaan awalan pada `modules/README.md`.
     *
     * Pemindaian dibatasi pada bagian "Namespace dan awalan tabel" — sama seperti penjaganya —
     * karena README memuat tabel lain, dan menyisipkan baris ke tabel yang salah membuat
     * dokumennya salah sekaligus penjaganya tetap merah.
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    private function tableRowIndex(array $lines): array
    {
        $inside = false;
        $index = [];

        foreach ($lines as $number => $line) {
            if (str_starts_with($line, '## ')) {
                $inside = str_starts_with($line, '## Namespace dan awalan tabel');

                continue;
            }

            // Hanya baris isi yang dihitung: judul dan garis pemisahnya tidak diapit backtick.
            if ($inside && preg_match('/^\|\s*`[^`]+`\s*\|\s*`[^`]+`\s*\|\s*`[^`]+`\s*\|/', $line) === 1) {
                $index[] = $number;
            }
        }

        return $index;
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
    private function registerInComposer(string $publisher, string $module): string
    {
        $package = $publisher.'/'.$module;
        $file = base_path('composer.json');
        $content = (string) file_get_contents($file);
        $lines = preg_split('/\R/', $content);

        if ($lines === false) {
            $this->warn('Isi composer.json tidak terbaca; package module harus didaftarkan sendiri.');

            return $package;
        }

        $start = null;

        foreach ($lines as $number => $line) {
            if (trim($line) === '"require": {') {
                $start = $number;

                break;
            }
        }

        if ($start === null) {
            $this->warn('Blok "require" pada composer.json tidak ditemukan; package module harus didaftarkan sendiri.');

            return $package;
        }

        $insert = null;
        $last = null;

        for ($number = $start + 1; $number < count($lines); $number++) {
            if (trim($lines[$number]) === '},') {
                break;
            }

            if (preg_match('/^\s*"([^"]+)":/', $lines[$number], $matches) !== 1) {
                continue;
            }

            $last = $number;

            // `php` selalu di puncak daftar, sesuai `sort-packages` milik Composer sendiri.
            if ($matches[1] === 'php') {
                continue;
            }

            if ($insert === null && strcmp($matches[1], $package) > 0) {
                $insert = $number;
            }
        }

        if ($last === null) {
            $this->warn('Daftar "require" pada composer.json kosong; package module harus didaftarkan sendiri.');

            return $package;
        }

        if ($insert === null) {
            // Masuk paling bawah: baris yang tadinya terakhir kini butuh koma di ujungnya.
            $lines[$last] = rtrim($lines[$last]).',';
            $insert = $last + 1;
            $line = '        "'.$package.'": "@dev"';
        } else {
            $line = '        "'.$package.'": "@dev",';
        }

        array_splice($lines, $insert, 0, [$line]);
        file_put_contents($file, implode(self::lineEnding($content), $lines));

        $this->line(sprintf('  apps/core/composer.json — package "%s" ditambahkan ke require', $package));

        return $package;
    }

    /**
     * Akhir baris yang dipakai sebuah berkas.
     *
     * Dibaca dari berkasnya, bukan ditetapkan, supaya menyisipkan satu baris tidak mengubah
     * seluruh berkas menjadi diff yang tidak bisa ditinjau di mesin yang mengecek keluar
     * dengan CRLF.
     */
    private static function lineEnding(string $content): string
    {
        return str_contains($content, "\r\n") ? "\r\n" : "\n";
    }

    private static function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }

    private static function title(string $name): string
    {
        return ucwords(str_replace('-', ' ', $name));
    }

    private static function shortPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $cut = strpos($path, '/modules/');

        return $cut === false ? $path : substr($path, $cut + 1);
    }
}
