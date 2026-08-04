<?php

namespace App\Http\Controllers\ReferenceData;

use App\Http\Controllers\Controller;
use App\Models\UnitOfMeasure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class UnitOfMeasureController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $tenant = $this->tenant($request);
        $data = [
            'classes' => DB::table('uom_classes')->where('tenant_id', $tenant)->orderBy('name')->get(),
            'systems' => DB::table('uom_systems')->where('tenant_id', $tenant)->orderBy('name')->get(),
            'units' => UnitOfMeasure::query()->where('tenant_id', $tenant)->withTrashed()->orderBy('code')->get(),
            'conversions' => DB::table('uom_conversions')->where('tenant_id', $tenant)->orderBy('created_at')->get(),
        ];
        if ($request->is('api/*')) return response()->json(['data' => $data]);
        return Inertia::render('settings/units-of-measure', ['canManage' => $request->user()?->can('manage-reference-data') ?? false, ...$data]);
    }

    public function storeClass(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'], 'name' => ['required', 'string', 'max:150']]);
        $this->write($request, 'class.created', fn (string $tenant) => DB::table('uom_classes')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, ...$data, 'active' => true, 'created_at' => now(), 'updated_at' => now()]));
        return $this->respond($request, 'Kelas satuan ditambahkan.');
    }

    public function storeSystem(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'], 'name' => ['required', 'string', 'max:150']]);
        $this->write($request, 'system.created', fn (string $tenant) => DB::table('uom_systems')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, ...$data, 'active' => true, 'created_at' => now(), 'updated_at' => now()]));
        return $this->respond($request, 'Sistem satuan ditambahkan.');
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'], 'name' => ['required', 'string', 'max:150'], 'symbol' => ['nullable', 'string', 'max:30'], 'decimal_places' => ['required', 'integer', 'between:0,12'], 'uom_class_id' => ['required', 'ulid'], 'uom_system_id' => ['nullable', 'ulid']]);
        $this->write($request, 'unit.created', function (string $tenant) use ($data): void {
            abort_unless(DB::table('uom_classes')->where(['tenant_id' => $tenant, 'id' => $data['uom_class_id'], 'active' => true])->exists(), 422, 'Kelas satuan tidak tersedia.');
            if ($data['uom_system_id']) abort_unless(DB::table('uom_systems')->where(['tenant_id' => $tenant, 'id' => $data['uom_system_id'], 'active' => true])->exists(), 422, 'Sistem satuan tidak tersedia.');
            UnitOfMeasure::query()->create(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, ...$data, 'active' => true]);
        });
        return $this->respond($request, 'Satuan ditambahkan.');
    }

    public function update(Request $request, UnitOfMeasure $unit): JsonResponse|RedirectResponse
    {
        $tenant = $this->tenant($request); abort_unless($unit->tenant_id === $tenant, 404);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'], 'symbol' => ['nullable', 'string', 'max:30'], 'decimal_places' => ['sometimes', 'integer', 'between:0,12'], 'active' => ['sometimes', 'boolean']]);
        $this->write($request, 'unit.updated', fn () => $unit->update($data));
        return $this->respond($request, 'Satuan diperbarui.');
    }

    public function storeConversion(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['from_unit_id' => ['required', 'ulid', 'different:to_unit_id'], 'to_unit_id' => ['required', 'ulid'], 'factor' => ['required', 'numeric', 'gt:0'], 'offset' => ['nullable', 'numeric'], 'rounding_scale' => ['nullable', 'integer', 'between:0,12']]);
        $this->write($request, 'conversion.created', function (string $tenant) use ($data): void {
            $units = UnitOfMeasure::query()->where('tenant_id', $tenant)->whereIn('id', [$data['from_unit_id'], $data['to_unit_id']])->where('active', true)->get();
            abort_unless($units->count() === 2 && $units->pluck('uom_class_id')->unique()->count() === 1, 422, 'Pilih dua satuan aktif dalam kelas yang sama.');
            DB::table('uom_conversions')->updateOrInsert(['tenant_id' => $tenant, 'from_unit_id' => $data['from_unit_id'], 'to_unit_id' => $data['to_unit_id']], ['id' => (string) Str::ulid(), 'factor' => $data['factor'], 'offset' => $data['offset'] ?? 0, 'rounding_scale' => $data['rounding_scale'], 'updated_at' => now(), 'created_at' => now()]);
        });
        return $this->respond($request, 'Aturan konversi disimpan.');
    }

    private function tenant(Request $request): string { return (string) app(\App\Support\CurrentWorkspace::class)->membership($request)?->tenant_id; }
    private function write(Request $request, string $action, callable $write): void
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);
        $tenant = $this->tenant($request);
        DB::transaction(function () use ($tenant, $action, $write): void {
            $write($tenant);
            DB::table('outbox_events')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'type' => 'core.units-of-measure.'.$action.'.v1', 'payload' => json_encode(['action' => $action]), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
    private function respond(Request $request, string $message): JsonResponse|RedirectResponse { return $request->is('api/*') ? response()->json(['data' => ['message' => $message]], 201) : back()->with('status', $message); }
}
