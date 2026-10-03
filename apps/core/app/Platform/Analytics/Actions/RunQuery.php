<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Actions;

use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\LabelResolver;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryExecutor;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Modules\Contracts\TenantRunner;

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
 * Hasilnya ({@see ResultSet}) sudah berlabel, celah deret waktunya terisi, dan totalnya terhitung — semua
 * di dalam `runFor()`, karena resolver label dimensi bersama juga membaca data tenant.
 *
 * Tempat area 9 memasang cache dan log query: di sekeliling isi closure `runFor()` (compile, eksekusi,
 * dan penyusunan hasil), sesudah langkah 1–3, sehingga hasil cache tidak pernah melewati pemeriksaan hak.
 * Area 6 memanggilnya dari data widget.
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
    ) {}

    /** @throws AnalyticsQueryException */
    public function handle(AnalyticsPrincipal $principal, AnalyticsQuery $query): ResultSet
    {
        $query = $this->normalizer->normalize($query);
        $dataset = $this->datasets->find($query->dataset) ?? throw AnalyticsQueryException::datasetUnknown();
        $this->access->authorize($principal, $dataset);
        $this->validator->validate($dataset, $query, $principal);

        return $this->tenants->runFor($principal->tenantId(), function () use ($dataset, $query, $principal): ResultSet {
            $compiled = $this->compiler->compile($dataset, $query, $principal);
            $started = hrtime(true);
            $executed = $this->executor->run($compiled, $principal->timeoutMs());

            return ResultSet::from($dataset, $query, $compiled, $executed, $principal, intdiv(hrtime(true) - $started, 1_000_000), $this->labels);
        });
    }
}
