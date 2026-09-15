<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Carbon\CarbonImmutable;
use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSSLAsymmetricKey;

/**
 * Menerbitkan lisensi bertanda tangan (versi 2) untuk sebuah situs.
 *
 * Kontraknya di `docs/todo/lisensi-mengunci/README.md`, dan pembacanya Core di server klien: JSON
 * persis yang ditandatangani, dan tanda tangan RSA PKCS#1 v1.5 SHA-256 base64 atas byte itu.
 *
 * ## Lisensi mengunci
 *
 * Isi `apps` adalah satu-satunya yang membedakan klien yang membeli satu app dari klien yang membeli
 * semuanya — image-nya sama. Karena itu daftarnya dibaca dari Core saat penerbitan, tidak pernah
 * disusun di sini, dan penerbitan berhenti bila daftar itu tidak terbaca (`EntitlementsUnavailable`).
 *
 * ## Setiap penerbitan tercatat
 *
 * `sites.license_issued_at` dan `license_valid_until` ditulis bersama jejak auditnya dalam satu
 * transaksi. Perpanjangan otomatis dicatat tanpa pengguna; penerbitan lewat operasi `install_license`
 * dicatat atas nama operatornya. Tanda tangannya tidak ikut dicatat — isinya yang dicatat: app dan
 * tanggal berakhirnya, cukup untuk menjawab "apa yang pernah kita izinkan di server ini".
 */
final class LicenseIssuer
{
    public function __construct(private readonly EntitlementsFromCore $entitlements) {}

    /**
     * Lisensi yang diminta operator, misalnya segera sesudah app ditambah.
     *
     * @param  ?string  $validUntil  `Y-m-d` yang sudah diperiksa pemanggilnya; null berarti masa bawaan.
     * @return array{license: string, signature: string}
     *
     * @throws SiteRejected kunci privat belum disetel, atau penandatanganan gagal.
     * @throws EntitlementsUnavailable
     */
    public function issueForOperator(Request $request, Site $site, ?string $validUntil = null): array
    {
        return $this->issue($site, $validUntil, static function (array $detail) use ($request, $site): void {
            OperatorAudit::record($request, 'site.license.issued', 'site', $site->id, $detail);
        });
    }

    /**
     * Lisensi yang diterbitkan konsol sendiri untuk jawaban laporan agen.
     *
     * @return array{license: string, signature: string}
     *
     * @throws SiteRejected
     * @throws EntitlementsUnavailable
     */
    public function renew(Site $site, ?string $agentIp = null): array
    {
        return $this->issue($site, null, static function (array $detail) use ($agentIp, $site): void {
            OperatorAudit::recordBySystem($agentIp, 'site.license.renewed', 'site', $site->id, $detail);
        });
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

    /**
     * Tanggal berakhir bawaan: hari ini ditambah `license_valid_days`, di zona waktu situs.
     *
     * Zona waktu situs, bukan zona waktu konsol (UTC). `valid_until` adalah tanggal kalender yang
     * dibaca Core di server klien, dan "hari ini" yang berarti adalah hari di klinik itu.
     */
    public function defaultValidUntil(Site $site): string
    {
        return CarbonImmutable::now($site->timezone)
            ->addDays((int) config('sites.license_valid_days'))
            ->toDateString();
    }

    /**
     * @param  callable(array<string, mixed>): void  $audit
     * @return array{license: string, signature: string}
     */
    private function issue(Site $site, ?string $validUntil, callable $audit): array
    {
        // Kunci diperiksa sebelum Core dipanggil. Konsol yang belum disetel tidak perlu membebani Core
        // setiap kali agen melapor, dan operator membaca sebab yang benar lebih dulu.
        $key = $this->privateKey();
        $apps = $this->entitlements->appsFor($site->tenant_id);
        $validUntil ??= $this->defaultValidUntil($site);
        $issuedAt = CarbonImmutable::now('UTC');

        // Urutan kunci bagian dari kontrak: yang ditandatangani adalah byte ini, dan agen maupun Core
        // tidak menyusunnya ulang.
        $license = (string) json_encode([
            'version' => 2,
            'tenant_id' => $site->tenant_id,
            'site_id' => $site->id,
            'apps' => $apps,
            'valid_until' => $validUntil,
            'issued_at' => $issuedAt->format('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! openssl_sign($license, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new SiteRejected('license_sign_failed', 'Lisensi gagal ditandatangani.');
        }

        DB::transaction(function () use ($site, $apps, $validUntil, $issuedAt, $audit): void {
            $site->forceFill([
                'license_issued_at' => $issuedAt,
                'license_valid_until' => $validUntil,
            ])->save();

            $audit([
                'apps' => $apps,
                'valid_until' => $validUntil,
                'issued_at' => $issuedAt->toIso8601String(),
            ]);
        });

        return ['license' => $license, 'signature' => base64_encode($signature)];
    }

    private function privateKey(): OpenSSLAsymmetricKey
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

        return $key;
    }
}
