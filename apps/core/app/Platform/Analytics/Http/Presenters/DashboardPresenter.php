<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Presenters;

use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Dashboards\WidgetDefinition;
use App\Platform\Analytics\Datasets\DatasetCatalog;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Models\Dashboard;
use App\Platform\Analytics\Models\SavedQuery;
use App\Platform\Analytics\Models\Widget;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Support\LaunchableAppCatalog;
use App\Platform\Tenant\Models\TenantMembership;

/**
 * Bentuk dasbor, widget, dan query tersimpan yang dikirim ke layar, sama untuk API `api/v1/analytics/...` dan
 * prop halaman `/analytics/...`. Tipe TypeScript-nya di `resources/js/lib/analytics/types.ts`.
 *
 * - Nama pemilik dikirim sebagai nama, bukan id, supaya layar dapat menulis "Dibuat oleh Rina".
 * - `can_edit` mengikuti `DashboardAccess`, yang juga menjaga endpoint ubah; layar tidak menebak sendiri.
 * - Query dan `visual` widget lama dikirim dengan kunci yang sudah dipetakan lewat `renamed` dataset, dan
 *   `status` menyebut keadaan yang membuat widget tidak dapat dihitung tanpa menghitungnya: `ok`,
 *   `field_removed` (dengan `missing_fields`), atau `dataset_unavailable` (dataset tidak terdaftar atau module
 *   tidak terpasang untuk tenant ini). Hak membaca data tidak diperiksa di sini; itu jawaban data widget.
 * - `layout` adalah letak efektif: letak tersimpan untuk widget yang masih ada, lalu widget tanpa letak di
 *   bawahnya, dua per baris. Menambah widget karena itu tidak mengubah versi dasbor.
 */
final class DashboardPresenter
{
    private const DEFAULT_WIDTH = 6;

    private const DEFAULT_HEIGHT = 2;

    public function __construct(
        private readonly DashboardAccess $access,
        private readonly DatasetRegistry $datasets,
        private readonly DatasetCatalog $catalog,
        private readonly LaunchableAppCatalog $apps,
        private readonly UserClock $clock,
    ) {}

    /**
     * Dasbor yang boleh dilihat keanggotaan ini — miliknya sendiri dan yang bersama — urut nama.
     *
     * @return list<array<string, mixed>>
     */
    public function list(TenantMembership $membership): array
    {
        $dashboards = $this->access->visible(Dashboard::query(), $membership)
            ->with('user:id,name')
            ->withCount('widgets')
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get();

        return array_values(array_map(fn (Dashboard $dashboard): array => $this->summary($dashboard, $membership), $dashboards->all()));
    }

    /** @return array<string, mixed> */
    public function summary(Dashboard $dashboard, TenantMembership $membership): array
    {
        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'shared' => $dashboard->shared,
            'mine' => $dashboard->user_id === (int) $membership->user_id,
            'owner_name' => $dashboard->user?->name,
            'can_edit' => $this->access->canEdit($membership, $dashboard),
            'widget_count' => (int) ($dashboard->getAttribute('widgets_count') ?? $dashboard->widgets()->count()),
            'updated_at' => $dashboard->updated_at?->toIso8601String(),
            'version' => $dashboard->version,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Dashboard $dashboard, TenantMembership $membership): array
    {
        $dashboard->loadMissing('user:id,name');
        $widgets = $dashboard->widgets()->orderBy('created_at')->orderBy('id')->get();
        $ready = $this->apps->readyModules((string) $membership->tenant_id);
        $dashboard->setAttribute('widgets_count', $widgets->count());
        $principal = UserPrincipal::fromMembership($membership, $this->clock->timezoneFor($membership->user, null));
        $widgetDatasets = array_fill_keys(array_filter($widgets->pluck('dataset_code')->all()), true);
        $datasetFields = [];
        foreach ($this->catalog->forPrincipal($principal) as $dataset) {
            if (isset($widgetDatasets[$dataset->code])) {
                $datasetFields[$dataset->code] = $this->catalog->describe($dataset, $principal);
            }
        }

        return [
            ...$this->summary($dashboard, $membership),
            'layout' => self::effectiveLayout($dashboard->layout, array_values(array_map(static fn (Widget $widget): string => $widget->id, $widgets->all()))),
            'slicers' => $dashboard->slicers ?? [],
            'dataset_fields' => $datasetFields,
            'widgets' => $widgets->map(fn (Widget $widget): array => $this->widget($widget, $ready))->values()->all(),
        ];
    }

    /**
     * @param  list<string>|null  $ready  module yang siap untuk tenant ini; null berarti dibaca di sini
     * @return array<string, mixed>
     */
    public function widget(Widget $widget, ?array $ready = null): array
    {
        $out = [
            'id' => $widget->id,
            'dashboard_id' => $widget->dashboard_id,
            'title' => $widget->title,
            'type' => $widget->type,
            'dataset_code' => $widget->dataset_code,
            'query' => $widget->query === null ? null : StoredQuery::ordered($widget->query),
            'visual' => $widget->visual,
            'cache_ttl_seconds' => $widget->cache_ttl_seconds,
            'status' => 'ok',
            'missing_fields' => [],
            'version' => $widget->version,
        ];
        if ($widget->dataset_code === null || $widget->query === null) {
            return $out;
        }

        $ready ??= $this->apps->readyModules($widget->tenant_id);
        $dataset = $this->datasets->find($widget->dataset_code);
        if ($dataset === null || ! in_array($dataset->moduleId, $ready, true)) {
            return [...$out, 'status' => 'dataset_unavailable'];
        }

        $read = StoredQuery::read($dataset, $widget->query, $widget->dataset_version);

        return [
            ...$out,
            'query' => $read['query'],
            'visual' => WidgetDefinition::renameVisual($widget->visual, $read['map']),
            'status' => $read['missing'] === [] ? 'ok' : 'field_removed',
            'missing_fields' => array_values(array_unique($read['missing'])),
        ];
    }

    /** @return array<string, mixed> */
    public function savedQuery(SavedQuery $saved, TenantMembership $membership): array
    {
        $out = [
            'id' => $saved->id,
            'code' => $saved->code,
            'name' => $saved->name,
            'description' => $saved->description,
            'shared' => $saved->shared,
            'mine' => $saved->user_id === (int) $membership->user_id,
            'owner_name' => $saved->user?->name,
            'can_edit' => $this->access->canEdit($membership, $saved),
            'dataset_code' => $saved->dataset_code,
            'query' => StoredQuery::ordered($saved->query),
            'status' => 'ok',
            'missing_fields' => [],
            'updated_at' => $saved->updated_at?->toIso8601String(),
            'version' => $saved->version,
        ];

        $dataset = $this->datasets->find($saved->dataset_code);
        if ($dataset === null || ! in_array($dataset->moduleId, $this->apps->readyModules($saved->tenant_id), true)) {
            return [...$out, 'status' => 'dataset_unavailable'];
        }
        $read = StoredQuery::read($dataset, $saved->query, $saved->dataset_version);

        return [
            ...$out,
            'query' => $read['query'],
            'status' => $read['missing'] === [] ? 'ok' : 'field_removed',
            'missing_fields' => array_values(array_unique($read['missing'])),
        ];
    }

    /**
     * Letak tersimpan untuk widget yang masih ada (entri pertama bila ganda), lalu widget tanpa letak di bawah
     * baris terakhir, dua per baris dengan ukuran bawaan.
     *
     * @param  list<array<string, mixed>>  $stored
     * @param  list<string>  $widgetIds  urut pembuatan
     * @return list<array{widget_id: string, x: int, y: int, w: int, h: int}>
     */
    public static function effectiveLayout(array $stored, array $widgetIds): array
    {
        $live = array_flip($widgetIds);
        $placed = [];
        $bottom = 0;
        foreach ($stored as $entry) {
            $id = $entry['widget_id'] ?? null;
            if (! is_string($id) || ! isset($live[$id]) || isset($placed[$id])) {
                continue;
            }
            $placed[$id] = [
                'widget_id' => $id,
                'x' => (int) ($entry['x'] ?? 0),
                'y' => (int) ($entry['y'] ?? 0),
                'w' => (int) ($entry['w'] ?? self::DEFAULT_WIDTH),
                'h' => (int) ($entry['h'] ?? self::DEFAULT_HEIGHT),
            ];
            $bottom = max($bottom, $placed[$id]['y'] + $placed[$id]['h']);
        }

        $i = 0;
        foreach ($widgetIds as $id) {
            if (isset($placed[$id])) {
                continue;
            }
            $placed[$id] = [
                'widget_id' => $id,
                'x' => ($i % 2) * self::DEFAULT_WIDTH,
                'y' => $bottom + intdiv($i, 2) * self::DEFAULT_HEIGHT,
                'w' => self::DEFAULT_WIDTH,
                'h' => self::DEFAULT_HEIGHT,
            ];
            $i++;
        }

        return array_values($placed);
    }
}
