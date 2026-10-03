<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Modules\Support\LaunchableAppCatalog;

/**
 * Langkah 3 dan 4 urutan otorisasi satu query (`docs/todo/analitik/keamanan.md`): module dataset
 * terpasang untuk tenant dan berlisensi di server ini, lalu principal memegang permission baca resource
 * dataset (KA-15).
 *
 * Kesiapan module dibaca dari `LaunchableAppCatalog::readyModules()`, penentu yang sama dengan peluncur
 * app — terpasang menurut catatan pemasangannya (`core_module_installations`, tidak pernah dari
 * entitlement) dan tercantum di lisensi situs. Lisensi ikut diperiksa di sini karena jalur module biasa
 * memeriksanya di middleware konteks module, yang tidak dilewati rute Core. Permission dibaca principal
 * lewat `LaunchableAppCatalog::permissionsFor()`.
 *
 * Module yang tidak siap dijawab 404 seperti dataset yang tidak ada, bukan 403: katalog, hak, dan
 * pemasangan tiga fakta berbeda, dan pengguna tanpa module itu tidak perlu tahu datanya ada.
 */
final class DatasetAccess
{
    public function __construct(private readonly LaunchableAppCatalog $apps) {}

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
        return in_array($dataset->moduleId, $this->apps->readyModules($principal->tenantId()), true);
    }
}
