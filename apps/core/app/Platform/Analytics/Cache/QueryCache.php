<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Cache;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Models\QueryCacheEntry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\ScopeFingerprint;
use App\Platform\Analytics\Support\QuerySlots;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Cache hasil query analitik di tabel `analytics_query_cache` **database tenant** (area 9, KA-18). Cache store
 * Laravel tidak dipakai untuk hasil: `EnvironmentConnection::pins()` mengarahkannya ke database pusat, dan angka
 * tenant yang punya database sendiri tidak boleh tersalin ke sana.
 *
 * Isolasi cache adalah gate keamanan. Kuncinya ({@see self::key()}) memuat semua yang menentukan isi hasil:
 * tenant, kode dan hash definisi dataset, query dalam bentuk normal, sidik jari jangkauan principal
 * ({@see ScopeFingerprint}: hibah, hak data pribadi, saringan terkunci), zona waktu, tanggal hari ini (token
 * relatif seperti `@this_month` berubah arti setiap hari), dan batas baris (hasil terpotong berbeda per batas).
 * Dua pengguna dengan jangkauan sama berbagi hasil; jangkauan berbeda tidak pernah. Kunci cache baru dihitung
 * **sesudah** pemeriksaan hak di `RunQuery`, jadi hasil cache tidak pernah melewati pemeriksaan itu.
 *
 * Perilakunya (`docs/todo/analitik/kinerja-dan-uji-beban.md` bagian *Cache*):
 *
 * - **TTL per pemanggil** ({@see self::ttl()}): bawaan `analytics.cache.default_ttl_seconds`, minimum 60 detik,
 *   `0` tanpa cache. Pembaca tidak menerima hasil yang lebih tua dari TTL-nya sendiri walau baris itu ditulis
 *   pemanggil lain dengan TTL lebih panjang.
 * - **Muat ulang** (`$refresh`) menghitung ulang tanpa membaca cache, lalu menimpa hasilnya.
 * - **Serbuan dicegah** dengan kunci `analytics:compute:{tenant}:{kunci}` selama perhitungan: pemanggil kedua
 *   menunggu sebentar sambil membaca ulang cache, lalu memakai hasil pemanggil pertama.
 * - **Hasil dikompres** gzip; yang sesudah dikompres melebihi `analytics.cache.max_payload_kb` tidak disimpan.
 * - **Pembersihan saat baca dan tulis**: baris kedaluwarsa yang terbaca dihapus, dan setiap penulisan menghapus
 *   paling banyak 100 baris kedaluwarsa lain milik tenant yang sama. Pembersihan terjadwal per environment
 *   menunggu perintah terjadwal dapat berjalan per environment.
 *
 * Tidak menyimpan apa pun di properti: satu pekerja FrankenPHP melayani banyak tenant berturut-turut.
 */
final class QueryCache
{
    /**
     * Versi bentuk kunci dan isi cache. Naikkan bila bentuk hasil yang disimpan berubah, supaya isi lama tidak terbaca.
     * Versi 2 (area 13): kolom hasil membawa `derived_from` dan `derivation`.
     */
    public const VERSION = 2;

    /** TTL terpendek yang disimpan; TTL di antara 1 dan 59 dinaikkan ke sini. */
    public const MIN_TTL_SECONDS = 60;

    /**
     * TTL penjelajah: pratinjau yang berubah tiap klik tidak ditahan lama, tetapi hasil yang sama persis dalam satu
     * menit tidak dihitung ulang.
     */
    public const EXPLORE_TTL_SECONDS = 60;

    /** Baris kedaluwarsa milik tenant yang dihapus setiap kali tenant itu menulis cache. */
    private const PURGE_BATCH = 100;

    /** Jeda antar pembacaan ulang cache selama menunggu perhitungan pemanggil lain. */
    private const WAIT_STEP_MS = 200;

    /**
     * Kunci cache satu query untuk satu principal: sha256 heksadesimal 64 karakter.
     */
    public function key(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): string
    {
        return hash('sha256', json_encode([
            'v' => self::VERSION,
            'tenant' => $principal->tenantId(),
            'dataset' => $dataset->code,
            // Definisi atau skema yang berubah saat rilis membuat hasil lama tidak terbaca lagi.
            'definition' => $dataset->hash(),
            'query' => $query->normalized(),
            'scope' => $principal->fingerprint($dataset),
            'timezone' => $principal->timezone(),
            'today' => $principal->now()->toDateString(),
            'row_limit' => $principal->rowLimit(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * TTL efektif dalam detik: null memakai bawaan config, `0` atau kurang berarti tanpa cache, dan nilai di
     * bawah {@see self::MIN_TTL_SECONDS} dinaikkan ke sana.
     */
    public function ttl(?int $requested): int
    {
        $ttl = $requested ?? config()->integer('analytics.cache.default_ttl_seconds', 300);

        return $ttl <= 0 ? 0 : max(self::MIN_TTL_SECONDS, $ttl);
    }

    /**
     * Hasil dari cache bila ada yang cukup segar, selain itu hasil `$compute` yang lalu disimpan. Dipanggil di
     * dalam `TenantRunner::runFor()`, sesudah pemeriksaan hak.
     *
     * @param  int  $ttl  TTL efektif dari {@see self::ttl()}; `0` melewati cache sepenuhnya
     * @param  bool  $refresh  hitung ulang tanpa membaca cache (tombol Muat ulang), lalu timpa hasilnya
     * @param  Closure(): ResultSet  $compute
     */
    public function remember(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, int $ttl, bool $refresh, Closure $compute): ResultSet
    {
        if ($ttl <= 0) {
            return $compute();
        }

        $tenant = $principal->tenantId();
        $key = $this->key($dataset, $query, $principal);

        if ($refresh) {
            return $this->store($dataset, $tenant, $key, $ttl, $compute());
        }

        $hit = $this->read($tenant, $key, $ttl);
        if ($hit !== null) {
            return $hit;
        }

        $lock = Cache::lock('analytics:compute:'.$tenant.':'.$key, QuerySlots::leaseSeconds($principal->timeoutMs()));
        $deadline = now()->addMilliseconds($principal->timeoutMs());
        $owned = $lock->get();

        try {
            $waited = false;
            while (! $owned && now()->lessThan($deadline)) {
                $waited = true;
                Sleep::for(self::WAIT_STEP_MS)->milliseconds();
                $hit = $this->read($tenant, $key, $ttl);
                if ($hit !== null) {
                    return $hit;
                }
                $owned = $lock->get();
            }

            // Pemanggil pertama mungkin selesai di antara pembacaan terakhir dan kunci yang baru lepas.
            if ($waited && $owned) {
                $hit = $this->read($tenant, $key, $ttl);
                if ($hit !== null) {
                    return $hit;
                }
            }

            // Tanpa kunci sesudah batas tunggu, pemanggil ini menghitung sendiri: menunggu lebih lama hanya menahan
            // proses PHP, dan perhitungan pemanggil pertama mungkin sudah gagal.
            return $this->store($dataset, $tenant, $key, $ttl, $compute());
        } finally {
            if ($owned) {
                $lock->release();
            }
        }
    }

    /** Hasil cache yang belum kedaluwarsa dan tidak lebih tua dari `$ttl`, atau null. Baris kedaluwarsa dihapus. */
    private function read(string $tenant, string $key, int $ttl): ?ResultSet
    {
        $entry = QueryCacheEntry::query()
            ->where('tenant_id', $tenant)
            ->where('cache_key', $key)
            ->first(['id', 'payload', 'expires_at', 'updated_at']);

        if ($entry === null) {
            return null;
        }

        if ($entry->expires_at->lessThanOrEqualTo(now())) {
            // Syarat kedaluwarsa diulang supaya baris yang baru saja ditimpa pemanggil lain tidak ikut terhapus.
            QueryCacheEntry::query()->whereKey($entry->id)->where('expires_at', '<=', now())->delete();

            return null;
        }

        if ($entry->updated_at === null || $entry->updated_at->lessThan(now()->subSeconds($ttl))) {
            return null;
        }

        return $this->decode($entry->payload);
    }

    /** Menyimpan hasil, lalu membersihkan baris kedaluwarsa tenant itu. Hasil yang terlalu besar tidak disimpan. */
    private function store(CompiledDataset $dataset, string $tenant, string $key, int $ttl, ResultSet $result): ResultSet
    {
        $bytes = gzencode(json_encode($result->toCache(), JSON_THROW_ON_ERROR), 6);
        if ($bytes === false || strlen($bytes) > config()->integer('analytics.cache.max_payload_kb', 512) * 1024) {
            return $result;
        }

        // `bytea` lewat binding biasa ditolak PostgreSQL sebagai teks UTF-8 yang tidak sah; aliran (`PARAM_LOB`)
        // dikirim apa adanya.
        $payload = fopen('php://memory', 'r+b');
        if ($payload === false) {
            return $result;
        }
        fwrite($payload, $bytes);
        rewind($payload);

        try {
            QueryCacheEntry::query()->upsert([[
                'tenant_id' => $tenant,
                'cache_key' => $key,
                'dataset_code' => $dataset->code,
                'payload' => $payload,
                'size_bytes' => strlen($bytes),
                'expires_at' => now()->addSeconds($ttl),
            ]], ['tenant_id', 'cache_key'], ['dataset_code', 'payload', 'size_bytes', 'expires_at']);
        } finally {
            fclose($payload);
        }

        QueryCacheEntry::query()
            ->whereIn('id', QueryCacheEntry::query()->select('id')->where('tenant_id', $tenant)->where('expires_at', '<=', now())->limit(self::PURGE_BATCH))
            ->delete();

        return $result;
    }

    /** @param  resource|string  $payload  `bytea` dibaca PDO PostgreSQL sebagai aliran */
    private function decode(mixed $payload): ?ResultSet
    {
        $bytes = is_resource($payload) ? stream_get_contents($payload) : $payload;
        $json = is_string($bytes) ? @gzdecode($bytes) : false;
        $data = is_string($json) ? json_decode($json, true) : null;

        // Isi yang tidak terbaca diperlakukan sebagai tidak ada di cache: hasilnya dihitung dan ditimpa.
        return is_array($data) ? ResultSet::fromCache($data) : null;
    }
}
