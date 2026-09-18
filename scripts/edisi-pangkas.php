#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Membuang module yang tidak dibeli sebuah edisi dari pohon bangunan image.
 *
 * Dipanggil dari `apps/core/Dockerfile`, bukan dari mesin pengembang. Ia mengubah
 * berkas di tempat; menjalankannya di repo sungguhan akan memangkas repo itu sendiri.
 *
 * ## Kenapa dua tahap
 *
 * Dockerfile menyalin konteks dua kali: sekali hanya `composer.json`, `composer.lock`, dan
 * `modules/` supaya lapisan `composer install` tidak terbangun ulang setiap ada satu baris PHP
 * berubah, lalu sekali lagi seluruh repo lewat `COPY . .`. Salinan kedua menaruh kembali berkas
 * utuh dari konteks, jadi pemangkasan harus dikerjakan dua kali.
 *
 * - `pasang` dijalankan **sebelum** `composer install`. Ia memangkas `composer.json` dan
 *   `composer.lock` lebih dulu, baru membuang folder module. Urutan ini tidak bisa dibalik:
 *   `composer.json` me-require setiap module sebagai paket path, jadi membuang foldernya lebih
 *   dulu membuat pemasangan gagal karena paket yang di-require tidak ada, sedangkan membuangnya
 *   sesudah pemasangan meninggalkan nama dan namespace module itu di
 *   `vendor/composer/installed.json` dan `vendor/composer/autoload_psr4.php`. Yang kedua adalah
 *   kebocoran: klaim produknya berbunyi "modul yang tidak dibeli tidak ada di server pelanggan",
 *   bukan "kodenya tidak ada".
 *
 * - `ulangi` dijalankan **sesudah** `COPY . .`. Ia memasang kembali hasil pangkasan tahap
 *   pertama yang disimpan di /edisi, lalu membuang lagi folder module dan keluaran Wayfinder
 *   yang basi. Tidak ada penyelesaian dependency kedua kali — yang berarti tidak ada peluang
 *   kedua bagi jaringan untuk membuat bangunan gagal, dan tidak ada peluang hasilnya berbeda
 *   dari tahap pertama.
 *
 * ## Kenapa `composer remove`, bukan menyunting lock dengan tangan
 *
 * `composer.lock` menyimpan `content-hash` dari `composer.json`; mengubah salah satunya tanpa
 * yang lain membuat `composer install` menolak jalan. `composer remove <paket> --no-install`
 * adalah perintah resmi yang mengubah keduanya sekaligus: ia menyunting `require`, menjalankan
 * update terbatas pada paket yang dibuang saja, lalu menulis ulang lock beserta hash-nya.
 * `composer update --lock` tidak bisa dipakai — ia menyematkan setiap paket yang sudah ada di
 * lock, jadi paket yang baru saja dibuang dari `require` justru ditolak karena kehilangan
 * penanda stabilitasnya.
 *
 * Keduanya aman dijalankan dua kali: tahap yang tidak menemukan lagi yang harus dibuang berhenti
 * tanpa mengubah apa pun.
 */

/** Nilai `--modul` yang berarti "seluruh module di repo", yaitu bangunan bawaan tanpa edisi. */
const SEMUA = 'semua';


/** Berkas penanda di dalam `modules/`, sekaligus penjaga agar folder itu tidak pernah kosong. */
const PENANDA = 'modules/.edisi';

exit(utama($argv));

/**
 * @param  list<string>  $argv
 */
function utama(array $argv): int
{
    $akar = dirname(__DIR__);

    array_shift($argv);
    $tahap = array_shift($argv) ?? '';
    $modul = null;

    foreach ($argv as $argumen) {
        if (str_starts_with($argumen, '--modul=')) {
            $modul = substr($argumen, strlen('--modul='));

            continue;
        }

        return gagal(sprintf('Argumen tidak dikenal: %s', $argumen));
    }

    if (! in_array($tahap, ['pasang', 'ulangi'], true) || $modul === null) {
        return gagal(
            'Pemakaian: edisi-pangkas.php <pasang|ulangi> --modul="<daftar id dipisah spasi>"'
            ."\n".'Nilai "'.SEMUA.'" berarti seluruh module ikut; daftar kosong berarti Core saja.'
        );
    }

    if (trim($modul) === SEMUA) {
        pesan('Bangunan bawaan: seluruh module ikut, tidak ada yang dipangkas.');

        return 0;
    }

    $dibeli = daftarId($modul);

    try {
        return $tahap === 'pasang'
            ? pasang($akar, $dibeli)
            : ulangi($akar, $dibeli);
    } catch (RuntimeException $kesalahan) {
        return gagal($kesalahan->getMessage());
    }
}

/**
 * Tahap pertama: pangkas metadata Composer, lalu buang folder module.
 *
 * @param  list<string>  $dibeli
 */
function pasang(string $akar, array $dibeli): int
{
    $module = daftarModule($akar);
    periksaDibeliAda($module, $dibeli);

    $dibuang = array_values(array_filter(
        $module,
        static fn (array $m): bool => ! in_array($m['id'], $dibeli, true),
    ));

    if ($dibuang === []) {
        pesan('Tidak ada module yang perlu dibuang dari metadata Composer.');
    } else {
        pangkasComposer($akar, $dibuang);
    }

    simpanHasilPangkasan($akar);
    buangFolderModule($akar, $dibuang);
    tulisPenanda($akar, $dibeli);

    return 0;
}

/**
 * Tahap kedua: pasang kembali hasil tahap pertama sesudah `COPY . .` menimpanya.
 *
 * @param  list<string>  $dibeli
 */
function ulangi(string $akar, array $dibeli): int
{
    pulihkanHasilPangkasan($akar);

    $module = daftarModule($akar);
    periksaDibeliAda($module, $dibeli);

    $dibuang = array_values(array_filter(
        $module,
        static fn (array $m): bool => ! in_array($m['id'], $dibeli, true),
    ));

    buangFolderModule($akar, $dibuang);
    buangKeluaranWayfinder($akar);
    tulisPenanda($akar, $dibeli);

    return 0;
}

/**
 * Buang setiap module yang tidak dibeli dari `composer.json` dan `composer.lock`.
 *
 * @param  list<array{id: string, folder: string, relatif: string, paket: string}>  $dibuang
 */
function pangkasComposer(string $akar, array $dibuang): void
{
    $app = $akar.'/apps/core';
    $berkasJson = $app.'/composer.json';
    $berkasLock = $app.'/composer.lock';

    $hashSebelum = bacaJson($berkasLock)['content-hash'] ?? '';

    // Pemetaan PSR-4 yang menunjuk ke dalam folder module dibuang lebih dulu, dan ia disunting
    // langsung karena `composer config --unset` tidak menjangkau `autoload-dev`: perintah itu
    // berhenti tanpa suara dan tanpa mengubah apa pun. Yang tertinggal kalau dilewatkan bukan
    // kode melainkan nama namespace-nya — misalnya `Modules\Apperp\ManagementAset\Tests\` —
    // dan nama itu ikut ke dalam image lewat `composer.json`.
    //
    // Suntingan ini sengaja dikerjakan sebelum `composer remove`, karena perintah itulah yang
    // menghitung ulang `content-hash`; membalik urutannya meninggalkan hash yang tidak cocok
    // dengan isi berkasnya.
    $isi = bacaJson($berkasJson);
    $dibuangDari = [];

    foreach (['autoload', 'autoload-dev'] as $bagian) {
        if (! isset($isi[$bagian]['psr-4']) || ! is_array($isi[$bagian]['psr-4'])) {
            continue;
        }

        foreach ($isi[$bagian]['psr-4'] as $namespace => $jalur) {
            foreach ((array) $jalur as $satu) {
                if (menunjukModule((string) $satu, $dibuang)) {
                    unset($isi[$bagian]['psr-4'][$namespace]);
                    $dibuangDari[] = $bagian.'.psr-4.'.$namespace;

                    break;
                }
            }
        }

        if ($isi[$bagian]['psr-4'] === []) {
            unset($isi[$bagian]['psr-4']);
        }

        if ($isi[$bagian] === []) {
            unset($isi[$bagian]);
        }
    }

    if ($dibuangDari !== []) {
        tulisJson($berkasJson, $isi);
        pesan('Pemetaan PSR-4 dibuang: '.implode(', ', $dibuangDari));
    }

    $paket = array_column($dibuang, 'paket');
    sort($paket);

    // Kedua `--ignore-platform-req` menyamai baris `composer install` pada Dockerfile: image
    // composer tidak membawa `ext-gd` maupun `ext-opentelemetry`, sedangkan penyelesaian
    // dependency memeriksa keduanya.
    //
    // Yang kedua sempat tertinggal, dan akibatnya seluruh pembangunan edisi berhenti di sini:
    // `open-telemetry/opentelemetry-auto-laravel` menuntut `ext-opentelemetry`, jadi `composer
    // remove` menolak menyelesaikan dependency sebelum satu paket pun dibuang. Pesannya menuduh
    // paket module yang sedang dibuang, bukan ekstensi yang hilang — itu yang membuatnya mahal
    // didiagnosa. Kalau kelak ada ekstensi ketiga yang hanya ada di image runtime, ia harus
    // ditambahkan di kedua tempat sekaligus.
    jalankan(
        $app,
        array_merge(
            [composerBin(), 'remove'],
            $paket,
            [
                '--no-install',
                '--no-scripts',
                '--no-plugins',
                '--no-audit',
                '--no-interaction',
                '--ignore-platform-req=ext-gd',
                '--ignore-platform-req=ext-opentelemetry',
            ],
        ),
    );

    periksaHasilPangkasan($berkasJson, $berkasLock, $paket, (string) $hashSebelum);
}

/**
 * Buktikan pangkasannya benar-benar terjadi, bukan sekadar perintahnya keluar dengan kode 0.
 *
 * @param  list<string>  $paket
 */
function periksaHasilPangkasan(string $berkasJson, string $berkasLock, array $paket, string $hashSebelum): void
{
    $json = bacaJson($berkasJson);
    $lock = bacaJson($berkasLock);

    $tersisa = [];

    foreach ($paket as $nama) {
        foreach (['require', 'require-dev'] as $bagian) {
            if (isset($json[$bagian][$nama])) {
                $tersisa[] = sprintf('composer.json %s.%s', $bagian, $nama);
            }
        }

        foreach (['packages', 'packages-dev'] as $bagian) {
            foreach ($lock[$bagian] ?? [] as $terkunci) {
                if (($terkunci['name'] ?? null) === $nama) {
                    $tersisa[] = sprintf('composer.lock %s: %s', $bagian, $nama);
                }
            }
        }

        if (isset($lock['stability-flags'][$nama])) {
            $tersisa[] = sprintf('composer.lock stability-flags.%s', $nama);
        }
    }

    if ($tersisa !== []) {
        throw new RuntimeException(
            "Module masih tersisa di metadata Composer sesudah dipangkas:\n- ".implode("\n- ", $tersisa)
        );
    }

    if (($lock['content-hash'] ?? '') === $hashSebelum) {
        throw new RuntimeException(
            '`content-hash` pada composer.lock tidak berubah padahal composer.json berubah. '
            .'`composer install` akan menolak lock ini.'
        );
    }

    pesan(sprintf('Metadata Composer dipangkas: %s.', implode(', ', $paket)));
}

/** Simpan hasil pangkasan supaya `COPY . .` bisa ditimpa balik tanpa menghitung ulang. */
function simpanHasilPangkasan(string $akar): void
{
    buatFolder(simpanan());

    foreach (['composer.json', 'composer.lock'] as $berkas) {
        salin($akar.'/apps/core/'.$berkas, simpanan().'/'.$berkas);
    }
}

function pulihkanHasilPangkasan(string $akar): void
{
    foreach (['composer.json', 'composer.lock'] as $berkas) {
        $sumber = simpanan().'/'.$berkas;

        if (! is_file($sumber)) {
            throw new RuntimeException(sprintf(
                'Hasil pangkasan %s tidak ditemukan. Tahap `pasang` harus dijalankan lebih dulu.',
                $sumber,
            ));
        }

        salin($sumber, $akar.'/apps/core/'.$berkas);
    }

    pesan('composer.json dan composer.lock hasil pangkasan dipasang kembali.');
}

/**
 * Kosongkan `modules/` dari apa pun yang bukan module yang dibeli.
 *
 * Yang ikut dibuang bukan hanya folder module lain, melainkan juga berkas lain di dalamnya —
 * `modules/README.md` misalnya menyebut nama module contoh, dan sebuah image pelanggan tidak
 * punya keperluan membawa catatan pengembang.
 *
 * @param  list<array{id: string, folder: string, relatif: string, paket: string}>  $dibuang
 */
function buangFolderModule(string $akar, array $dibuang): void
{
    $folderModule = $akar.'/modules';

    if (! is_dir($folderModule)) {
        return;
    }

    $disimpan = [];

    foreach ($dibuang as $module) {
        hapus($module['folder']);
        pesan(sprintf('Folder module dibuang: %s', $module['relatif']));
    }

    foreach (daftarModule($akar) as $module) {
        $disimpan[] = realpath($module['folder']) ?: $module['folder'];
    }

    // Sisa isi `modules/` yang bukan folder module yang disimpan ikut dibuang, termasuk folder
    // penerbit yang menjadi kosong sesudah module di dalamnya dibuang.
    foreach (isiFolder($folderModule) as $penerbit) {
        if (is_file($penerbit)) {
            if (basename($penerbit) !== basename(PENANDA)) {
                hapus($penerbit);
            }

            continue;
        }

        foreach (isiFolder($penerbit) as $isi) {
            if (! in_array(realpath($isi) ?: $isi, $disimpan, true)) {
                hapus($isi);
            }
        }

        if (isiFolder($penerbit) === []) {
            hapus($penerbit);
        }
    }
}

/**
 * Buang keluaran Wayfinder yang ikut dari konteks.
 *
 * Ketiga folder ini diturunkan dari controller dan rute yang ada saat `wayfinder:generate`
 * berjalan, jadi isinya menyebut nama module — `resources/js/routes/contoh-a` dan
 * `resources/js/actions/Modules/Apperp/ContohA/` misalnya. Ketiganya ada di `.gitignore`, jadi
 * pada checkout CI yang bersih folder-folder ini memang tidak ada dan baris ini tidak melakukan
 * apa-apa. Ia ada untuk bangunan dari mesin pengembang, tempat sisa dari bangunan sebelumnya
 * bisa ikut terbawa ke dalam konteks dan lolos ke image sebagai jejak module yang tidak dibeli.
 *
 * `php artisan wayfinder:generate` di Dockerfile menuliskan ketiganya kembali sesudah ini.
 */
function buangKeluaranWayfinder(string $akar): void
{
    foreach (['actions', 'routes', 'wayfinder'] as $folder) {
        $jalur = $akar.'/apps/core/resources/js/'.$folder;

        if (is_dir($jalur)) {
            hapus($jalur);
            pesan(sprintf('Keluaran Wayfinder yang basi dibuang: resources/js/%s', $folder));
        }
    }
}

/**
 * Catat edisi yang dibangun di dalam image, sekaligus menjaga `modules/` tidak pernah kosong.
 *
 * Folder kosong menyulitkan `COPY --from=...` dan menyulitkan admin yang membuka image untuk
 * memastikan apa yang ia terima. Isinya hanya id module yang **dibeli**, jadi berkas ini sendiri
 * tidak bisa menjadi kebocoran.
 *
 * @param  list<string>  $dibeli
 */
function tulisPenanda(string $akar, array $dibeli): void
{
    buatFolder($akar.'/modules');

    $isi = "# Module yang ikut di dalam image ini. Dihitung `php artisan edition:modules`.\n";

    foreach ($dibeli as $id) {
        $isi .= $id."\n";
    }

    tulis($akar.'/'.PENANDA, $isi);
}

/**
 * Semua module yang ada di pohon bangunan, dibaca dari folder — bukan dari daftar tertulis.
 *
 * `app.yaml` dibaca dengan pola sederhana, bukan dengan `symfony/yaml`: tahap `pasang` berjalan
 * sebelum `composer install`, jadi belum ada satu pun paket yang terpasang. Yang dibutuhkan
 * hanya satu kunci di kolom paling kiri.
 *
 * @return list<array{id: string, folder: string, relatif: string, paket: string}>
 */
function daftarModule(string $akar): array
{
    $ditemukan = [];
    $berkas = glob($akar.'/modules/*/*/app.yaml');

    foreach ($berkas === false ? [] : $berkas as $manifest) {
        $folder = dirname($manifest);
        $id = bacaId($manifest);

        if ($id === null) {
            throw new RuntimeException(sprintf('`%s` tidak menyebut `id`.', $manifest));
        }

        $paketJson = $folder.'/composer.json';

        if (! is_file($paketJson)) {
            throw new RuntimeException(sprintf('Module `%s` tidak punya composer.json.', $id));
        }

        $nama = bacaJson($paketJson)['name'] ?? null;

        if (! is_string($nama) || $nama === '') {
            throw new RuntimeException(sprintf('composer.json module `%s` tidak menyebut `name`.', $id));
        }

        $ditemukan[] = [
            'id' => $id,
            'folder' => $folder,
            'relatif' => 'modules/'.basename(dirname($folder)).'/'.basename($folder),
            'paket' => $nama,
        ];
    }

    usort($ditemukan, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

    return $ditemukan;
}

function bacaId(string $manifest): ?string
{
    $isi = file_get_contents($manifest);

    if ($isi === false) {
        throw new RuntimeException(sprintf('`%s` tidak bisa dibaca.', $manifest));
    }

    if (preg_match('/^id:[ \t]*(?:"([^"]*)"|\'([^\']*)\'|([^\s#]+))/m', $isi, $cocok) !== 1) {
        return null;
    }

    $id = $cocok[3] !== '' ? $cocok[3] : ($cocok[2] !== '' ? $cocok[2] : $cocok[1]);

    return $id === '' ? null : $id;
}

/**
 * Module yang dibeli tapi tidak ada di repo adalah kesalahan, bukan hal yang disaring diam-diam.
 *
 * Menyaringnya tanpa suara menghasilkan image yang berhasil dibangun dan kekurangan module yang
 * dibayar pelanggan — kegagalan yang baru terlihat di server pelanggan. `edition:modules` sudah
 * menolaknya lebih dulu; pemeriksaan ini menangkap daftar yang sampai ke sini lewat jalan lain.
 *
 * @param  list<array{id: string, folder: string, relatif: string, paket: string}>  $module
 * @param  list<string>  $dibeli
 */
function periksaDibeliAda(array $module, array $dibeli): void
{
    $ada = array_column($module, 'id');
    $hilang = array_values(array_diff($dibeli, $ada));

    if ($hilang !== []) {
        throw new RuntimeException(sprintf(
            "Module berikut ada di daftar edisi tapi tidak ada di repo:\n- %s\nYang ada: %s",
            implode("\n- ", $hilang),
            $ada === [] ? '(tidak ada)' : implode(', ', $ada),
        ));
    }
}

/**
 * @param  list<array{id: string, folder: string, relatif: string, paket: string}>  $dibuang
 */
function menunjukModule(string $jalur, array $dibuang): bool
{
    // Garis miring ditambahkan di belakang supaya `modules/apperp/contoh-a` tidak ikut cocok
    // dengan `modules/apperp/contoh-a-lain`, dan supaya jalur yang berhenti tepat di folder
    // module — tanpa garis miring penutup — tetap tertangkap.
    $rapi = rtrim(str_replace('\\', '/', $jalur), '/').'/';

    foreach ($dibuang as $module) {
        if (str_contains($rapi, $module['relatif'].'/')) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function daftarId(string $modul): array
{
    $pecahan = preg_split('/\s+/', trim($modul));

    return array_values(array_unique(array_filter(
        $pecahan === false ? [] : $pecahan,
        static fn (string $id): bool => $id !== '',
    )));
}

/** @return array<string, mixed> */
function bacaJson(string $berkas): array
{
    $isi = file_get_contents($berkas);

    if ($isi === false) {
        throw new RuntimeException(sprintf('`%s` tidak bisa dibaca.', $berkas));
    }

    $terurai = json_decode($isi, true);

    if (! is_array($terurai)) {
        throw new RuntimeException(sprintf('`%s` bukan JSON yang sah.', $berkas));
    }

    return $terurai;
}

/**
 * Ditulis ulang dengan format bawaan Composer: empat spasi, garis miring tidak di-escape.
 *
 * Bentuk berkasnya boleh berubah — `content-hash` dihitung dari isi yang sudah diurai, bukan dari
 * teksnya — dan berkas ini hanya hidup di dalam image, tidak pernah kembali ke repo.
 *
 * @param  array<string, mixed>  $isi
 */
function tulisJson(string $berkas, array $isi): void
{
    $teks = json_encode($isi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($teks === false) {
        throw new RuntimeException(sprintf('`%s` tidak bisa ditulis ulang.', $berkas));
    }

    tulis($berkas, $teks."\n");
}

function tulis(string $berkas, string $isi): void
{
    if (file_put_contents($berkas, $isi) === false) {
        throw new RuntimeException(sprintf('`%s` tidak bisa ditulis.', $berkas));
    }
}

function salin(string $sumber, string $tujuan): void
{
    if (! copy($sumber, $tujuan)) {
        throw new RuntimeException(sprintf('`%s` tidak bisa disalin ke `%s`.', $sumber, $tujuan));
    }
}

function buatFolder(string $folder): void
{
    if (! is_dir($folder) && ! mkdir($folder, 0o755, true) && ! is_dir($folder)) {
        throw new RuntimeException(sprintf('Folder `%s` tidak bisa dibuat.', $folder));
    }
}

/**
 * Isi sebuah folder, termasuk berkas tersembunyi.
 *
 * `scandir`, bukan `glob`: pola glob melewatkan berkas berawalan titik, dan yang terlewat di
 * sini adalah berkas yang justru ikut ke dalam image tanpa terlihat.
 *
 * @return list<string>
 */
function isiFolder(string $folder): array
{
    $isi = scandir($folder);

    if ($isi === false) {
        return [];
    }

    return array_values(array_map(
        static fn (string $nama): string => $folder.'/'.$nama,
        array_filter($isi, static fn (string $nama): bool => $nama !== '.' && $nama !== '..'),
    ));
}

/** Hapus berkas atau folder beserta isinya. Diam saja bila memang sudah tidak ada. */
function hapus(string $jalur): void
{
    if (is_link($jalur) || is_file($jalur)) {
        unlink($jalur);

        return;
    }

    if (! is_dir($jalur)) {
        return;
    }

    foreach (isiFolder($jalur) as $isi) {
        hapus($isi);
    }

    rmdir($jalur);
}

/**
 * Jalankan perintah dan berhenti bila ia gagal.
 *
 * @param  list<string>  $perintah
 */
function jalankan(string $folder, array $perintah): void
{
    pesan('$ '.implode(' ', $perintah));

    // `proc_open` dengan perintah berbentuk larik, bukan `exec` dengan satu baris teks: tidak ada
    // shell yang ikut menafsirkan argumen, folder kerja diberikan langsung alih-alih lewat `cd`
    // yang bentuknya berbeda di tiap shell, dan fungsi ini pasti tersedia — Composer sendiri
    // menolak berjalan tanpanya, jadi ia ada di setiap tempat yang bisa menjalankan
    // `composer remove`.
    $proses = proc_open(
        $perintah,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipa,
        $folder,
    );

    if (! is_resource($proses)) {
        throw new RuntimeException(sprintf('Perintah `%s` tidak bisa dijalankan.', $perintah[0]));
    }

    foreach ($pipa as $satu) {
        $keluaran = stream_get_contents($satu);

        if (is_string($keluaran) && trim($keluaran) !== '') {
            fwrite(STDERR, '  '.str_replace("\n", "\n  ", rtrim($keluaran))."\n");
        }

        fclose($satu);
    }

    $kode = proc_close($proses);

    if ($kode !== 0) {
        throw new RuntimeException(sprintf('Perintah gagal dengan kode %d.', $kode));
    }
}

/**
 * Tempat hasil pangkasan tahap `pasang` disimpan sampai tahap `ulangi` memakainya.
 *
 * Di luar `/repo`, jadi ia tidak pernah ikut tersalin ke tahap berikutnya. Bisa dialihkan lewat
 * `EDISI_SIMPANAN` supaya skrip ini bisa diuji di folder sementara tanpa menulis ke akar sistem
 * berkas.
 */
function simpanan(): string
{
    $folder = getenv('EDISI_SIMPANAN');

    return is_string($folder) && $folder !== '' ? $folder : '/edisi';
}

function composerBin(): string
{
    $bin = getenv('COMPOSER_BIN');

    return is_string($bin) && $bin !== '' ? $bin : 'composer';
}

function pesan(string $teks): void
{
    fwrite(STDERR, '[edisi] '.$teks."\n");
}

function gagal(string $teks): int
{
    fwrite(STDERR, '[edisi] GAGAL: '.$teks."\n");

    return 1;
}
