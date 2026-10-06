<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\FiscalCalendarDirectory;
use App\Platform\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Menghitung rentang token tahun fiskal (`@this_fiscal_year`, `@last_fiscal_year`, area 13) lewat
 * `FiscalCalendarDirectory`, sebelum query dikompilasi dan sebelum kunci cache dihitung. Rentang hasilnya
 * disimpan di `TimeRange::$bounds`, jadi compiler, pengisi celah, perbandingan periode, dan kunci cache membaca
 * rentang yang sama.
 *
 * Tahun fiskal milik satu perusahaan (entitas legal). Perusahaannya, berurutan:
 *
 * 1. dari saringan — saringan terkunci principal dan saringan query — pada field yang menunjuk dimensi bersama
 *    entitas legal, bila berisi tepat satu perusahaan;
 * 2. dari workspace pengguna yang sedang membuka layar ({@see UserPrincipal::workspaceLegalEntity()}). Publikasi
 *    dan embed tidak punya workspace, jadi bagi mereka perusahaannya wajib dari saringan.
 *
 * Pilihan workspace dan saringan hanya memilih perusahaan; keduanya bukan izin membaca kalender. Sebelum kalender
 * dibaca, perusahaan harus berada di jangkauan kebijakan data dataset. Tanpa perusahaan, atau dengan lebih dari
 * satu, token ditolak dengan pesan yang memintanya — bukan diganti tahun kalender diam-diam. Kalender yang belum
 * ada atau belum mencakup tanggalnya juga ditolak dengan pesan.
 *
 * "Tahun fiskal lalu" adalah tahun fiskal yang memuat hari sebelum awal tahun fiskal ini, jadi tahun fiskal yang
 * panjangnya tidak dua belas bulan tetap terbaca benar.
 */
final class FiscalYearRange
{
    public function __construct(private readonly FiscalCalendarDirectory $calendars) {}

    /**
     * Query yang sama dengan rentang tahun fiskal yang sudah dihitung, atau query apa adanya bila rentang waktunya
     * bukan tahun fiskal. Dipanggil di dalam `TenantRunner::runFor()`.
     *
     * @throws AnalyticsQueryException
     */
    public function resolve(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): AnalyticsQuery
    {
        $range = $query->timeRange;
        if ($range === null || ! RelativeRange::isFiscal($range->range)) {
            return $query;
        }

        $legalEntity = $this->legalEntity($dataset, $query, $principal);
        $year = $this->year($legalEntity, $principal->now()->toDateString(), 'hari ini');
        if ($range->range === '@last_fiscal_year') {
            $year = $this->year($legalEntity, CarbonImmutable::parse($year[0])->subDay()->toDateString(), 'tahun fiskal lalu');
        }

        return $query->withTimeRange(new TimeRange($range->range, $range->field, $year));
    }

    /** @throws AnalyticsQueryException */
    private function legalEntity(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): string
    {
        $chosen = [];
        foreach ([$principal->lockedFilters($dataset->code), $query->filters] as $filters) {
            foreach ($filters as $key => $value) {
                if (! $dataset->hasField($key) || $dataset->sharedDimension($key) !== SharedDimension::LegalEntity) {
                    continue;
                }
                if (is_array($value)) {
                    if (count($value) !== 1) {
                        throw AnalyticsQueryException::invalidQuery("filters.{$key}", 'Tahun fiskal dihitung per perusahaan. Saring tepat satu perusahaan untuk memakai periode tahun fiskal.');
                    }
                    $legalEntity = $value[0];
                } else {
                    $legalEntity = $value;
                }
                if ($legalEntity === '') {
                    throw AnalyticsQueryException::invalidQuery("filters.{$key}", 'Tahun fiskal dihitung per perusahaan. Saring tepat satu perusahaan untuk memakai periode tahun fiskal.');
                }
                $chosen[$legalEntity] = true;
            }
        }
        if (count($chosen) > 1) {
            throw AnalyticsQueryException::invalidQuery('time_range.range', 'Tahun fiskal dihitung per perusahaan. Saring tepat satu perusahaan untuk memakai periode tahun fiskal.');
        }

        $fromFilter = array_key_first($chosen);
        if ($fromFilter !== null) {
            $known = Organization::query()
                ->whereKey((string) $fromFilter)
                ->where('tenant_id', $principal->tenantId())
                ->where('classification', 'legal_entity')
                ->exists();

            if (! $known) {
                throw AnalyticsQueryException::invalidQuery('time_range.range', 'Perusahaan di saringan tidak dikenal, jadi tahun fiskalnya tidak dapat dihitung.');
            }

            return $this->inPolicyScope($dataset, $principal, (string) $fromFilter)
                ? (string) $fromFilter
                : throw self::outOfScope();
        }

        $workspace = $principal instanceof UserPrincipal ? $principal->workspaceLegalEntity() : null;
        if ($workspace !== null) {
            return $this->inPolicyScope($dataset, $principal, $workspace) ? $workspace : throw self::outOfScope();
        }

        throw RelativeRange::fiscalUnresolved();
    }

    private function inPolicyScope(CompiledDataset $dataset, AnalyticsPrincipal $principal, string $legalEntity): bool
    {
        if ($dataset->policy === null) {
            return true;
        }

        $scope = $principal->policyScope($dataset->policy['code']);
        if ($scope['all']) {
            return true;
        }

        foreach ($scope['scope_grants'] as $grant) {
            if ($grant['legal_entity_id'] === $legalEntity) {
                return true;
            }
        }

        return false;
    }

    private static function outOfScope(): AnalyticsQueryException
    {
        return AnalyticsQueryException::invalidQuery('time_range.range', 'Perusahaan ini tidak termasuk jangkauan data yang boleh Anda lihat, jadi tahun fiskalnya tidak dapat dihitung.');
    }

    /**
     * Hari pertama dan terakhir tahun fiskal yang memuat `$date`.
     *
     * @return array{0: string, 1: string}
     *
     * @throws AnalyticsQueryException
     */
    private function year(string $legalEntity, string $date, string $which): array
    {
        try {
            $year = $this->calendars->period($legalEntity, $date)['year'];
        } catch (ValidationException $e) {
            // Kalender belum ada, atau belum mencakup tanggal itu: keadaan data tenant, bukan cacat engine.
            throw AnalyticsQueryException::invalidQuery('time_range.range', 'Kalender fiskal perusahaan ini belum mencakup '.$which.'. Lengkapi kalender fiskalnya, atau pilih periode lain.');
        }

        return [$year['starts_on'], $year['ends_on']];
    }
}
