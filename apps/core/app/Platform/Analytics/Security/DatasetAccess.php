<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\License\Support\SiteLicense;
use App\Platform\Modules\Models\ModuleInstallation;

/**
 * Langkah 3 dan 4 urutan otorisasi satu query (`docs/todo/analitik/keamanan.md`): module dataset
 * terpasang untuk tenant dan berlisensi di server ini, lalu principal memegang permission baca resource
 * dataset (KA-15).
 *
 * Module yang tidak terpasang dijawab 404 seperti dataset yang tidak ada, bukan 403: katalog, hak, dan
 * pemasangan tiga fakta berbeda, dan "terpasang" hanya sah dari catatan pemasangannya
 * (`core_module_installations`), tidak pernah dari entitlement. Lisensi situs ikut diperiksa di sini
 * karena jalur module biasa memeriksanya di middleware konteks module, yang tidak dilewati rute Core.
 *
 * Isi kerangka berjalan (area 0); area 4 melengkapinya.
 */
final class DatasetAccess
{
    public function __construct(private readonly SiteLicense $license) {}

    /** @throws AnalyticsQueryException */
    public function authorize(AnalyticsPrincipal $principal, CompiledDataset $dataset): void
    {
        if (! $this->available($principal, $dataset)) {
            throw AnalyticsQueryException::datasetUnknown();
        }

        if (! $principal->holdsPermission($dataset->moduleId, $dataset->permission)) {
            throw AnalyticsQueryException::datasetForbidden();
        }
    }

    /** Boleh dibaca principal ini, untuk katalog dan layar yang memilih dataset. */
    public function allows(AnalyticsPrincipal $principal, CompiledDataset $dataset): bool
    {
        return $this->available($principal, $dataset)
            && $principal->holdsPermission($dataset->moduleId, $dataset->permission);
    }

    private function available(AnalyticsPrincipal $principal, CompiledDataset $dataset): bool
    {
        return $this->license->allowsApp($dataset->moduleId)
            && ModuleInstallation::query()
                ->where('tenant_id', $principal->tenantId())
                ->where('module_id', $dataset->moduleId)
                ->where('status', ModuleInstallation::STATUS_INSTALLED)
                ->exists();
    }
}
