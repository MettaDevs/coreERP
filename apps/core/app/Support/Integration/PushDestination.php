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
 * Nama host diselesaikan ke alamat IPv4 **dan** IPv6-nya, dan satu alamat terlarang sudah cukup untuk
 * menolak: host dengan IPv4 publik tidak boleh lolos hanya karena IPv6 privatnya tidak ditanyakan.
 * Pemeriksaan diulang setiap kali mengirim, dan kirimannya dipatok ke alamat yang baru saja diperiksa
 * (`inspect()`), supaya DNS yang diganti sesudah URL disimpan — atau di antara pemeriksaan dan
 * pengiriman — tidak melewati aturan kedua.
 */
final class PushDestination
{
    /**
     * Rentang yang tidak boleh dituju dari SaaS, di samping `IpUtils::PRIVATE_SUBNETS`. Daftar Symfony itu
     * sudah memuat IPv6 yang membungkus alamat IPv4 (NAT64, 6to4, Teredo, kompatibel IPv4); yang
     * ditambahkan untuk IPv6 hanya site-local lama dan multicast.
     */
    private const FORBIDDEN = [
        '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        'fec0::/10', 'ff00::/8',
    ];

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver  Alamat IPv4 dan IPv6 sebuah nama host. */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static fn (string $host): array => self::lookup($host);
    }

    /** Pesan penolakan dalam bahasa manusia, atau `null` bila URL boleh dituju. */
    public function reject(string $url): ?string
    {
        return $this->inspect($url)['rejection'];
    }

    /**
     * Pemeriksaan yang sama dengan `reject()` untuk saat mengirim, dengan nama host yang diselesaikan
     * satu kali saja.
     *
     * - `unresolved`: nama host tidak menghasilkan satu alamat pun. Bisa gangguan DNS sesaat, jadi
     *   pengirim memperlakukannya seperti tujuan yang tidak terjangkau, bukan penolakan tetap.
     * - `resolve`: entri `CURLOPT_RESOLVE` ke alamat yang baru saja lolos. cURL memakainya apa adanya
     *   dan tidak bertanya ke DNS lagi. Kosong bila tidak ada yang perlu dipatok: di on-prem, atau
     *   bila URL sudah berupa alamat IP.
     *
     * @return array{rejection: ?string, unresolved: bool, resolve: list<string>}
     */
    public function inspect(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return self::result('URL tujuan harus alamat https:// yang lengkap.');
        }

        if (! self::saas()) {
            return self::result(null);
        }

        $host = trim($parts['host'], '[]');
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = array_values(array_filter(
            $literal ? [$host] : ($this->resolver)($host),
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false,
        ));
        if ($addresses === []) {
            return self::result('Nama host tujuan tidak dapat diselesaikan.', unresolved: true);
        }

        foreach ($addresses as $ip) {
            if (IpUtils::checkIp($ip, [...IpUtils::PRIVATE_SUBNETS, ...self::FORBIDDEN])) {
                return self::result('URL tujuan menunjuk jaringan privat. Dari layanan SaaS, aplikasi finance harus dapat dijangkau lewat alamat publik.');
            }
        }

        if ($literal) {
            return self::result(null);
        }

        // cURL mencocokkan entri ini dengan nama host di URL apa adanya — huruf besar-kecil tidak
        // berpengaruh, titik di ujung nama berpengaruh — jadi nama host tidak dinormalkan.
        $pinned = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $addresses);

        return self::result(null, resolve: [sprintf('%s:%d:%s', $parts['host'], $parts['port'] ?? 443, implode(',', $pinned))]);
    }

    /**
     * IPv4 lewat resolver sistem, ditambah AAAA dari DNS. Pertanyaan AAAA yang gagal hanya berarti tidak
     * ada alamat IPv6 untuk dipatok, sehingga cURL tidak memakai IPv6. Tanda `@` karena galat DNS sesaat
     * dilaporkan PHP sebagai warning, dan warning di sini tidak boleh menghentikan putaran push.
     *
     * @return list<string>
     */
    private static function lookup(string $host): array
    {
        $ipv6 = @dns_get_record($host, DNS_AAAA);

        return array_values(array_unique([
            ...(gethostbynamel($host) ?: []),
            ...array_column(is_array($ipv6) ? $ipv6 : [], 'ipv6'),
        ]));
    }

    /**
     * @param  list<string>  $resolve
     * @return array{rejection: ?string, unresolved: bool, resolve: list<string>}
     */
    private static function result(?string $rejection, bool $unresolved = false, array $resolve = []): array
    {
        return ['rejection' => $rejection, 'unresolved' => $unresolved, 'resolve' => $resolve];
    }

    private static function saas(): bool
    {
        return trim((string) config('coreerp.base_domain')) !== '';
    }
}
