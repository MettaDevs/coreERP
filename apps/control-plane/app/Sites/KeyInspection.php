<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

/**
 * Keadaan berkas kunci yang disetel lewat `.env`, dalam bentuk yang dapat ditampilkan halaman
 * Pengaturan: sidik jari bila sah, kalimat penyebab bila tidak.
 *
 * ## Sidik jari
 *
 * SHA-256 atas bentuk DER kunci publik (SubjectPublicKeyInfo), heksadesimal huruf kecil — keluaran yang
 * sama dengan
 *
 *     openssl pkey -pubin -in kunci.pem -outform DER | sha256sum
 *
 * Dihitung dari DER, bukan dari teks PEM: berkas PEM yang sama dapat berbeda baris akhir, spasi, atau
 * komentar di laptop dan di server, dan sidik jari atas teks akan menyatakan dua kunci yang sama sebagai
 * dua kunci berbeda. Operator mencocokkan angka ini dengan kunci yang dipaku agen di server klien.
 */
final class KeyInspection
{
    /**
     * @return array{ok: true, fingerprint: string}|array{ok: false, error: string}
     */
    public static function publicKey(mixed $path, string $setting): array
    {
        $pem = self::read($path);

        if ($pem === null) {
            return ['ok' => false, 'error' => sprintf('Belum disetel atau berkasnya tidak terbaca (%s).', $setting)];
        }

        // Kunci privat yang tertukar ke jalur kunci publik juga "terbaca sebagai kunci publik" oleh
        // OpenSSL. Menyajikannya berarti membocorkannya, jadi ia ditolak dengan nama sebabnya.
        if (str_contains($pem, 'PRIVATE KEY')) {
            return ['ok' => false, 'error' => sprintf('Berkas di %s berisi kunci privat, bukan kunci publik.', $setting)];
        }

        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            return ['ok' => false, 'error' => sprintf('Berkas di %s bukan kunci publik PEM yang sah.', $setting)];
        }

        $fingerprint = self::fingerprint($key);

        return $fingerprint === null
            ? ['ok' => false, 'error' => sprintf('Sidik jari kunci di %s tidak dapat dihitung.', $setting)]
            : ['ok' => true, 'fingerprint' => $fingerprint];
    }

    /**
     * Kunci privat lisensi: terbaca dan sah, beserta sidik jari kunci publik pasangannya.
     *
     * @return array{ok: true, fingerprint: string}|array{ok: false, error: string}
     */
    public static function privateKey(mixed $path, string $setting): array
    {
        $pem = self::read($path);

        if ($pem === null) {
            return ['ok' => false, 'error' => sprintf('Belum disetel atau berkasnya tidak terbaca (%s).', $setting)];
        }

        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            return ['ok' => false, 'error' => sprintf('Berkas di %s bukan kunci privat PEM yang sah.', $setting)];
        }

        $fingerprint = self::fingerprint($key);

        return $fingerprint === null
            ? ['ok' => false, 'error' => sprintf('Sidik jari kunci di %s tidak dapat dihitung.', $setting)]
            : ['ok' => true, 'fingerprint' => $fingerprint];
    }

    private static function read(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return is_string($content) && trim($content) !== '' ? $content : null;
    }

    /**
     * `openssl_pkey_get_details()['key']` selalu memulangkan kunci **publik** dalam PEM, baik dari kunci
     * publik maupun privat — jadi satu fungsi melayani keduanya, dan sidik jari kunci privat sama dengan
     * sidik jari kunci publik pasangannya.
     */
    private static function fingerprint(\OpenSSLAsymmetricKey $key): ?string
    {
        $details = openssl_pkey_get_details($key);
        $pem = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : null;

        if ($pem === null) {
            return null;
        }

        $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $pem);
        $der = is_string($body) ? base64_decode($body, true) : false;

        return is_string($der) && $der !== '' ? hash('sha256', $der) : null;
    }
}
