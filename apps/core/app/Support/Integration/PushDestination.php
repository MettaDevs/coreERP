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
 * Nama host diselesaikan saat diperiksa — alamat IPv4 **dan** IPv6-nya — dan pemeriksaan diulang
 * setiap kali mengirim, supaya DNS yang diganti sesudah disimpan tidak melewati aturan kedua. Saat
 * mengirim, pengirim memakai alamat hasil pemeriksaan itu (`inspect()['pin']`) alih-alih membiarkan
 * klien HTTP meresolusi ulang: tanpanya, DNS yang berganti di antara pemeriksaan dan pengiriman
 * (DNS rebinding) tetap membawa kiriman ke jaringan privat.
 */
final class PushDestination
{
    /**
     * Rentang yang tidak boleh dituju dari SaaS, di samping `IpUtils::PRIVATE_SUBNETS`. Rentang IPv6-nya
     * menutup jalan memutar ke IPv4: alamat IPv4-mapped (`::ffff:10.0.0.5`) dan prefix NAT64 dapat
     * menjangkau jaringan privat IPv4 lewat alamat yang tampak seperti IPv6.
     */
    private const TERLARANG = [
        '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001:db8::/32', 'ff00::/8',
    ];

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolve(...);
    }

    /** Pesan penolakan dalam bahasa manusia, atau `null` bila URL boleh dituju. */
    public function reject(string $url): ?string
    {
        return $this->inspect($url)['reason'];
    }

    /**
     * Hasil pemeriksaan: `reason` berisi pesan penolakan atau `null`, dan `pin` berisi alamat yang
     * sudah diperiksa untuk dipakai saat mengirim, dalam bentuk entri `CURLOPT_RESOLVE`
     * (`host:port:alamat,…`). `pin` kosong di luar SaaS, bila host-nya sudah berupa alamat IP, atau
     * bila URL-nya ditolak.
     *
     * @return array{reason: ?string, pin: list<string>}
     */
    public function inspect(string $url): array
    {
        $bagian = parse_url($url);
        if (! is_array($bagian) || ($bagian['scheme'] ?? '') !== 'https' || ($bagian['host'] ?? '') === '') {
            return ['reason' => 'URL tujuan harus alamat https:// yang lengkap.', 'pin' => []];
        }

        if (! self::saas()) {
            return ['reason' => null, 'pin' => []];
        }

        $host = trim($bagian['host'], '[]');
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $alamat = $literal ? [$host] : ($this->resolver)($host);
        if ($alamat === []) {
            return ['reason' => 'Nama host tujuan tidak dapat diselesaikan.', 'pin' => []];
        }

        foreach ($alamat as $ip) {
            if (IpUtils::checkIp($ip, [...IpUtils::PRIVATE_SUBNETS, ...self::TERLARANG])) {
                return ['reason' => 'URL tujuan menunjuk jaringan privat. Dari layanan SaaS, aplikasi finance harus dapat dijangkau lewat alamat publik.', 'pin' => []];
            }
        }

        if ($literal) {
            return ['reason' => null, 'pin' => []];
        }

        $daftar = implode(',', array_map(static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $alamat));

        return ['reason' => null, 'pin' => [sprintf('%s:%d:%s', $host, $bagian['port'] ?? 443, $daftar)]];
    }

    /**
     * Alamat IPv4 lewat resolver sistem — yang juga membaca berkas hosts — ditambah catatan AAAA
     * DNS. `gethostbynamel()` sendiri hanya mengenal IPv4, sehingga host dengan IPv4 publik dan
     * IPv6 privat dulu lolos (TODO feed posting 4.8).
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $alamat = gethostbynamel($host) ?: [];
        $catatan = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($catatan) ? $catatan : [] as $baris) {
            if (isset($baris['ipv6']) && is_string($baris['ipv6'])) {
                $alamat[] = $baris['ipv6'];
            }
        }

        return array_values(array_unique($alamat));
    }

    private static function saas(): bool
    {
        return trim((string) config('coreerp.base_domain')) !== '';
    }
}
