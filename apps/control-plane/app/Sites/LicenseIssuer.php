<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use ControlPlane\Models\Site;

/**
 * Menerbitkan lisensi bertanda tangan untuk sebuah situs.
 *
 * Bentuknya dibaca Core di server klien (`App\Support\License\SiteLicense`): JSON persis yang
 * ditandatangani, dan tanda tangan RSA SHA-256 base64 atas byte itu. Core hanya menampilkan
 * peringatan dari isinya — tidak ada yang dikunci ketika lisensi habis, karena klien kita fasilitas
 * kesehatan dan aplikasi yang berhenti berarti pelayanan pasien berhenti.
 */
final class LicenseIssuer
{
    /** @return array{license: string, signature: string} */
    public function issue(Site $site, string $validUntil): array
    {
        $path = config('sites.license_private_key_path');
        $pem = is_string($path) && $path !== '' && is_readable($path) ? file_get_contents($path) : false;
        $key = is_string($pem) ? openssl_pkey_get_private($pem) : false;

        if ($key === false) {
            throw new SiteRejected(
                'license_key_missing',
                'Kunci privat lisensi belum disetel di konsol ini (CONSOLE_LICENSE_PRIVATE_KEY_PATH), jadi lisensi tidak dapat diterbitkan.',
            );
        }

        $license = (string) json_encode([
            'version' => 1,
            'tenant_id' => $site->tenant_id,
            'site_id' => $site->id,
            'edition' => $site->edition,
            'valid_until' => $validUntil,
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES);

        if (! openssl_sign($license, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new SiteRejected('license_sign_failed', 'Lisensi gagal ditandatangani.');
        }

        return ['license' => $license, 'signature' => base64_encode($signature)];
    }

    /** Kunci publik lisensi dalam PEM, untuk diantar ke agen saat pendaftaran. */
    public function publicKey(): ?string
    {
        $path = config('sites.license_public_key_path');

        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        $pem = file_get_contents($path);

        return is_string($pem) && $pem !== '' ? $pem : null;
    }
}
