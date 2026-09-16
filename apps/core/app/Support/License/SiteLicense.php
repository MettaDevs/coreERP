<?php

declare(strict_types=1);

namespace App\Support\License;

use App\Models\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Membaca lisensi situs on-prem, dan memutuskan dari isinya apakah pemasangan ini terkunci dan app
 * mana yang boleh dibuka.
 *
 * ## Kunci, dan kenapa ia berubah dari tanda
 *
 * Versi pertama kelas ini hanya memberi peringatan. Alasannya waktu itu masuk akal: pelanggan kita
 * fasilitas kesehatan, dan aplikasi yang berhenti berarti pelayanan berhenti. Keputusan itu dibalik
 * pemilik produk pada 15 September 2026, bersamaan dengan keputusan **satu image untuk semua klien**
 * (`docs/todo/lisensi-mengunci`). Begitu image membawa kode seluruh app, satu-satunya yang membedakan
 * klien yang membeli satu app dari klien yang membeli semuanya adalah lisensinya — dan tanda yang
 * dapat diabaikan tidak membedakan apa pun.
 *
 * Harga pelayanan yang berhenti tetap diakui, dan dibayar dengan tiga cara di tempat lain: lisensi
 * diperpanjang otomatis lewat jawaban laporan agen jauh sebelum habis, peringatannya tampil tujuh
 * hari sebelumnya, dan kuncinya hanya menyala di server yang menyetel `COREERP_LICENSE_REQUIRED`.
 *
 * ## Yang tetap terbuka saat terkunci
 *
 * Kelas ini hanya menjawab; yang menegakkan jawabannya `EnforceSiteLicense` dan empat pintu app.
 * Batasnya ditulis di sini supaya pembaca kelas ini tahu apa yang tidak dikunci:
 *
 * - **Data tidak disentuh.** Tidak ada yang dihapus, disembunyikan di database, atau diubah.
 * - **Akun provider tetap masuk** ke halaman Core, supaya vendor dapat memperbaiki pemasangan.
 * - **Login, logout, dan `/up`** tetap bekerja. Orang harus selalu dapat keluar, dan pemantau harus
 *   dapat membedakan "terkunci" dari "mati".
 * - **Pemasangan yang tidak mewajibkan lisensi tidak pernah terkunci**, apa pun isi berkasnya.
 *
 * ## Tidak pernah melempar
 *
 * Aturan ini bertahan dari versi pertama, dengan alasan yang lebih kuat sekarang. Kelas ini dipanggil
 * dari shared props Inertia, dari middleware di depan setiap halaman, dan dari penjaga API antar-app.
 * Exception yang lolos dari sini bukan kunci yang rapi melainkan 500 di seluruh aplikasi — termasuk di
 * halaman login dan bagi akun provider yang justru harus tetap masuk. Karena itu setiap jalan yang
 * gagal berakhir sebagai **keadaan**, dan keadaan yang gagal diperlakukan sebagai terkunci.
 *
 * ## Bentuk berkasnya
 *
 * `license.json` berisi JSON apa adanya; `license.json.sig` di sebelahnya berisi base64 satu baris
 * dari tanda tangan RSA PKCS#1 v1.5 SHA-256 atas **byte persis** `license.json`. Tanda tangan
 * diperiksa lebih dulu dan JSON-nya baru diurai sesudahnya: isi yang belum terbukti asalnya tidak
 * layak dibaca, dan menguraikan byte yang ditandatangani — bukan hasil susun ulangnya — adalah
 * satu-satunya cara penerbit dan pembaca sepakat tentang apa yang sebenarnya ditandatangani.
 *
 * ## Sekali per permintaan
 *
 * Diikat `scoped()` di `AppServiceProvider`, dan hasilnya diingat di instans. Berkasnya dibaca paling
 * banyak sekali per permintaan walaupun empat pintu menanyakannya, dan peringatan log untuk berkas yang
 * hilang atau rusak juga tercatat sekali per permintaan. Scoped, bukan singleton: berkas baru yang
 * dipasang agen harus terbaca pada permintaan berikutnya tanpa menunggu pekerja PHP diganti — itulah
 * yang membuat perpanjangan membuka kunci tanpa ada yang menyentuh server.
 */
final class SiteLicense
{
    /**
     * Satu-satunya versi format yang dikenal. Versi 1 — tanpa daftar app — ditolak, bukan ditebak:
     * lisensi tanpa daftar app tidak dapat menjawab app mana yang dibeli, dan belum ada satu klien pun
     * yang memakainya.
     */
    private const FORMAT_VERSION = 2;

    /** Bentuk id app, sama dengan id module di katalog. `\z`, bukan `$`: `$` menerima baris baru di ujungnya. */
    private const APP_ID_PATTERN = '/^[a-z0-9][a-z0-9-]*\z/';

    private ?SiteLicenseState $state = null;

    public function state(): SiteLicenseState
    {
        return $this->state ??= $this->evaluate();
    }

    /**
     * Apakah lisensi diwajibkan pemasangan ini.
     *
     * Dibaca dari config setiap kali, tanpa menyentuh berkas. Dua penanya yang paling sering —
     * middleware kunci dan saringan app — bertanya ini lebih dulu, sehingga SaaS dan beli-putus tidak
     * pernah membaca disk demi lisensi yang memang tidak mereka punya.
     */
    public function required(): bool
    {
        return filter_var(config('coreerp.license.required', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function isLocked(): bool
    {
        return $this->required() && $this->state()->isLocked();
    }

    public function allowsApp(string $appId): bool
    {
        return ! $this->required() || $this->state()->allowsApp($appId);
    }

    private function evaluate(): SiteLicenseState
    {
        $required = $this->required();
        $licensePath = $this->setting('path');

        if ($licensePath === '') {
            // Tidak wajib dan tidak disetel: pemasangan ini memang tidak punya lisensi. Tidak dibaca,
            // tidak dicatat.
            if (! $required) {
                return new SiteLicenseState(SiteLicenseState::NOT_REQUIRED);
            }

            // Wajib tetapi tanpa jalur adalah salah pasang, dan ia mengunci — kalau tidak, mengosongkan
            // satu baris `.env` menjadi jalan pintas yang lebih murah daripada menghapus berkasnya.
            return $this->reject(SiteLicenseState::MISSING, 'Lisensi situs wajib, tetapi COREERP_LICENSE_PATH tidak disetel.', [], $required);
        }

        try {
            return $this->read($licensePath, $required);
        } catch (Throwable $exception) {
            // Jaring terakhir. Yang dicatat nama kelasnya saja; pesan dari lapisan OpenSSL atau sistem
            // berkas tidak menambah apa pun yang dapat ditindaklanjuti pembaca log.
            return $this->reject(SiteLicenseState::INVALID, 'Lisensi situs tidak dapat diperiksa karena kesalahan tak terduga.', [
                'path' => $licensePath,
                'exception' => $exception::class,
            ], $required);
        }
    }

    private function read(string $licensePath, bool $required): SiteLicenseState
    {
        $files = [
            'berkas lisensi' => $licensePath,
            'tanda tangan lisensi' => $licensePath.'.sig',
            'kunci publik lisensi' => $this->setting('public_key_path'),
        ];

        $contents = [];

        foreach ($files as $label => $path) {
            $content = $path !== '' && is_file($path) && is_readable($path) ? file_get_contents($path) : false;

            if ($content === false) {
                return $this->reject(SiteLicenseState::MISSING, sprintf('Lisensi situs disetel, tetapi %s tidak ditemukan atau tidak dapat dibaca.', $label), [
                    'path' => $path === '' ? null : $path,
                ], $required);
            }

            $contents[$label] = $content;
        }

        [$licenseBytes, $encodedSignature, $publicKeyPem] = array_values($contents);

        // `trim`, bukan pembacaan persis: penulis berkas satu baris hampir selalu mengakhirinya dengan
        // baris baru, dan menolaknya karena itu berarti server pelanggan terkunci karena satu byte yang
        // tidak ditandatangani siapa pun. Mode ketat tetap menolak apa pun selain base64 — termasuk
        // base64 yang dipecah beberapa baris.
        $signature = base64_decode(trim($encodedSignature), true);

        if ($signature === false || $signature === '') {
            return $this->reject(SiteLicenseState::INVALID, 'Tanda tangan lisensi situs bukan base64 yang sah.', ['path' => $licensePath], $required);
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            return $this->reject(SiteLicenseState::INVALID, 'Kunci publik lisensi situs tidak terbaca sebagai kunci PEM.', [
                'path' => $files['kunci publik lisensi'],
            ], $required);
        }

        // `=== 1`, bukan sekadar benar: `openssl_verify` memulangkan -1 untuk kesalahan, dan -1 itu
        // truthy. Pemeriksaan longgar di sini membuat setiap kesalahan OpenSSL terbaca "sah".
        if (openssl_verify($licenseBytes, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->reject(SiteLicenseState::INVALID, 'Tanda tangan lisensi situs tidak cocok dengan isinya atau dengan kunci rilis.', [
                'path' => $licensePath,
            ], $required);
        }

        // Diurai sebagai objek, bukan array asosiatif. Dengan array asosiatif `{}` dan `[]` terbaca sama,
        // sehingga `"apps": {}` akan lolos sebagai daftar kosong — bentuk yang tidak pernah diterbitkan
        // penerbit yang benar.
        $license = json_decode($licenseBytes, false);

        if (! $license instanceof stdClass) {
            return $this->reject(SiteLicenseState::INVALID, 'Lisensi situs bertanda tangan sah, tetapi isinya bukan JSON objek.', ['path' => $licensePath], $required);
        }

        if (($license->version ?? null) !== self::FORMAT_VERSION) {
            return $this->reject(SiteLicenseState::INVALID, 'Versi format lisensi situs tidak dikenal.', ['path' => $licensePath], $required);
        }

        $apps = $this->appsFrom($license->apps ?? null);

        if ($apps === null) {
            return $this->reject(SiteLicenseState::INVALID, 'Daftar app lisensi situs bukan daftar id app yang unik.', ['path' => $licensePath], $required);
        }

        // Lisensi harus milik tenant yang memang hidup di server ini. Semua lisensi ditandatangani kunci
        // vendor yang sama, jadi tanpa pemeriksaan ini `license.json` beserta tanda tangannya dari klien
        // lain — yang membeli lebih banyak app — dapat disalin ke sini dan diterima utuh. Core tidak
        // mengenal id situsnya sendiri, tetapi mengenal tenantnya: server klien dilahirkan dengan id
        // tenant yang sama dengan admin.erp.
        $tenantId = $license->tenant_id ?? null;

        if (! is_string($tenantId) || $tenantId === '' || ! Tenant::query()->whereKey($tenantId)->exists()) {
            return $this->reject(SiteLicenseState::INVALID, 'Lisensi situs diterbitkan untuk tenant yang tidak ada di server ini.', ['path' => $licensePath], $required);
        }

        // Kunci yang tidak ada dan kunci yang bernilai null adalah dua hal berbeda di sini. Lisensi yang
        // tidak menyebut `valid_until` sama sekali ditolak: berkas yang terpotong di tengah penulisan
        // tidak boleh terbaca sebagai lisensi tanpa masa berlaku.
        if (! property_exists($license, 'valid_until')) {
            return $this->reject(SiteLicenseState::INVALID, 'Lisensi situs tidak menyebut tanggal berakhir.', ['path' => $licensePath], $required);
        }

        $validUntil = $license->valid_until;

        // `null` berarti lisensi tanpa tanggal berakhir, dipilih operator per situs di admin.erp. Ia
        // tidak pernah habis dan tidak pernah diperpanjang; yang membatasi tetap daftar `apps`.
        if ($validUntil === null) {
            return new SiteLicenseState(SiteLicenseState::VALID, null, $apps, $required, null);
        }

        if (! is_string($validUntil) || ! $this->isCalendarDate($validUntil)) {
            return $this->reject(SiteLicenseState::INVALID, 'Tanggal berakhir lisensi situs bukan tanggal berbentuk YYYY-MM-DD.', ['path' => $licensePath], $required);
        }

        $daysLeft = $this->daysUntil($validUntil);

        return new SiteLicenseState($this->statusFor($daysLeft), $validUntil, $apps, $required, $daysLeft);
    }

    /**
     * Daftar app dari lisensi, atau null bila bentuknya tidak dapat dipercaya.
     *
     * Ditolak seluruhnya, bukan disaring: satu id yang cacat berarti penerbitnya keliru, dan membuang
     * id itu diam-diam akan menutup app yang dibeli klien tanpa satu pun catatan yang menyebut sebabnya.
     * Urutan tidak diperiksa — urutan tidak mengubah app mana yang dibeli.
     *
     * @return list<string>|null
     */
    private function appsFrom(mixed $value): ?array
    {
        // Hanya JSON array yang terurai menjadi array PHP di sini — objek sudah menjadi stdClass karena
        // penguraian di atas — jadi `is_array` sekaligus menolak `{}` dan `{"0": "..."}`.
        if (! is_array($value)) {
            return null;
        }

        $apps = [];

        foreach ($value as $appId) {
            // Id berulang juga ditolak: penerbit yang menulis satu app dua kali sedang menyusun daftar
            // dari sesuatu yang salah, dan daftar itu tidak layak dipercaya untuk app yang lain pula.
            if (! is_string($appId) || preg_match(self::APP_ID_PATTERN, $appId) !== 1 || in_array($appId, $apps, true)) {
                return null;
            }

            $apps[] = $appId;
        }

        return $apps;
    }

    /**
     * Keadaan dari sisa hari, dihitung sebagai tanggal kalender.
     *
     * Hari terakhir masih berlaku: lisensi "sampai 14 September" belum habis pada 14 September, jadi
     * sisa nol hari masih `expiring`, bukan `expired`.
     *
     * @return SiteLicenseState::VALID|SiteLicenseState::EXPIRING|SiteLicenseState::EXPIRED
     */
    private function statusFor(int $daysLeft): string
    {
        if ($daysLeft < 0) {
            return SiteLicenseState::EXPIRED;
        }

        $warnDays = max(0, (int) config('coreerp.license.warn_days', 7));

        return $daysLeft <= $warnDays ? SiteLicenseState::EXPIRING : SiteLicenseState::VALID;
    }

    /**
     * Selisih hari kalender dari hari ini sampai tanggal berakhir, negatif bila sudah lewat.
     *
     * Dihitung sekali di server dan dikirim ke peramban, bukan dihitung ulang di sana. Server dan
     * peramban dapat berada di zona waktu berbeda; spanduk yang menyebut "tiga hari lagi" padahal kunci
     * di server menghitung dua adalah janji yang dilanggar kode kita sendiri.
     */
    private function daysUntil(string $validUntil): int
    {
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable(now()->toDateString(), $utc);
        $end = new DateTimeImmutable($validUntil, $utc);
        $difference = $today->diff($end);

        return $difference->invert === 1 ? -(int) $difference->days : (int) $difference->days;
    }

    /** `2027-02-30` ditolak: format yang cocok belum tentu tanggal yang ada. */
    private function isCalendarDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Mencatat sebabnya, lalu memulangkan keadaannya.
     *
     * `warning`, bukan `error`, dan itu tetap benar walaupun keadaan ini sekarang mengunci: yang
     * menindaklanjutinya vendor lewat laporan agen, bukan orang yang dibangunkan tengah malam oleh log.
     * Pencatatannya sendiri dibungkus — log yang tidak dapat ditulis tidak boleh mengubah pemeriksaan
     * lisensi menjadi halaman yang gagal.
     *
     * @param  SiteLicenseState::MISSING|SiteLicenseState::INVALID  $status
     * @param  array<string, mixed>  $context
     */
    private function reject(string $status, string $message, array $context, bool $required): SiteLicenseState
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // Sengaja diam; lihat docblock.
        }

        return new SiteLicenseState($status, required: $required);
    }

    private function setting(string $key): string
    {
        $value = config('coreerp.license.'.$key);

        return is_string($value) ? trim($value) : '';
    }
}
