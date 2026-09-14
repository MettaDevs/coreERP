<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Berkas rilis bertanda tangan yang dapat dipasang agen — tabel `site_releases` milik Core.
 *
 * `signature` tersimpan base64; berkas `SHA256SUMS.sig` yang diterima agen adalah bentuk binernya,
 * persis keluaran `openssl dgst -sign`.
 *
 * @property string $id
 * @property string $edition
 * @property string $release
 * @property string $image
 * @property string $digest
 * @property string $manifest
 * @property string $compose
 * @property string $update_script
 * @property string $checksums
 * @property string $signature
 */
class SiteRelease extends Model
{
    use HasUlids;

    /** Nama berkas di kontrak agen, dipetakan ke kolomnya. */
    public const FILES = [
        'manifest.json' => 'manifest',
        'compose.yaml' => 'compose',
        'update.sh' => 'update_script',
        'SHA256SUMS' => 'checksums',
        'SHA256SUMS.sig' => 'signature',
    ];

    protected $table = 'site_releases';

    protected $fillable = [
        'edition',
        'release',
        'image',
        'digest',
        'manifest',
        'compose',
        'update_script',
        'checksums',
        'signature',
    ];

    public function fileContents(string $file): ?string
    {
        $column = self::FILES[$file] ?? null;

        if ($column === null) {
            return null;
        }

        $value = (string) $this->getAttribute($column);

        return $column === 'signature' ? (base64_decode($value, true) ?: null) : $value;
    }

    /**
     * Membandingkan dua nomor rilis bertitik — `0.10.0` lebih besar dari `0.9.3`.
     *
     * Perbandingan string biasa salah persis pada kasus yang paling berbahaya: ia menyatakan `0.10.0`
     * lebih kecil, lalu agen menolak rilis yang sah atau konsol menawarkan rilis lama sebagai
     * pembaruan.
     */
    public static function compare(string $a, string $b): int
    {
        return version_compare($a, $b);
    }
}
