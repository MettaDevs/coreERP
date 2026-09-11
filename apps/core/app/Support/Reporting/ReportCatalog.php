<?php

namespace App\Support\Reporting;

use App\Models\TenantMembership;
use App\Support\LaunchableAppCatalog;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Laporan yang dikenal Core, dari blok `reports` manifest app. Satu laporan dapat
 * dijalankan seorang pengguna bila app-nya siap dibuka olehnya dan ia memegang
 * permission bisnis yang disebut laporan itu; hak yang sama ditegakkan lagi oleh app
 * saat dataset diminta.
 */
final class ReportCatalog
{
    public function __construct(private readonly LaunchableAppCatalog $apps) {}

    /**
     * Baris `app_reports` beserta `app_name`.
     *
     * Tipenya `stdClass`, bukan `object`, karena itu yang benar-benar dipulangkan query
     * builder — dan bedanya bukan kosmetik: dengan `object`, setiap pembacaan kolom di sisi
     * pemanggil menjadi akses properti yang tidak dikenal analisa statis, sehingga salah ketik
     * nama kolom baru ketahuan saat dijalankan.
     */
    public function find(string $code): ?stdClass
    {
        $report = DB::table('app_reports as reports')
            ->join('apps', 'apps.id', '=', 'reports.app_id')
            ->where('reports.code', $code)
            ->first(['reports.*', 'apps.name as app_name', 'apps.version as app_version']);
        if ($report === null) {
            return null;
        }
        $report->parameters = json_decode($report->parameters, true) ?: [];
        $report->builtin_layouts = json_decode($report->builtin_layouts, true) ?: [];

        return $report;
    }

    /** Kode laporan di sisi app: kode katalog tanpa awalan ID app. */
    public function localCode(stdClass $report): string
    {
        return substr($report->code, strlen($report->app_id) + 1);
    }

    /**
     * Semua laporan dari app yang siap dibuka pengguna ini, beserta apakah ia boleh
     * menjalankannya. Laporan yang app-nya tidak siap tidak muncul sama sekali.
     *
     * @return list<array<string, mixed>>
     */
    public function forMembership(TenantMembership $membership): array
    {
        $readyApps = collect($this->apps->for($membership))->keyBy('id');
        if ($readyApps->isEmpty()) {
            return [];
        }
        $reports = DB::table('app_reports')
            ->whereIn('app_id', $readyApps->keys()->all())
            ->orderBy('app_id')->orderBy('name')
            ->get();
        $permissions = [];
        foreach ($readyApps->keys() as $appId) {
            $permissions[$appId] = array_flip($this->apps->permissionsFor($membership, $appId));
        }

        return $reports->map(fn (stdClass $report): array => [
            'code' => $report->code,
            'app_id' => $report->app_id,
            'app_name' => $readyApps[$report->app_id]['name'],
            'name' => $report->name,
            'description' => $report->description,
            'permission' => $report->permission,
            'parameters' => json_decode($report->parameters, true) ?: [],
            'can_run' => isset($permissions[$report->app_id][$report->permission]),
            'builtin_layouts' => array_map(fn (array $layout): array => [
                'ref' => LayoutRef::BUILTIN_PREFIX.$layout['key'],
                'name' => $layout['name'],
                'description' => $layout['description'] ?? null,
                'format' => $layout['format'],
                'outputs' => LayoutFile::outputFormatsFor($layout['format']),
            ], json_decode($report->builtin_layouts, true) ?: []),
        ])->all();
    }

    public function canRun(TenantMembership $membership, stdClass $report): bool
    {
        return collect($this->apps->for($membership))->contains('id', $report->app_id)
            && in_array($report->permission, $this->apps->permissionsFor($membership, $report->app_id), true);
    }
}
