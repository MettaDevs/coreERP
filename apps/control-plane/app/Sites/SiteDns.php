<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Dns\CloudflareClient;
use ControlPlane\Dns\DnsUnavailable;
use ControlPlane\Models\Site;
use Illuminate\Http\Request;

/**
 * Record DNS yang membawa alamat aplikasi server klien ke mesinnya.
 *
 * Produksi di server klien beralamat `<tenant>.<domain dasar>`, bentuk yang sama dengan produksi di server
 * kita (`EnvironmentAddress`). Wildcard domain dasar menunjuk server kita; record dengan nama persis
 * mengalahkannya, jadi record itu yang membuat alamat tersebut sampai ke IP server klien — tempat proxy agen
 * mengambil sertifikat Let's Encrypt untuknya.
 *
 * ## Hanya record milik sendiri yang disentuh
 *
 * Setiap record yang ditulis membawa komentar `coreerp-site:<id situs>`. Record dengan nama yang sama tanpa
 * komentar itu dianggap milik orang lain, dan penulisan ditolak dengan menyebut isinya — menimpanya berarti
 * memindahkan layanan orang lain ke server klien tanpa ada yang tahu. Penghapusan memakai id yang tercatat di
 * `sites.dns_record_id`, tidak pernah nama.
 *
 * ## Jenis record
 *
 * IPv4 menjadi A, IPv6 menjadi AAAA, nama host menjadi CNAME. Ketiganya tidak boleh berdampingan pada satu nama
 * di Cloudflare, jadi pergantian jenis menghapus record lama lebih dulu.
 */
final class SiteDns
{
    public const COMMENT_PREFIX = 'coreerp-site:';

    public function __construct(private readonly CloudflareClient $cloudflare) {}

    /** Nama host alamat aplikasi situs, atau null bila situs ini tidak punya alamat otomatis. */
    public static function hostFor(Site $site): ?string
    {
        $url = $site->appUrl();
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? mb_strtolower($host) : null;
    }

    /** Jenis record untuk alamat server ini. */
    public static function typeFor(string $target): string
    {
        return match (true) {
            filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 'A',
            filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 'AAAA',
            default => 'CNAME',
        };
    }

    /**
     * Keadaan record dilihat dari yang tercatat, tanpa bertanya ke Cloudflare: `none` belum pernah ditulis,
     * `synced` mengikuti alamat server sekarang, `outdated` ditulis untuk alamat server atau nama yang lain.
     */
    public static function state(Site $site): string
    {
        if ($site->dns_record_id === null) {
            return 'none';
        }

        return $site->dns_name === self::hostFor($site) && $site->dns_target === $site->server_address ? 'synced' : 'outdated';
    }

    /**
     * Bentuk yang dikirim ke layar: keadaan dan isi record yang tercatat, tanpa bertanya ke Cloudflare pada
     * setiap baris daftar. Nama yang belum ditulis tetap disebut, supaya operator tahu record apa yang akan dibuat.
     *
     * @return array{state: string, name: ?string, target: ?string, syncedAt: ?string}
     */
    public static function forScreen(Site $site): array
    {
        return [
            'state' => self::state($site),
            'name' => $site->dns_name ?? self::hostFor($site),
            'target' => $site->dns_target,
            'syncedAt' => $site->dns_synced_at?->toDateTimeString(),
        ];
    }

    /**
     * Memastikan record `<host> → alamat server` ada dan milik situs ini.
     *
     * @throws DnsUnavailable
     */
    public function ensure(Request $request, Site $site): void
    {
        $name = self::hostFor($site);
        $target = $site->server_address;

        if ($site->environment_id === null || $name === null) {
            throw new DnsUnavailable('Server klien ini tidak punya alamat aplikasi otomatis, jadi tidak ada record DNS untuknya.');
        }

        if ($target === null) {
            throw new DnsUnavailable(sprintf('Catat alamat server (IP VPS) lebih dulu. Alamat itu yang dituju record DNS %s.', $name));
        }

        if ($target === $name) {
            throw new DnsUnavailable(sprintf('Alamat server tidak boleh sama dengan alamat aplikasinya sendiri (%s).', $name));
        }

        $type = self::typeFor($target);
        $comment = self::COMMENT_PREFIX.$site->id;
        $zone = $this->cloudflare->zone();

        if (! str_ends_with($name, '.'.$zone['name']) && $name !== $zone['name']) {
            throw new DnsUnavailable(sprintf('Alamat %s tidak berada di zona Cloudflare %s.', $name, $zone['name']));
        }

        $before = ['name' => $site->dns_name, 'target' => $site->dns_target];

        // Nama lama milik situs ini — tenant yang slug-nya berganti — dibuang lebih dulu, lewat id.
        if ($site->dns_record_id !== null && $site->dns_name !== $name) {
            $this->cloudflare->delete($zone['id'], $site->dns_record_id);
        }

        $records = $this->cloudflare->records($zone['id'], $name);
        $foreign = array_values(array_filter($records, fn (array $record): bool => $record['comment'] !== $comment));

        if ($foreign !== []) {
            throw new DnsUnavailable(sprintf(
                'Nama %s sudah punya record DNS yang bukan buatan admin.erp (%s → %s). Hapus atau pindahkan record itu di Cloudflare lebih dulu; admin.erp tidak menimpa record milik orang lain.',
                $name,
                $foreign[0]['type'],
                $foreign[0]['content'],
            ));
        }

        $keep = null;

        foreach ($records as $record) {
            if ($keep === null && $record['type'] === $type) {
                $keep = $record;

                continue;
            }

            // Jenis lain milik sendiri, atau salinan kedua: tidak boleh berdampingan dengan yang ditulis.
            $this->cloudflare->delete($zone['id'], $record['id']);
        }

        $id = $keep !== null && $keep['content'] === $target
            ? $keep['id']
            : $this->cloudflare->put($zone['id'], $keep['id'] ?? null, $name, $type, $target, $comment);

        $site->forceFill([
            'dns_record_id' => $id,
            'dns_name' => $name,
            'dns_target' => $target,
            'dns_synced_at' => now(),
        ])->save();

        if ($before !== ['name' => $name, 'target' => $target]) {
            OperatorAudit::record($request, 'site.dns.written', 'site', $site->id, [
                'before' => $before,
                'after' => ['name' => $name, 'type' => $type, 'target' => $target],
            ]);
        }
    }

    /**
     * Menghapus record milik situs ini. Tanpa record tercatat tidak ada yang dilakukan.
     *
     * @throws DnsUnavailable
     */
    public function remove(Request $request, Site $site): void
    {
        if ($site->dns_record_id === null) {
            return;
        }

        $zone = $this->cloudflare->zone();
        $this->cloudflare->delete($zone['id'], $site->dns_record_id);

        $removed = ['name' => $site->dns_name, 'target' => $site->dns_target];

        $site->forceFill([
            'dns_record_id' => null,
            'dns_name' => null,
            'dns_target' => null,
            'dns_synced_at' => null,
        ])->save();

        OperatorAudit::record($request, 'site.dns.deleted', 'site', $site->id, $removed);
    }
}
