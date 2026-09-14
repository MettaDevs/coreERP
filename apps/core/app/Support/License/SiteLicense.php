<?php

declare(strict_types=1);

namespace App\Support\License;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Membaca lisensi situs on-prem untuk satu tujuan saja: memberi peringatan.
 *
 * ## Tanda, bukan kunci
 *
 * Tidak ada apa pun di sini yang boleh menolak permintaan, menyembunyikan fitur, atau melempar
 * kesalahan ke pemanggilnya. Pelanggan on-prem kita fasilitas kesehatan; aplikasi yang berhenti
 * karena berkas lisensi terlambat dikirim berarti pelayanan pasien berhenti, dan akibatnya jatuh
 * pada kita. Pembayaran ditegakkan lewat kontrak — bayar di muka, pembaruan dan dukungan berhenti
 * ketika terlambat — bukan lewat kode ini.
 *
 * Karena itu setiap jalan yang gagal di sini berakhir sebagai **keadaan**, bukan exception. Kelas
 * ini dipanggil dari shared props Inertia, jadi exception yang lolos akan menjatuhkan setiap
 * halaman — persis kunci yang tidak boleh ada, hanya lewat pintu belakang.
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
 * banyak sekali per permintaan, dan peringatan log untuk berkas yang hilang atau rusak juga tercatat
 * sekali per permintaan — bukan sekali per pembaca. Scoped, bukan singleton: berkas baru yang
 * dipasang agen harus terbaca pada permintaan berikutnya tanpa menunggu pekerja PHP diganti.
 */
final class SiteLicense
{
    /** Satu-satunya versi format yang dikenal. Versi lain ditolak, bukan ditebak bentuknya. */
    private const FORMAT_VERSION = 1;

    private ?SiteLicenseState $state = null;

    public function state(): SiteLicenseState
    {
        return $this->state ??= $this->evaluate();
    }

    private function evaluate(): SiteLicenseState
    {
        $licensePath = $this->setting('path');

        // Tanpa jalur, pemasangan ini memang tidak punya lisensi: tidak dibaca, tidak dicatat.
        if ($licensePath === '') {
            return new SiteLicenseState(SiteLicenseState::NOT_REQUIRED);
        }

        try {
            return $this->read($licensePath);
        } catch (Throwable $exception) {
            // Jaring terakhir. Yang dicatat nama kelasnya saja; pesan dari lapisan OpenSSL atau sistem
            // berkas tidak menambah apa pun yang dapat ditindaklanjuti pembaca log.
            return $this->reject(SiteLicenseState::INVALID, 'Lisensi situs tidak dapat diperiksa karena kesalahan tak terduga.', [
                'path' => $licensePath,
                'exception' => $exception::class,
            ]);
        }
    }

    private function read(string $licensePath): SiteLicenseState
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
                ]);
            }

            $contents[$label] = $content;
        }

        [$licenseBytes, $encodedSignature, $publicKeyPem] = array_values($contents);

        // `trim`, bukan pembacaan persis: penulis berkas satu baris hampir selalu mengakhirinya dengan
        // baris baru, dan menolaknya karena itu berarti peringatan palsu di setiap server pelanggan.
        // Mode ketat tetap menolak apa pun selain base64 — termasuk base64 yang dipecah beberapa baris.
        $signature = base64_decode(trim($encodedSignature), true);

        if ($signature === false || $signature === '') {
            return $this->reject(SiteLicenseState::INVALID, 'Tanda tangan lisensi situs bukan base64 yang sah.', ['path' => $licensePath]);
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            return $this->reject(SiteLicenseState::INVALID, 'Kunci publik lisensi situs tidak terbaca sebagai kunci PEM.', [
                'path' => $files['kunci publik lisensi'],
            ]);
        }

        // `=== 1`, bukan sekadar benar: `openssl_verify` memulangkan -1 untuk kesalahan, dan -1 itu
        // truthy. Pemeriksaan longgar di sini membuat setiap kesalahan OpenSSL terbaca "sah".
        if (openssl_verify($licenseBytes, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->reject(SiteLicenseState::INVALID, 'Tanda tangan lisensi situs tidak cocok dengan isinya atau dengan kunci rilis.', [
                'path' => $licensePath,
            ]);
        }

        $license = json_decode($licenseBytes, true);

        if (! is_array($license)) {
            return $this->reject(SiteLicenseState::INVALID, 'Lisensi situs bertanda tangan sah, tetapi isinya bukan JSON objek.', ['path' => $licensePath]);
        }

        if (($license['version'] ?? null) !== self::FORMAT_VERSION) {
            return $this->reject(SiteLicenseState::INVALID, 'Versi format lisensi situs tidak dikenal.', ['path' => $licensePath]);
        }

        $validUntil = $license['valid_until'] ?? null;

        if (! is_string($validUntil) || ! $this->isCalendarDate($validUntil)) {
            return $this->reject(SiteLicenseState::INVALID, 'Tanggal berakhir lisensi situs bukan tanggal berbentuk YYYY-MM-DD.', ['path' => $licensePath]);
        }

        return new SiteLicenseState($this->statusFor($validUntil), $validUntil);
    }

    /**
     * Keadaan dari tanggal berakhir, dibandingkan sebagai tanggal kalender.
     *
     * Hari terakhir masih berlaku: lisensi "sampai 14 September" belum habis pada 14 September.
     * Kedua tanggal berbentuk `Y-m-d`, jadi perbandingan string sama dengan perbandingan tanggal.
     *
     * @return SiteLicenseState::VALID|SiteLicenseState::EXPIRING|SiteLicenseState::EXPIRED
     */
    private function statusFor(string $validUntil): string
    {
        $today = now()->toDateString();

        if ($validUntil < $today) {
            return SiteLicenseState::EXPIRED;
        }

        $warnDays = max(0, (int) config('coreerp.license.warn_days', 30));

        if ($validUntil <= now()->addDays($warnDays)->toDateString()) {
            return SiteLicenseState::EXPIRING;
        }

        return SiteLicenseState::VALID;
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
     * `warning`, bukan `error`: tidak ada yang rusak pada aplikasinya, dan tidak ada yang perlu
     * dibangunkan tengah malam. Pencatatannya sendiri dibungkus — log yang tidak dapat ditulis
     * tidak boleh mengubah pemeriksaan lisensi menjadi halaman yang gagal.
     *
     * @param  SiteLicenseState::MISSING|SiteLicenseState::INVALID  $status
     * @param  array<string, mixed>  $context
     */
    private function reject(string $status, string $message, array $context): SiteLicenseState
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // Sengaja diam; lihat docblock.
        }

        return new SiteLicenseState($status);
    }

    private function setting(string $key): string
    {
        $value = config('coreerp.license.'.$key);

        return is_string($value) ? trim($value) : '';
    }
}
