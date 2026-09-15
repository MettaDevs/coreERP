<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Carbon\CarbonInterface;
use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Dns\DnsUnavailable;
use ControlPlane\Models\Environment;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Models\SiteRelease;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Panel "Server klien" di halaman lingkungan produksi: menyiapkan situsnya, mengubah setelannya, dan
 * membuat perintah pasang. Rancangannya `docs/todo/pasang-satu-perintah`, PS-02 dan PS-03.
 *
 * ## Satu isian wajib: alamat server
 *
 * Semua yang lain sudah diketahui sistem: tenant, owner-nya, app yang dibeli, rilis terbaru, dan alamat
 * aplikasinya (`<tenant>.<domain dasar>`, lihat `Site::appUrl()`). Formulir "Situs baru" yang lama menanyakan
 * nama dan edisi; keduanya kini diturunkan, jadi tidak ada yang dapat salah ketik.
 *
 * Alamat server (IP VPS) boleh kosong saat menyiapkan, tetapi wajib saat perintah pasang dibuat. Sejak
 * 15 September 2026 perintah pasang memastikan record DNS alamat aplikasi menunjuk ke mesin itu (`SiteDns`),
 * karena proxy agen di server klien mengambil sertifikat Let's Encrypt untuk alamat tersebut — tanpa record,
 * sertifikatnya tidak pernah terbit dan tidak ada yang dapat masuk.
 *
 * ## Kata sandi sementara tidak pernah tersimpan
 *
 * Yang masuk database hanya hash bcrypt-nya, di parameter operasi `install`, dan hash itu dibuang begitu
 * operasinya ditutup (`SiteOperations::closingColumns`). Kata sandinya sendiri hanya dipulangkan ke
 * pemanggil, untuk ditunjukkan sekali lewat flash session — tidak ke jejak audit, tidak ke log.
 */
final class ClientServerSetup
{
    /** Zona waktu situs baru, sama dengan bawaan kolom `sites.timezone`. */
    public const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    private const SITE_NAME_SUFFIX = ' — Produksi';

    public function __construct(
        private readonly EnrollmentTokens $tokens,
        private readonly EntitlementsFromCore $entitlements,
        private readonly SiteDns $dns,
    ) {}

    /**
     * Mencatat situs untuk lingkungan produksi di server klien.
     *
     * @param  array{server_address?: ?string, update_window_start?: ?string, update_window_end?: ?string}  $settings
     */
    public function prepare(Request $request, Environment $environment, array $settings): Site
    {
        $this->assertClientServer($environment);

        if (Site::query()->where('environment_id', $environment->id)->exists()) {
            throw new SiteRejected('site_exists', 'Lingkungan ini sudah punya server klien.');
        }

        $name = self::siteName((string) ($environment->tenant->name ?? ''));

        /*
         * Dua klik "Siapkan server klien" yang berlomba sama-sama lolos pemeriksaan di atas; yang
         * memutuskan indeks `sites_satu_per_lingkungan`. Penolakannya ditangkap di luar
         * `DB::transaction`, yang di dalam transaksi pemanggil menjadi SAVEPOINT: PostgreSQL membatalkan
         * seluruh transaksi pada pelanggaran unik, dan tanpa savepoint transaksi pemanggil tidak dapat
         * dipakai lagi sesudahnya.
         */
        try {
            return DB::transaction(function () use ($request, $environment, $settings, $name): Site {
                $site = Site::query()->create([
                    'tenant_id' => $environment->tenant_id,
                    'environment_id' => $environment->id,
                    'name' => $name,
                    'profile' => 'managed_on_prem',
                    'edition' => Site::SINGLE_IMAGE_EDITION,
                    'timezone' => self::DEFAULT_TIMEZONE,
                    'server_address' => $settings['server_address'] ?? null,
                    'update_window_start' => $settings['update_window_start'] ?? null,
                    'update_window_end' => $settings['update_window_end'] ?? null,
                    'created_by' => $request->user()?->getAuthIdentifier(),
                ]);

                OperatorAudit::record($request, 'site.created', 'site', $site->id, [
                    'tenant_id' => $site->tenant_id,
                    'environment_id' => $environment->id,
                    'edition' => $site->edition,
                    'server_address' => $site->server_address,
                ]);

                return $site;
            });
        } catch (UniqueConstraintViolationException $conflict) {
            if (str_contains($conflict->getMessage(), 'sites_satu_per_lingkungan')) {
                throw new SiteRejected('site_exists', 'Lingkungan ini sudah punya server klien.');
            }

            if (str_contains($conflict->getMessage(), 'sites_tenant_id_name_unique')) {
                throw new SiteRejected('site_name_taken', sprintf('Tenant ini sudah punya situs bernama "%s" dari pendaftaran lama. Ganti nama situs itu lebih dulu.', $name));
            }

            throw $conflict;
        }
    }

    /**
     * Alamat server dan jendela pembaruan — dari panel di halaman lingkungan maupun dari halaman rincian
     * server klien. Isiannya dirapikan dan diperiksa `ServerSettings`.
     *
     * Bila record DNS situs ini sudah pernah ditulis dan alamat server berubah, record itu ikut diperbarui
     * sesudah setelannya tersimpan. Cloudflare yang menolak tidak membatalkan setelan: kalimatnya dipulangkan
     * untuk ditampilkan, dan layar menandai record yang belum mengikuti sampai "Sinkronkan DNS" berhasil.
     *
     * @param  array{server_address?: ?string, update_window_start?: ?string, update_window_end?: ?string}  $settings
     * @return ?string peringatan DNS, atau null bila tidak ada
     */
    public function updateSettings(Request $request, Site $site, array $settings): ?string
    {
        if ($site->revoked()) {
            throw new SiteRejected('site_revoked', 'Situs ini sudah dicabut.');
        }

        $changedAddress = DB::transaction(function () use ($request, $site, $settings): bool {
            $before = self::settingsOf($site);

            $site->forceFill([
                'server_address' => $settings['server_address'] ?? null,
                'update_window_start' => $settings['update_window_start'] ?? null,
                'update_window_end' => $settings['update_window_end'] ?? null,
            ])->save();

            OperatorAudit::record($request, 'site.settings.updated', 'site', $site->id, [
                'before' => $before,
                'after' => self::settingsOf($site),
            ]);

            return $before['server_address'] !== $site->server_address;
        });

        if (! $changedAddress || $site->dns_record_id === null) {
            return null;
        }

        if ($site->server_address === null) {
            return 'Alamat server dikosongkan, tetapi record DNS lama tetap menunjuk alamat sebelumnya sampai alamat baru dicatat atau server klien dicabut.';
        }

        try {
            $this->dns->ensure($request, $site);
        } catch (DnsUnavailable $e) {
            return 'Setelan disimpan, tetapi record DNS belum mengikuti alamat baru: '.$e->getMessage();
        }

        return null;
    }

    /**
     * "Sinkronkan DNS": menulis ulang record alamat aplikasi ke alamat server yang tercatat sekarang.
     */
    public function syncDns(Request $request, Site $site): void
    {
        if ($site->revoked()) {
            throw new SiteRejected('site_revoked', 'Situs ini sudah dicabut.');
        }

        try {
            $this->dns->ensure($request, $site);
        } catch (DnsUnavailable $e) {
            throw new SiteRejected('dns_unavailable', $e->getMessage());
        }
    }

    /**
     * Membuat perintah pasang: token pendaftaran baru dan operasi `install` yang menunggu agen.
     *
     * @return array{command: string, expires_at: CarbonInterface, email: string, password: string, release: ?string}
     */
    public function issueInstallCommand(Request $request, Environment $environment): array
    {
        $this->assertClientServer($environment);

        $site = Site::query()->where('environment_id', $environment->id)->first();

        if (! $site instanceof Site) {
            throw new SiteRejected('site_missing', 'Siapkan server klien lebih dulu.');
        }

        // Diperiksa sebelum Core dipanggil, dan sekali lagi di bawah kunci baris situs.
        $this->assertInstallable($site);

        $site->setRelation('environment', $environment);
        $appUrl = self::installableAppUrl($site);

        if ($site->server_address === null) {
            throw new SiteRejected('server_address_missing', sprintf(
                'Catat alamat server (IP VPS) lebih dulu di Setelan server. Record DNS %s dibuat menunjuk ke alamat itu, dan tanpanya proxy di server klien tidak pernah mendapat sertifikat.',
                (string) parse_url($appUrl, PHP_URL_HOST),
            ));
        }

        $tenant = $environment->tenant;

        if ($tenant === null) {
            throw new SiteRejected('tenant_missing', 'Tenant lingkungan ini tidak ditemukan.');
        }

        $owner = $this->owner($tenant->id);

        if ($owner === null) {
            throw new SiteRejected('owner_missing', 'Tenant ini belum punya owner aktif, jadi tidak ada admin pertama yang dapat dilahirkan di server klien.');
        }

        // Daftar app dibaca dari Core, bukan disusun di sini — alasannya di `EntitlementsFromCore`. Tanpa
        // daftar itu tidak ada yang dibuat: pemasangan dengan app tebakan melahirkan tenant yang tidak
        // dapat membuka app yang dibayarnya, atau dapat membuka yang tidak.
        try {
            $apps = $this->entitlements->appsFor($tenant->id);
        } catch (EntitlementsUnavailable $e) {
            throw new SiteRejected('entitlements_unavailable', 'Daftar app tenant tidak terbaca dari Core, jadi perintah pasang tidak dibuat. '.$e->getMessage());
        }

        // Sebelum token dan operasi lahir, di luar transaksi karena ia panggilan jaringan. Perintah pasang yang
        // dibuat tanpa record DNS menghasilkan server yang terpasang tetapi tidak dapat dibuka siapa pun.
        try {
            $this->dns->ensure($request, $site);
        } catch (DnsUnavailable $e) {
            throw new SiteRejected('dns_unavailable', 'Perintah pasang tidak dibuat karena record DNS alamat aplikasi belum dapat dipastikan. '.$e->getMessage());
        }

        $release = self::newestRelease($site->edition);
        $password = TemporaryPassword::generate();

        // Bcrypt, dengan biaya `BCRYPT_ROUNDS` konsol ini. Driver-nya disebut tegas, bukan bawaan
        // `Hash::make`: `tenant:bootstrap-site` di server klien hanya menerima awalan `$2y$`, dan konsol
        // yang kelak berganti bawaan ke argon akan melahirkan owner yang tidak dapat masuk. Core juga
        // menolak biaya yang lebih tinggi dari setelannya sendiri; keduanya memakai bawaan 12.
        $hash = Hash::driver('bcrypt')->make($password);

        return DB::transaction(function () use ($request, $site, $tenant, $owner, $apps, $release, $password, $hash, $appUrl): array {
            $locked = Site::query()->lockForUpdate()->findOrFail($site->id);
            $this->assertInstallable($locked);

            $issued = $this->tokens->issue($locked, $request->user()?->getAuthIdentifier());

            // Perintah pasang sebelumnya yang belum diambil agen digantikan. Membiarkannya berarti dua
            // hash kata sandi untuk owner yang sama, dan indeks satu-permintaan-per-jenis menolak yang baru.
            $cancelled = SiteOperation::query()
                ->where('site_id', $locked->id)
                ->where('operation', 'install')
                ->where('status', 'requested')
                ->update(SiteOperations::closingColumns('cancelled'));

            $operation = SiteOperation::query()->create([
                'site_id' => $locked->id,
                'operation' => 'install',
                'parameters' => [
                    // `edition` ikut karena agen mengambil berkas rilis lewat jalur yang sama dengan
                    // `upgrade` — `/releases/{edition}/{release}/files/...`. Dibuang bersama kolom edisi.
                    'edition' => $locked->edition,
                    'release' => $release,
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'app_ids' => $apps,
                    'admin_name' => $owner['name'],
                    'admin_email' => $owner['email'],
                    SiteOperation::PASSWORD_HASH_PARAMETER => $hash,
                    // Ditulis agen ke `APP_URL` dan nama host proxy HTTPS-nya sebelum stack dinyalakan.
                    // Tanpa ini `pasang.sh` hanya tahu `hostname -f` mesin itu, yang bukan alamat tenant.
                    'app_url' => $appUrl,
                ],
                'status' => 'requested',
                'requested_by' => $request->user()?->getAuthIdentifier(),
                'requested_at' => now(),
                'expires_at' => now()->addDays((int) config('sites.request_expiry_days')),
            ]);

            // Tanpa kata sandi, hash, maupun token: yang dicatat keberadaannya dan apa yang akan dipasang.
            OperatorAudit::record($request, 'site.install_command.issued', 'site', $locked->id, [
                'operation_id' => $operation->id,
                'release' => $release,
                'app_ids' => $apps,
                'app_url' => $appUrl,
                'token_expires_at' => $issued['expires_at']->toIso8601String(),
                'cancelled_operations' => $cancelled,
            ]);

            return [
                'command' => EnrollmentTokens::installCommand($issued['token']),
                'expires_at' => $issued['expires_at'],
                'email' => $owner['email'],
                'password' => $password,
                'release' => $release,
            ];
        });
    }

    /**
     * Rilis terdaftar terbaru untuk satu edisi, dibandingkan bertitik — `0.10.0` di atas `0.9.3`.
     */
    public static function newestRelease(string $edition): ?string
    {
        $newest = SiteRelease::query()
            ->where('edition', $edition)
            ->pluck('release')
            ->reduce(fn (?string $carry, string $release): string => $carry === null || SiteRelease::compare($release, $carry) > 0 ? $release : $carry);

        return is_string($newest) ? $newest : null;
    }

    /**
     * `<tenant> — Produksi`, dipotong supaya muat di `sites.name` (100 karakter).
     */
    public static function siteName(string $tenantName): string
    {
        $room = 100 - mb_strlen(self::SITE_NAME_SUFFIX);

        return rtrim(mb_substr(trim($tenantName), 0, $room)).self::SITE_NAME_SUFFIX;
    }

    /**
     * Alamat aplikasi dalam bentuk yang diterima agen: `https://<host>` tanpa port dan jalur.
     *
     * Bentuk lain lahir dari setelan konsol yang bukan untuk server klien — domain dasar kosong, skema `http`, atau
     * port pada pengembangan lokal — dan agen menolaknya. Ditolak di sini lebih dulu, dengan sebabnya.
     */
    private static function installableAppUrl(Site $site): string
    {
        $url = $site->appUrl();

        if (! is_string($url) || preg_match('#^https://(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$#', $url) !== 1) {
            throw new SiteRejected('app_url_unusable', sprintf(
                'Alamat aplikasi server klien tidak dapat dibentuk (%s). Konsol ini butuh domain dasar (COREERP_BASE_DOMAIN) dengan skema https tanpa port.',
                $url ?? 'kosong',
            ));
        }

        return $url;
    }

    /** @return array{server_address: ?string, update_window: array{start: string, end: string, timezone: string}|null} */
    private static function settingsOf(Site $site): array
    {
        return [
            'server_address' => $site->server_address,
            'update_window' => $site->updateWindow(),
        ];
    }

    private function assertClientServer(Environment $environment): void
    {
        if (! $environment->runsOnClientServer()) {
            throw new SiteRejected('not_client_server', 'Hanya lingkungan produksi yang berjalan di server klien yang punya server klien.');
        }
    }

    private function assertInstallable(Site $site): void
    {
        if ($site->revoked()) {
            throw new SiteRejected('site_revoked', 'Situs ini sudah dicabut, jadi tidak dapat dipasang lagi.');
        }

        $statuses = SiteOperation::query()
            ->where('site_id', $site->id)
            ->where('operation', 'install')
            ->whereIn('status', ['succeeded', 'running'])
            ->pluck('status')
            ->all();

        // Pemasangan kedua di atas yang sudah berhasil melahirkan owner baru dengan kata sandi yang tidak
        // pernah berlaku — `tenant:bootstrap-site` menjawab "sudah ada" untuk owner yang sama. Yang
        // dibutuhkan server yang sudah jalan adalah operasi di halaman situsnya.
        if (in_array('succeeded', $statuses, true)) {
            throw new SiteRejected('already_installed', 'Server klien ini sudah terpasang. Pembaruan, cadangan, dan lisensinya diminta dari halaman situs, bagian "Minta operasi".');
        }

        // Token baru di tengah pemasangan membuka jalan pendaftaran kedua bagi server yang sedang dipasang.
        if (in_array('running', $statuses, true)) {
            throw new SiteRejected('install_running', 'Pemasangan sedang berjalan di server klien. Tunggu hasilnya sebelum membuat perintah baru.');
        }
    }

    /**
     * Owner aktif tertua tenant ini — admin pertama yang dilahirkan di server klien.
     *
     * @return array{name: string, email: string}|null
     */
    private function owner(string $tenantId): ?array
    {
        $row = DB::table('tenant_memberships')
            ->join('users', 'users.id', '=', 'tenant_memberships.user_id')
            ->where('tenant_memberships.tenant_id', $tenantId)
            ->where('tenant_memberships.system_role', 'owner')
            ->where('tenant_memberships.status', 'active')
            ->orderBy('tenant_memberships.created_at')
            ->orderBy('tenant_memberships.id')
            ->first(['users.name', 'users.email']);

        if ($row === null || ! is_string($row->name ?? null) || ! is_string($row->email ?? null)) {
            return null;
        }

        return ['name' => $row->name, 'email' => $row->email];
    }
}
