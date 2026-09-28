<?php

declare(strict_types=1);

namespace ControlPlane\Console\Commands;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Dns\CloudflareClient;
use ControlPlane\Dns\CloudflareSettings;
use ControlPlane\Dns\DnsUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan token API Cloudflare untuk record DNS server klien, dibaca dari stdin.
 *
 *   read -rsp 'Token Cloudflare: ' T; echo
 *   printf '%s' "$T" | sudo docker exec -i -u www-data <container konsol> php artisan dns:token-cloudflare; unset T
 *
 * `-u www-data`, bukan root: perintah yang berjalan sebagai root di container meninggalkan berkas log milik root,
 * dan Apache lalu gagal menulis log tanpa jejak.
 *
 * Tokennya dibaca sebagai data, tidak pernah sebagai argumen: argumen terlihat di daftar proses dan riwayat
 * shell. Masukan boleh berupa token saja, atau baris `CLOUDFLARE_API_TOKEN='…'`.
 *
 * Token diperiksa ke Cloudflare **sebelum** tersimpan — zona domain dasar harus terlihat olehnya. Token yang
 * salah ketik tidak menggantikan token yang sedang bekerja; kegagalannya baru akan terlihat pada perintah pasang
 * berikutnya.
 */
final class SetCloudflareToken extends Command
{
    protected $signature = 'dns:token-cloudflare {--berkas= : Baca dari berkas ini, bukan dari stdin}';

    protected $description = 'Menyimpan token API Cloudflare dari stdin, sesudah zona domain dasar terbukti terlihat';

    public function handle(CloudflareSettings $settings, CloudflareClient $cloudflare): int
    {
        $path = $this->input->getOption('berkas');
        $input = is_string($path) && $path !== ''
            ? (is_readable($path) ? (string) file_get_contents($path) : '')
            : (string) stream_get_contents(STDIN);
        $token = self::token($input);

        if ($token === null) {
            $this->error('Masukan harus berupa token Cloudflare, atau baris CLOUDFLARE_API_TOKEN=\'…\'.');

            return self::FAILURE;
        }

        try {
            $zone = DB::transaction(function () use ($settings, $cloudflare, $token): array {
                $settings->storeToken($token, null);
                // Di dalam transaksi yang sama: pemeriksaan membaca token yang baru disimpan, dan kegagalannya
                // mengembalikan token sebelumnya.
                $zone = $cloudflare->zone();

                OperatorAudit::recordBySystem(null, 'console.dns_token.stored', 'console_setting', CloudflareSettings::TOKEN, [
                    'zone' => $zone['name'],
                ]);

                return $zone;
            });
        } catch (DnsUnavailable $e) {
            $this->error('Tidak disimpan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Token Cloudflare tersimpan dan melihat zona %s.', $zone['name']));

        return self::SUCCESS;
    }

    private static function token(string $input): ?string
    {
        if (preg_match("/^CLOUDFLARE_API_TOKEN='([^'\\r\\n]+)'\\s*$/m", $input, $match) === 1) {
            return $match[1];
        }

        $input = trim($input);

        // Token Cloudflare satu baris tanpa spasi. Apa pun yang lain lebih mungkin berkas yang salah daripada token.
        return preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $input) === 1 ? $input : null;
    }
}
