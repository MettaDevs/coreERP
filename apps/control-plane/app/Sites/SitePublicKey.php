<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

/**
 * Kunci publik situs yang dapat diterima: PEM, RSA, paling sedikit 2048 bit.
 *
 * Diperiksa saat kunci masuk, bukan saat pertama kali dipakai memverifikasi. Kunci yang tidak dapat
 * dibaca dan telanjur tersimpan membuat situs itu terdaftar tetapi tidak pernah dapat melapor — dan
 * yang terlihat di layar hanyalah situs yang diam.
 */
final class SitePublicKey
{
    public const MINIMUM_BITS = 2048;

    public static function acceptable(mixed $pem): bool
    {
        if (! is_string($pem) || ! str_contains($pem, '-----BEGIN PUBLIC KEY-----') || strlen($pem) > 16000) {
            return false;
        }

        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            return false;
        }

        $details = openssl_pkey_get_details($key);

        return is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && (int) ($details['bits'] ?? 0) >= self::MINIMUM_BITS;
    }
}
