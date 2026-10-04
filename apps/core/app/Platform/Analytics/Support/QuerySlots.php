<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Support;

use App\Platform\Analytics\Query\AnalyticsQueryException;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Batas query analitik yang dihitung bersamaan per tenant (area 9, `docs/todo/analitik/kinerja-dan-uji-beban.md`
 * bagian *Batas*). Server on-prem menjalankan Core dengan jumlah proses PHP terbatas: dua puluh query yang
 * masing-masing sah delapan detik dapat menahan semuanya, dan layar kasir menunggu. Jatah ini menjaga proses
 * tetap tersedia untuk layar transaksi.
 *
 * Jatahnya kunci bernomor `analytics:slot:{tenant}:{0..n-1}` di store kunci Laravel. Nama kunci hanya memuat
 * id tenant, tidak pernah data, jadi tidak masalah bahwa store itu tinggal di database pusat — yang dilarang
 * di sana hanya hasil query (KA-18). Hasil dari cache tidak memakai jatah: yang dijaga proses dan koneksi yang
 * tertahan menghitung, bukan jumlah permintaan.
 *
 * Setiap kunci punya **masa berlaku** ({@see self::leaseSeconds()}), dan dilepas di `finally`. Proses yang mati
 * keras tidak sempat melepasnya; masa berlaku itulah yang mengembalikan jatahnya. Tanpa masa berlaku, satu
 * proses yang mati mengurangi jatah tenant itu selamanya.
 *
 * Tidak menyimpan apa pun di properti: satu pekerja FrankenPHP melayani banyak tenant berturut-turut.
 */
final class QuerySlots
{
    /**
     * Detik yang disarankan sebelum mencoba lagi. Query layar biasanya selesai di bawah satu detik (gate latensi
     * area 10); dua detik cukup untuk satu jatah lepas tanpa membuat layar mengulang terus-menerus.
     */
    public const RETRY_AFTER_SECONDS = 2;

    /**
     * Menjalankan `$compute` sambil memegang satu jatah tenant, atau menolak 429 `analytics.busy` bila semua
     * jatah sedang dipakai.
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     *
     * @throws AnalyticsQueryException
     */
    public function run(string $tenantId, int $timeoutMs, Closure $compute): mixed
    {
        $slots = max(1, config()->integer('analytics.limits.concurrent_per_tenant', 4));
        $lease = self::leaseSeconds($timeoutMs);
        // Mulai dari jatah acak supaya permintaan bersamaan tidak semuanya berebut jatah 0 lebih dulu.
        $first = random_int(0, $slots - 1);

        for ($i = 0; $i < $slots; $i++) {
            $lock = Cache::lock(self::name($tenantId, ($first + $i) % $slots), $lease);
            if (! $lock->get()) {
                continue;
            }

            try {
                return $compute();
            } finally {
                $lock->release();
            }
        }

        throw AnalyticsQueryException::busy(self::RETRY_AFTER_SECONDS);
    }

    /**
     * Masa berlaku jatah dan kunci hitung cache. Satu query menjalankan sampai dua pernyataan — hasil dan total —
     * yang masing-masing dibatasi `statement_timeout`, jadi dua kali batas waktu, ditambah lima detik untuk
     * menyusun query dan labelnya. Lebih pendek dari itu, kunci query berat kedaluwarsa saat ia masih berjalan
     * dan jatahnya terpakai ganda tepat ketika server paling sibuk.
     */
    public static function leaseSeconds(int $timeoutMs): int
    {
        return 2 * intdiv(max(0, $timeoutMs) + 999, 1000) + 5;
    }

    /** Nama kunci satu jatah. Hanya id tenant dan nomor jatah. */
    public static function name(string $tenantId, int $slot): string
    {
        return 'analytics:slot:'.$tenantId.':'.$slot;
    }
}
