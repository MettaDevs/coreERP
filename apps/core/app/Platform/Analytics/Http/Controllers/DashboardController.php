<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Http\Presenters\DashboardPresenter;
use App\Platform\Analytics\Models\Dashboard;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dasbor analitik untuk layar (`api/v1/analytics/dashboards`), dijaga `core.analytics.dashboard.read` di gate
 * rute; membuat juga `core.analytics.dashboard.create`. Aturan berbaginya di {@see DashboardAccess}: dasbor
 * pribadi orang lain dijawab 404 seperti yang tidak ada, dan id tenant lain sudah 404 sejak route binding.
 *
 * Setiap perubahan memakai versi baris (`If-Match` atau field `version`): tanpa versi 428, versi basi 409.
 * Mengarsipkan dasbor ikut mengarsipkan widget-nya; tidak ada baris yang dihapus fisik.
 *
 * `layout` hanya menerima letak widget milik dasbor itu, dengan lebar 3, 4, 6, 8, atau 12 dari grid 12 kolom
 * dan tinggi 1–3 baris. Slicer milik fase 2 dan belum diterima.
 */
final class DashboardController extends Controller
{
    public const WIDTHS = [3, 4, 6, 8, 12];

    public const MAX_HEIGHT = 3;

    public function __construct(
        private readonly DashboardAccess $access,
        private readonly DashboardPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);

        return response()->json(['data' => $this->presenter->list($membership)]);
    }

    public function store(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'shared' => ['sometimes', 'boolean'],
        ]);
        $shared = (bool) ($data['shared'] ?? false);
        $this->access->authorizeCreate($membership, $shared);

        $name = trim($data['name']);
        $this->assertNameFree($membership, (int) $membership->user_id, $name, $shared, null);

        try {
            // Savepoint: pelanggaran indeks unik membatalkan seluruh transaksi PostgreSQL bila tidak dibatasi.
            $dashboard = DB::transaction(fn (): Dashboard => Dashboard::query()->create([
                'tenant_id' => $membership->tenant_id,
                'user_id' => $membership->user_id,
                'name' => $name,
                'description' => $data['description'] ?? null,
                'shared' => $shared,
            ])->refresh());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [self::duplicateMessage($shared)]]);
        }

        return $this->respond($dashboard, $membership, 201);
    }

    public function show(Request $request, Dashboard $dashboard): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->access->authorizeView($membership, $dashboard);

        return $this->respond($dashboard, $membership);
    }

    public function update(Request $request, Dashboard $dashboard): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->access->authorizeEdit($membership, $dashboard);
        $expected = RowVersion::expected($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'shared' => ['sometimes', 'boolean'],
            'layout' => ['sometimes', 'array', 'list'],
            'layout.*' => ['array:widget_id,x,y,w,h'],
            'layout.*.widget_id' => ['required', 'string'],
            'layout.*.x' => ['required', 'integer', 'min:0', 'max:11'],
            'layout.*.y' => ['required', 'integer', 'min:0', 'max:1000'],
            'layout.*.w' => ['required', 'integer', Rule::in(self::WIDTHS)],
            'layout.*.h' => ['required', 'integer', 'min:1', 'max:'.self::MAX_HEIGHT],
        ]);

        $values = [];
        $shared = $dashboard->shared;
        if (array_key_exists('shared', $data)) {
            $shared = (bool) $data['shared'];
            $this->access->authorizeShareChange($membership, $dashboard, $shared);
            $values['shared'] = $shared;
        }
        if (array_key_exists('name', $data) || $shared !== $dashboard->shared) {
            $values['name'] = trim($data['name'] ?? $dashboard->name);
            $this->assertNameFree($membership, $dashboard->user_id, $values['name'], $shared, $dashboard->id);
        }
        if (array_key_exists('description', $data)) {
            $values['description'] = $data['description'];
        }
        if (array_key_exists('layout', $data)) {
            $values['layout'] = $this->layout($dashboard, $data['layout']);
        }

        try {
            DB::transaction(function () use ($dashboard, $expected, $values): void {
                RowVersion::claim($dashboard, $expected);
                if ($values !== []) {
                    $dashboard->forceFill($values)->save();
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [self::duplicateMessage($shared)]]);
        }

        return $this->respond($dashboard->refresh(), $membership);
    }

    public function destroy(Request $request, Dashboard $dashboard): Response
    {
        $membership = $this->currentMembership($request);
        $this->access->authorizeEdit($membership, $dashboard);
        $expected = RowVersion::expected($request);

        DB::transaction(function () use ($dashboard, $expected): void {
            RowVersion::claim($dashboard, $expected);
            $dashboard->widgets()->delete();
            $dashboard->delete();
        });

        return response()->noContent();
    }

    private function respond(Dashboard $dashboard, TenantMembership $membership, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($dashboard, $membership)], $status, ['ETag' => RowVersion::etag($dashboard->version)]);
    }

    /**
     * Letak yang hanya menyebut widget milik dasbor ini, masing-masing sekali, dan tidak melewati grid 12 kolom.
     *
     * @param  list<array{widget_id: string, x: int, y: int, w: int, h: int}>  $layout
     * @return list<array{widget_id: string, x: int, y: int, w: int, h: int}>
     */
    private function layout(Dashboard $dashboard, array $layout): array
    {
        $widgets = array_flip($dashboard->widgets()->pluck('id')->all());
        $seen = [];
        $out = [];
        foreach ($layout as $i => $entry) {
            $id = $entry['widget_id'];
            if (! isset($widgets[$id])) {
                throw ValidationException::withMessages(["layout.{$i}.widget_id" => ['Widget ini tidak ada di dasbor ini. Muat ulang dasbornya.']]);
            }
            if (isset($seen[$id])) {
                throw ValidationException::withMessages(["layout.{$i}.widget_id" => ['Setiap widget hanya boleh punya satu letak.']]);
            }
            if ($entry['x'] + $entry['w'] > 12) {
                throw ValidationException::withMessages(["layout.{$i}.x" => ['Widget melewati lebar dasbor. Geser ke kiri atau perkecil lebarnya.']]);
            }
            $seen[$id] = true;
            $out[] = ['widget_id' => $id, 'x' => (int) $entry['x'], 'y' => (int) $entry['y'], 'w' => (int) $entry['w'], 'h' => (int) $entry['h']];
        }

        return $out;
    }

    /** Nama dasbor pribadi unik per pemiliknya; nama dasbor bersama unik per tenant. */
    private function assertNameFree(TenantMembership $membership, int $ownerId, string $name, bool $shared, ?string $exceptId): void
    {
        $taken = Dashboard::query()
            ->where(['tenant_id' => $membership->tenant_id, 'shared' => $shared])
            ->when(! $shared, fn (Builder $query) => $query->where('user_id', $ownerId))
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => [self::duplicateMessage($shared)]]);
        }
    }

    private static function duplicateMessage(bool $shared): string
    {
        return $shared
            ? 'Sudah ada dasbor bersama dengan nama ini. Pilih nama lain.'
            : 'Sudah ada dasbor pribadi dengan nama ini milik pemiliknya. Pilih nama lain.';
    }
}
