<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSSLAsymmetricKey;

/**
 * Menulis lisensi situs bertanda tangan ke disk, persis bentuk yang dipasang agen.
 *
 * Dipakai tiga kelas test — pembaca, middleware kunci, dan saringan app — dan satu salinan di sini
 * lebih baik daripada tiga yang kelak berbeda pendapat tentang bentuk berkasnya. Test yang menulis
 * lisensinya sendiri dengan bentuk yang sedikit lain akan hijau terhadap pembaca yang salah.
 *
 * Kuncinya dibuat di dalam test, bukan diambil dari berkas di repo. Kunci privat yang ikut
 * di-commit — sekalipun "hanya untuk test" — adalah kunci yang suatu hari disalin ke tempat lain.
 */
trait WritesSiteLicenses
{
    private const LICENSED_TENANT_ID = '01j9zq3v6n8m2k4h7g5f3d1c0b';

    /** @var array{release: OpenSSLAsymmetricKey, foreign: OpenSSLAsymmetricKey}|null */
    private static ?array $licenseKeys = null;

    private string $licenseDirectory;

    /**
     * Folder sementara beserta kunci publik rilis, dan config yang menunjuknya.
     *
     * `required` dimatikan dan `warn_days` disetel eksplisit: nilai dari `.env` pengembang atau dari
     * config yang kelak berubah tidak boleh menentukan hasil test ini.
     */
    protected function prepareSiteLicenseDirectory(): void
    {
        $this->licenseDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'coreerp-lisensi-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($this->licenseDirectory);

        config()->set('coreerp.license.required', false);
        config()->set('coreerp.license.path', $this->licensePath());
        config()->set('coreerp.license.public_key_path', $this->licenseDirectory.DIRECTORY_SEPARATOR.'release.pub.pem');
        config()->set('coreerp.license.warn_days', 7);

        File::put($this->licenseDirectory.DIRECTORY_SEPARATOR.'release.pub.pem', $this->publicPem('release'));

        $this->ensureLicensedTenant();
    }

    /**
     * Tenant pemilik lisensi uji. Pembaca menolak lisensi untuk tenant yang tidak hidup di server ini,
     * jadi setiap test yang menulis lisensi sah butuh tenant dengan id yang sama.
     */
    protected function ensureLicensedTenant(): void
    {
        if (DB::table('tenants')->where('id', self::LICENSED_TENANT_ID)->exists()) {
            return;
        }

        $clientId = (string) Str::ulid();
        $suffix = Str::lower(Str::random(6));

        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'Klien Berlisensi', 'slug' => 'klien-berlisensi-'.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => self::LICENSED_TENANT_ID, 'client_id' => $clientId, 'name' => 'Tenant Berlisensi', 'slug' => 'tenant-berlisensi-'.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function removeSiteLicenseDirectory(): void
    {
        File::deleteDirectory($this->licenseDirectory);
    }

    protected function licensePath(): string
    {
        return $this->licenseDirectory.DIRECTORY_SEPARATOR.'license.json';
    }

    /**
     * Isi lisensi versi 2 dengan urutan bidang yang sama dengan contoh di kontrak.
     *
     * @param  list<string>  $apps
     */
    protected function licenseJson(string $validUntil, array $apps = ['contoh-a']): string
    {
        return json_encode([
            'version' => 2,
            'tenant_id' => self::LICENSED_TENANT_ID,
            'site_id' => '01j9zq3v6n8m2k4h7g5f3d1c0c',
            'apps' => $apps,
            'valid_until' => $validUntil,
            'issued_at' => '2026-09-15T08:00:00Z',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Menulis `license.json` beserta `.sig`-nya.
     *
     * Tanda tangannya diakhiri baris baru, karena begitulah berkas satu baris biasanya ditulis —
     * sehingga setiap test hijau yang memakai ini sekaligus membuktikan baris baru itu diterima.
     *
     * @param  'release'|'foreign'  $signer
     */
    protected function installSignedLicense(string $bytes, string $signer = 'release'): void
    {
        $signed = openssl_sign($bytes, $signature, $this->licenseKeys()[$signer], OPENSSL_ALGO_SHA256);
        $this->assertTrue($signed, 'Lisensi uji tidak dapat ditandatangani.');

        File::put($this->licensePath(), $bytes);
        File::put($this->licensePath().'.sig', base64_encode((string) $signature)."\n");
    }

    /** @param  'release'|'foreign'  $which */
    private function publicPem(string $which): string
    {
        $details = openssl_pkey_get_details($this->licenseKeys()[$which]);
        $this->assertIsArray($details);

        return (string) $details['key'];
    }

    /**
     * Dua pasang kunci RSA, dibuat sekali per kelas — membuat kunci 2048 bit per test hanya
     * memperlambat suite tanpa membuktikan apa pun tambahan.
     *
     * @return array{release: OpenSSLAsymmetricKey, foreign: OpenSSLAsymmetricKey}
     */
    private function licenseKeys(): array
    {
        return self::$licenseKeys ??= [
            'release' => $this->makeLicenseKeyPair(),
            'foreign' => $this->makeLicenseKeyPair(),
        ];
    }

    private function makeLicenseKeyPair(): OpenSSLAsymmetricKey
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);

        // PHP untuk Windows tidak menemukan `openssl.cnf`-nya sendiri, dan `openssl_pkey_new()` gagal
        // dengan "configuration file routines::no such file". Berkasnya ikut terpasang di samping
        // binary PHP; di Linux cabang ini tidak pernah diambil.
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if ($key === false && is_file($bundledConfig)) {
            $key = openssl_pkey_new($options + ['config' => $bundledConfig]);
        }

        $this->assertNotFalse($key, 'Kunci RSA untuk lisensi uji tidak dapat dibuat.');

        return $key;
    }
}
