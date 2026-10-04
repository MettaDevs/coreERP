<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Dashboards\WidgetDefinition;
use App\Platform\Analytics\Http\Presenters\DashboardPresenter;
use App\Platform\Analytics\Models\Dashboard;
use App\Platform\Analytics\Models\Widget;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Widget satu dasbor (`api/v1/analytics/dashboards/{dashboard}/widgets`, `.../widgets/{widget}`). Haknya sama
 * dengan mengubah dasbornya ({@see DashboardAccess::authorizeEdit()}); widget di dasbor yang tidak boleh dilihat
 * dijawab 404, dan widget di dasbor terarsip tidak ditemukan.
 *
 * Isi widget diperiksa saat disimpan ({@see WidgetDefinition}): query terhadap dataset saat ini dengan hak
 * penyimpannya, lalu `visual` per jenis. Mengganti judul atau masa simpan saja tidak memeriksa ulang query,
 * supaya widget lama yang kolomnya sudah hilang tetap dapat diganti nama atau diarsipkan.
 *
 * Jumlah widget per dasbor dibatasi `analytics.limits.widgets_per_dashboard` (bawaan 24), dihitung di bawah
 * kunci baris dasbor supaya dua penambahan bersamaan tidak melewatinya. Menambah widget tidak mengubah versi
 * dasbor: letaknya diatur lewat `layout` dasbor, dan widget tanpa letak ditempatkan di bawah oleh presenter.
 */
final class WidgetController extends Controller
{
    /** Masa simpan hasil widget: kosong berarti bawaan, 0 tanpa cache, selain itu 60 detik sampai sehari. */
    private const MIN_TTL = 60;

    private const MAX_TTL = 86400;

    public function __construct(
        private readonly DashboardAccess $access,
        private readonly WidgetDefinition $definition,
        private readonly DashboardPresenter $presenter,
        private readonly UserClock $clock,
    ) {}

    public function store(Request $request, Dashboard $dashboard): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->access->authorizeEdit($membership, $dashboard);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(Widget::TYPES)],
            'query' => ['nullable', 'array'],
            'visual' => ['nullable', 'array'],
            'cache_ttl_seconds' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_TTL],
        ]);
        $ttl = $this->ttl($data['cache_ttl_seconds'] ?? null);

        try {
            $definition = $this->definition->validate($this->principal($request, $membership), $data['type'], $data['query'] ?? null, $data['visual'] ?? null);
            $widget = DB::transaction(function () use ($dashboard, $membership, $data, $definition, $ttl): Widget {
                Dashboard::query()->whereKey($dashboard->id)->lockForUpdate()->first();
                $limit = config()->integer('analytics.limits.widgets_per_dashboard', 24);
                if ($dashboard->widgets()->count() >= $limit) {
                    throw AnalyticsQueryException::limitExceeded('dashboard', "Dasbor ini sudah berisi {$limit} widget, batas terbanyaknya. Arsipkan widget yang tidak dipakai lebih dulu.");
                }

                return Widget::query()->create([
                    'tenant_id' => $membership->tenant_id,
                    'dashboard_id' => $dashboard->id,
                    'title' => trim($data['title']),
                    'type' => $data['type'],
                    ...$definition,
                    'cache_ttl_seconds' => $ttl,
                ])->refresh();
            });
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        return $this->respond($widget, 201);
    }

    public function update(Request $request, Widget $widget): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $dashboard = $widget->dashboard ?? abort(404);
        $this->access->authorizeEdit($membership, $dashboard);
        $expected = RowVersion::expected($request);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'type' => ['sometimes', 'required', 'string', Rule::in(Widget::TYPES)],
            'query' => ['sometimes', 'nullable', 'array'],
            'visual' => ['sometimes', 'nullable', 'array'],
            'cache_ttl_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_TTL],
        ]);

        $values = [];
        if (array_key_exists('title', $data)) {
            $values['title'] = trim($data['title']);
        }
        if (array_key_exists('cache_ttl_seconds', $data)) {
            $values['cache_ttl_seconds'] = $this->ttl($data['cache_ttl_seconds']);
        }

        try {
            if (array_intersect_key($data, array_flip(['type', 'query', 'visual'])) !== []) {
                // Bagian yang tidak dikirim diambil dari widget yang tersimpan, dengan kunci yang sudah dipetakan
                // ke dataset saat ini, lalu seluruh isinya diperiksa ulang bersama.
                $current = $this->presenter->widget($widget);
                $type = $data['type'] ?? $widget->type;
                $values = [...$values, 'type' => $type, ...$this->definition->validate(
                    $this->principal($request, $membership),
                    $type,
                    array_key_exists('query', $data) ? $data['query'] : $current['query'],
                    // Tampilan jenis lama tidak berlaku untuk jenis baru; tanpa kiriman, bawaan jenis barunya.
                    array_key_exists('visual', $data) ? $data['visual'] : ($type === $widget->type ? $current['visual'] : []),
                )];
            }
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        DB::transaction(function () use ($widget, $expected, $values): void {
            RowVersion::claim($widget, $expected);
            if ($values !== []) {
                $widget->forceFill($values)->save();
            }
        });

        return $this->respond($widget->refresh());
    }

    public function destroy(Request $request, Widget $widget): Response
    {
        $membership = $this->currentMembership($request);
        $dashboard = $widget->dashboard ?? abort(404);
        $this->access->authorizeEdit($membership, $dashboard);
        $expected = RowVersion::expected($request);

        DB::transaction(function () use ($widget, $expected): void {
            RowVersion::claim($widget, $expected);
            $widget->delete();
        });

        return response()->noContent();
    }

    private function respond(Widget $widget, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->presenter->widget($widget)], $status, ['ETag' => RowVersion::etag($widget->version)]);
    }

    private function principal(Request $request, TenantMembership $membership): UserPrincipal
    {
        return UserPrincipal::fromMembership($membership, $this->clock->timezone($request));
    }

    private function ttl(?int $seconds): ?int
    {
        if ($seconds !== null && $seconds > 0 && $seconds < self::MIN_TTL) {
            throw ValidationException::withMessages(['cache_ttl_seconds' => ['Masa simpan hasil paling sedikit '.self::MIN_TTL.' detik, atau 0 untuk selalu menghitung ulang.']]);
        }

        return $seconds;
    }
}
