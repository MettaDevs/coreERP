<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Actions;

use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryExecutor;
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
 * 1. Dataset dicari di registry; yang tidak dikenal menjadi 404.
 * 2. Module terpasang dan berlisensi, lalu permission baca resource-nya ({@see DatasetAccess}).
 * 3. Query diperiksa terhadap dataset ({@see QueryValidator}), baru sesudah hak pasti — pengguna tanpa
 *    hak tidak belajar nama kolom dari pesan galat.
 * 4. Compile dan eksekusi **di dalam `TenantRunner::runFor()`**. Model module menyaring lewat
 *    `TenantScope`, yang gagal tertutup bila tenant belum terikat, dan rute Core tidak melewati
 *    middleware konteks module yang biasanya mengikatnya.
 *
 * Tempat area 9 memasang cache dan log query, dan area 6 memanggilnya dari data widget.
 */
final class RunQuery
{
    public function __construct(
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryValidator $validator,
        private readonly QueryCompiler $compiler,
        private readonly QueryExecutor $executor,
        private readonly TenantRunner $tenants,
    ) {}

    /** @throws AnalyticsQueryException */
    public function handle(AnalyticsPrincipal $principal, AnalyticsQuery $query): ResultSet
    {
        $dataset = $this->datasets->find($query->dataset) ?? throw AnalyticsQueryException::datasetUnknown();
        $this->access->authorize($principal, $dataset);
        $this->validator->validate($dataset, $query, $principal);

        return $this->tenants->runFor($principal->tenantId(), function () use ($dataset, $query, $principal): ResultSet {
            $compiled = $this->compiler->compile($dataset, $query, $principal);
            $started = hrtime(true);
            $executed = $this->executor->run($compiled, $principal->timeoutMs());

            return ResultSet::from($dataset, $query, $compiled, $executed, $principal, intdiv(hrtime(true) - $started, 1_000_000));
        });
    }
}
