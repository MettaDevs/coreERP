<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use ControlPlane\Models\SiteRelease;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Mendaftarkan berkas rilis bertanda tangan dari alur rilis.
 *
 * Tidak ada yang disimpan sebelum tiga hal terbukti, berurutan:
 *
 * 1. `SHA256SUMS.sig` sah atas `SHA256SUMS` terhadap kunci publik rilis.
 * 2. `SHA256SUMS` menyebut tepat tiga berkas — manifest, compose, dan skrip pembaruan — dan checksum
 *    masing-masing cocok dengan isi yang diunggah. Berkas lain di daftar, termasuk `images.tar.gz`,
 *    ditolak: agen menjalankan `sha256sum --check` atas daftar itu, dan berkas yang disebut tetapi
 *    tidak ada akan menggagalkan pembaruan di server klien, bukan di sini.
 * 3. `manifest.json` menyebut image lewat digest registry. Tag dapat dipindahkan; digest tidak.
 *
 * Konsol ini tidak pernah memegang kunci privat rilis, jadi kalau ia dibobol penyerang tetap tidak
 * dapat mendaftarkan rilis — hanya memilih di antara yang sudah ada.
 */
final class ReleaseRegistry
{
    private const CHECKSUMMED = [
        'manifest.json' => 'manifest',
        'compose.yaml' => 'compose',
        'update.sh' => 'update_script',
    ];

    /**
     * @param  array{manifest: string, compose: string, update_script: string, checksums: string, signature: string}  $files
     * @return array{release: SiteRelease, created: bool}
     */
    public function register(array $files): array
    {
        $this->verifySignature($files['checksums'], $files['signature']);
        $this->verifyChecksums($files);

        $manifest = json_decode($files['manifest'], true);

        if (! is_array($manifest)) {
            throw new SiteRejected('manifest_invalid', 'manifest.json bukan JSON.');
        }

        $edition = is_string($manifest['edisi'] ?? null) ? $manifest['edisi'] : '';
        $release = is_string($manifest['rilis'] ?? null) ? $manifest['rilis'] : '';
        $image = is_string($manifest['image'] ?? null) ? $manifest['image'] : '';
        $digest = is_string($manifest['digest'] ?? null) ? $manifest['digest'] : '';

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $edition) !== 1
            || preg_match('/^\d+(\.\d+){1,3}$/', $release) !== 1) {
            throw new SiteRejected('manifest_invalid', 'manifest.json harus menyebut edisi dan nomor rilis bertitik.');
        }

        if (preg_match('/@sha256:[a-f0-9]{64}$/', $image) !== 1 || preg_match('/^sha256:[a-f0-9]{64}$/', $digest) !== 1) {
            throw new SiteRejected('manifest_invalid', 'manifest.json harus menyebut image lewat digest registry dan id image-nya.');
        }

        $attributes = [
            'edition' => $edition,
            'release' => $release,
            'image' => $image,
            'digest' => $digest,
            'manifest' => $files['manifest'],
            'compose' => $files['compose'],
            'update_script' => $files['update_script'],
            'checksums' => $files['checksums'],
            'signature' => base64_encode($files['signature']),
        ];

        $existing = SiteRelease::query()->where('edition', $edition)->where('release', $release)->first();

        if ($existing instanceof SiteRelease) {
            return ['release' => $this->sameOrConflict($existing, $attributes), 'created' => false];
        }

        try {
            return ['release' => SiteRelease::query()->create($attributes), 'created' => true];
        } catch (UniqueConstraintViolationException) {
            // Dua pendaftaran pada detik yang sama. Yang kalah diperlakukan seperti pendaftaran ulang.
            $winner = SiteRelease::query()->where('edition', $edition)->where('release', $release)->firstOrFail();

            return ['release' => $this->sameOrConflict($winner, $attributes), 'created' => false];
        }
    }

    private function verifySignature(string $checksums, string $signature): void
    {
        $path = config('sites.release_public_key_path');
        $pem = is_string($path) && $path !== '' && is_readable($path) ? file_get_contents($path) : false;

        if (! is_string($pem) || openssl_pkey_get_public($pem) === false) {
            throw new SiteRejected(
                'release_key_missing',
                'Kunci publik rilis belum disetel di konsol ini (CONSOLE_RELEASE_PUBLIC_KEY_PATH); pendaftaran rilis ditolak.',
            );
        }

        if (openssl_verify($checksums, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new SiteRejected('signature_invalid', 'Tanda tangan SHA256SUMS tidak sah terhadap kunci publik rilis.');
        }
    }

    /** @param  array<string, string>  $files */
    private function verifyChecksums(array $files): void
    {
        $listed = [];

        foreach (preg_split('/\r?\n/', trim($files['checksums'])) ?: [] as $line) {
            if (preg_match('/^([a-f0-9]{64}) [ *]?(\S+)$/', trim($line), $match) !== 1) {
                throw new SiteRejected('checksums_invalid', 'SHA256SUMS memuat baris yang tidak dikenal.');
            }

            $listed[$match[2]] = $match[1];
        }

        ksort($listed);
        $expected = self::CHECKSUMMED;
        ksort($expected);

        if (array_keys($listed) !== array_keys($expected)) {
            throw new SiteRejected('checksums_invalid', 'SHA256SUMS harus menyebut tepat manifest.json, compose.yaml, dan update.sh.');
        }

        foreach (self::CHECKSUMMED as $name => $field) {
            if (! hash_equals($listed[$name], hash('sha256', $files[$field]))) {
                throw new SiteRejected('checksums_invalid', sprintf('Checksum %s tidak cocok dengan isinya.', $name));
            }
        }
    }

    /** @param  array<string, string>  $attributes */
    private function sameOrConflict(SiteRelease $existing, array $attributes): SiteRelease
    {
        foreach (['manifest', 'compose', 'update_script', 'checksums'] as $field) {
            if ($existing->getAttribute($field) !== $attributes[$field]) {
                throw new SiteRejected(
                    'release_conflict',
                    sprintf('Edisi %s rilis %s sudah terdaftar dengan isi berbeda. Naikkan nomor rilis di manifest edisi.', $attributes['edition'], $attributes['release']),
                );
            }
        }

        return $existing;
    }
}
