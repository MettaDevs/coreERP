<?php

declare(strict_types=1);

namespace App\Support\Integration;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Memeriksa URL tujuan mode `push` sebelum CoreERP mengirim apa pun ke sana (K-03).
 *
 * Dua aturan:
 *
 * 1. **Wajib HTTPS.** Yang terkirim adalah jurnal keuangan dan data vendor.
 * 2. **Di SaaS, tidak boleh menunjuk jaringan privat.** URL itu diketik admin tenant, dan server SaaS
 *    melayani banyak tenant sekaligus: `https://10.0.0.5/...` atau alamat metadata awan akan membuat
 *    server kita memanggil jaringan dalamnya sendiri atas perintah satu tenant (SSRF). Di on-prem
 *    aturan ini justru salah — aplikasi finance pelanggan lazim berada di LAN yang sama — jadi
 *    hanya berlaku bila CoreERP berjalan di bawah domain dasar SaaS.
 *
 * Nama host diselesaikan saat diperiksa, dan pemeriksaan diulang setiap kali mengirim, supaya DNS
 * yang diganti sesudah disimpan tidak melewati aturan kedua.
 */
final class PushDestination
{
    /** Rentang yang tidak boleh dituju dari SaaS, di samping `IpUtils::PRIVATE_SUBNETS`. */
    private const TERLARANG = ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4'];

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static fn (string $host): array => gethostbynamel($host) ?: [];
    }

    /** Pesan penolakan dalam bahasa manusia, atau `null` bila URL boleh dituju. */
    public function reject(string $url): ?string
    {
        $bagian = parse_url($url);
        if (! is_array($bagian) || ($bagian['scheme'] ?? '') !== 'https' || ($bagian['host'] ?? '') === '') {
            return 'URL tujuan harus alamat https:// yang lengkap.';
        }

        if (! self::saas()) {
            return null;
        }

        $host = trim($bagian['host'], '[]');
        $alamat = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($alamat === []) {
            return 'Nama host tujuan tidak dapat diselesaikan.';
        }

        foreach ($alamat as $ip) {
            if (IpUtils::checkIp($ip, [...IpUtils::PRIVATE_SUBNETS, ...self::TERLARANG])) {
                return 'URL tujuan menunjuk jaringan privat. Dari layanan SaaS, aplikasi finance harus dapat dijangkau lewat alamat publik.';
            }
        }

        return null;
    }

    private static function saas(): bool
    {
        return trim((string) config('coreerp.base_domain')) !== '';
    }
}
