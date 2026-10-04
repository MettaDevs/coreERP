<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Actions;

use App\Platform\Analytics\Cache\QueryCache;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\FiscalYearRange;
use App\Platform\Analytics\Query\LabelResolver;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryExecutor;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Analytics\Support\QuerySlots;
use App\Platform\Modules\Contracts\TenantRunner;
use Throwable;

/**
 * Satu query analitik dari ujung ke ujung, sama untuk setiap jalur masuk: layar, widget, publikasi, feed,
 * embed. Setiap jalur membuat principal-nya sendiri lalu memanggil ini, sehingga tidak ada jalur yang
 * dapat melewati satu langkah pun:
 *
 * 1. Query disatukan ke bentuk normalnya ({@see QueryNormalizer}), lalu dataset dicari di registry; yang
 *    tidak dikenal menjadi 404.
 * 2. Module terpasang dan berlisensi, lalu permission baca resource-nya ({@see DatasetAccess}).
 * 3. Query diperiksa terhadap dataset ({@see QueryValidator}), baru sesudah hak pasti — pengguna tanpa
 *    hak tidak belajar nama kolom dari pesan galat.
 * 4. Compile dan eksekusi **di dalam `TenantRunner::runFor()`**. Model module menyaring lewat
 *    `TenantScope`, yang gagal tertutup bila tenant belum terikat, dan rute Core tidak melewati
 *    middleware konteks module yang biasanya mengikatnya.
 *
 * Token tahun fiskal (area 13) dihitung rentangnya di dalam `runFor()` juga, sebelum kunci cache dihitung
 * ({@see FiscalYearRange}): kalender fiskal ada di database tenant, dan rentangnya ikut menentukan kunci cache.
 *
 * Hasilnya ({@see ResultSet}) sudah berlabel, celah deret waktunya terisi, dan totalnya terhitung — semua
 * di dalam `runFor()`, karena resolver label dimensi bersama juga membaca data tenant.
 *
 * Area 9 memasang tiga hal di sekeliling isi closure itu, sesudah langkah 1–3, sehingga hasil cache tidak
 * pernah melewati pemeriksaan hak:
 *
 * - **Cache hasil** di database tenant ({@see QueryCache}). Pemanggil menentukan `$cacheTtl` (null = bawaan
 *   config, `0` = tanpa cache) dan `$refresh` (tombol Muat ulang: hitung ulang dan timpa). Area 6 meneruskan
 *   TTL widget dan permintaan Muat ulang dari endpoint data widget.
 * - **Jatah query bersamaan per tenant** ({@see QuerySlots}) hanya untuk perhitungan sungguhan; hasil dari
 *   cache tidak memakai jatah. Jatah habis menjadi 429 `analytics.busy`.
 * - **Log query** ({@see QueryLog}): satu baris per query, berhasil maupun ditolak, dengan `$source` jalur
 *   masuknya (`QueryLog::SOURCE_*`).
 */
final class RunQuery
{
    public function __construct(
        private readonly QueryNormalizer $normalizer,
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryValidator $validator,
        private readonly QueryCompiler $compiler,
        private readonly QueryExecutor $executor,
        private readonly TenantRunner $tenants,
        private readonly LabelResolver $labels,
        private readonly QueryCache $cache,
        private readonly QuerySlots $slots,
        private readonly QueryLog $log,
        private readonly FiscalYearRange $fiscalYears,
    ) {}

    /**
     * @param  ?int  $cacheTtl  TTL cache dalam detik; null memakai `analytics.cache.default_ttl_seconds`, `0` tanpa
     *                          cache, dan nilai di bawah 60 dinaikkan ke 60 ({@see QueryCache::ttl()})
     * @param  bool  $refresh  lewati cache dan hitung ulang (tombol Muat ulang); hasilnya menimpa cache
     * @param  string  $source  jalur masuk untuk log, salah satu `QueryLog::SOURCE_*`
     *
     * @throws AnalyticsQueryException
     */
    public function handle(AnalyticsPrincipal $principal, AnalyticsQuery $query, ?int $cacheTtl = null, bool $refresh = false, string $source = QueryLog::SOURCE_EXPLORE): ResultSet
    {
        $started = hrtime(true);
        $query = $this->normalizer->normalize($query);
        $dataset = null;

        try {
            $dataset = $this->datasets->find($query->dataset) ?? throw AnalyticsQueryException::datasetUnknown();
            $this->access->authorize($principal, $dataset);
            $this->validator->validate($dataset, $query, $principal);

            $result = $this->tenants->runFor($principal->tenantId(), function () use ($dataset, $query, $principal, $cacheTtl, $refresh): ResultSet {
                $resolved = $this->fiscalYears->resolve($dataset, $query, $principal);

                return $this->cache->remember(
                    $dataset, $resolved, $principal, $this->cache->ttl($cacheTtl), $refresh,
                    fn (): ResultSet => $this->slots->run($principal->tenantId(), $principal->timeoutMs(), fn (): ResultSet => $this->compute($dataset, $resolved, $principal)),
                );
            });
        } catch (Throwable $e) {
            $this->log->failed($principal, $source, $query, $dataset, $e, self::elapsedMs($started));

            throw $e;
        }

        $this->log->succeeded($principal, $source, $query, $dataset, $result, self::elapsedMs($started));

        return $result;
    }

    /** Compile, eksekusi, dan penyusunan hasil; dijalankan di dalam `runFor()` sambil memegang satu jatah tenant. */
    private function compute(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): ResultSet
    {
        $compiled = $this->compiler->compile($dataset, $query, $principal);
        $started = hrtime(true);
        $executed = $this->executor->run($compiled, $principal->timeoutMs());

        return ResultSet::from($dataset, $query, $compiled, $executed, $principal, self::elapsedMs($started), $this->labels);
    }

    private static function elapsedMs(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }
}
