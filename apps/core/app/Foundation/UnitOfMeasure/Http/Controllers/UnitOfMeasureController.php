<?php

namespace App\Foundation\UnitOfMeasure\Http\Controllers;

use App\Foundation\UnitOfMeasure\Models\UnitOfMeasure;
use App\Http\Controllers\Controller;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class UnitOfMeasureController extends Controller
{
    private const VALIDATION_MESSAGES = [
        'required' => ':attribute wajib diisi.',
        'string' => ':attribute harus berupa teks.',
        'max' => ':attribute tidak boleh lebih dari :max karakter.',
        'in' => 'Pilihan :attribute tidak tersedia.',
        'ulid' => ':attribute tidak valid. Pilih ulang dari daftar.',
        'boolean' => 'Pilihan :attribute tidak valid.',
        'integer' => ':attribute harus berupa bilangan bulat.',
        'numeric' => ':attribute harus berupa angka.',
        'between' => ':attribute harus antara :min dan :max.',
        'gt' => ':attribute harus lebih besar dari :value.',
        'different' => 'Satuan asal dan tujuan harus berbeda.',
        'code.regex' => 'Kode hanya boleh memakai huruf, angka, titik, garis bawah, dan tanda hubung.',
        'code.unique' => 'Kode ini sudah digunakan. Pilih kode lain.',
    ];

    private const VALIDATION_ATTRIBUTES = [
        'resource' => 'Daftar', 'status' => 'Status', 'active' => 'Status aktif',
        'code' => 'Kode', 'name' => 'Nama', 'symbol' => 'Simbol',
        'decimal_places' => 'Jumlah angka desimal', 'uom_class_id' => 'Kelas satuan',
        'uom_system_id' => 'Sistem satuan', 'from_unit_id' => 'Satuan asal',
        'to_unit_id' => 'Satuan tujuan', 'exclude_id' => 'Satuan',
        'factor' => 'Faktor konversi', 'offset' => 'Pergeseran',
        'rounding_scale' => 'Pembulatan desimal', 'q' => 'Pencarian',
    ];

    public function index(Request $request): JsonResponse|Response
    {
        if (! $request->is('api/*') && ! $request->boolean('incremental')) {
            return Inertia::render('foundation/unit-of-measure/units-of-measure', ['scopeKey' => $this->tenant($request), 'canManage' => $request->user()?->can('manage-reference-data') ?? false]);
        }
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'resource' => ['required', Rule::in(['units', 'classes', 'systems', 'conversions'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'uom_class_id' => ['nullable', 'ulid'],
            'from_unit_id' => ['nullable', 'ulid'],
            'active' => ['nullable', 'boolean'],
            'exclude_id' => ['nullable', 'ulid'],
        ], self::VALIDATION_MESSAGES, self::VALIDATION_ATTRIBUTES);
        $tenant = $this->tenant($request);
        $resource = $input['resource'];
        if ($resource === 'units') {
            $query = $this->unitQuery($tenant);
            if (! empty($input['uom_class_id'])) {
                $query->where('u.uom_class_id', $input['uom_class_id']);
            }
            if (! empty($input['exclude_id'])) {
                $query->where('u.id', '!=', $input['exclude_id']);
            }
            $columns = ['code', 'name', 'symbol', 'decimal_places', 'class_name', 'system_name', 'active'];
            $search = ['code', 'name', 'symbol', 'class_name', 'system_name'];
        } elseif ($resource === 'conversions') {
            $query = $this->conversionQuery($tenant);
            if (! empty($input['from_unit_id'])) {
                $query->where('c.from_unit_id', $input['from_unit_id']);
            }
            $columns = ['from_code', 'from_name', 'to_code', 'to_name', 'factor', 'offset', 'rounding_scale'];
            $search = ['from_code', 'from_name', 'to_code', 'to_name'];
        } else {
            $query = DB::table($resource === 'classes' ? 'uom_classes' : 'uom_systems')->where('tenant_id', $tenant);
            $columns = ['code', 'name', 'active'];
            $search = ['code', 'name'];
        }
        if ($resource !== 'conversions' && ! empty($input['status'])) {
            $query->where($resource === 'units' ? 'u.active' : 'active', $input['status'] === 'active');
        }
        if ($resource !== 'conversions' && array_key_exists('active', $input) && $input['active'] !== null) {
            $query->where($resource === 'units' ? 'u.active' : 'active', (bool) $input['active']);
        }

        return response()->json(IncrementalList::query($request, $query, array_combine($columns, $columns), $search, $resource === 'conversions' ? 'from_code' : 'code', ['decimal_places', 'factor', 'offset', 'rounding_scale']));
    }

    private function unitQuery(string $tenant): Builder
    {
        return DB::table('units_of_measure as u')->where('u.tenant_id', $tenant)->whereNull('u.deleted_at')
            ->join('uom_classes as c', fn ($join) => $join->on('c.id', '=', 'u.uom_class_id')->on('c.tenant_id', '=', 'u.tenant_id'))
            ->leftJoin('uom_systems as s', fn ($join) => $join->on('s.id', '=', 'u.uom_system_id')->on('s.tenant_id', '=', 'u.tenant_id'))
            ->select('u.*', 'c.name as class_name', 's.name as system_name');
    }

    private function conversionQuery(string $tenant): Builder
    {
        return DB::table('uom_conversions as c')->where('c.tenant_id', $tenant)
            ->join('units_of_measure as f', fn ($join) => $join->on('f.id', '=', 'c.from_unit_id')->on('f.tenant_id', '=', 'c.tenant_id'))
            ->join('units_of_measure as t', fn ($join) => $join->on('t.id', '=', 'c.to_unit_id')->on('t.tenant_id', '=', 'c.tenant_id'))
            ->select('c.*', 'f.code as from_code', 'f.name as from_name', 't.code as to_code', 't.name as to_name');
    }

    public function storeClass(Request $request): JsonResponse|RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('uom_classes', 'code')->where('tenant_id', $tenant)], 'name' => ['required', 'string', 'max:150']], self::VALIDATION_MESSAGES, self::VALIDATION_ATTRIBUTES);
        $record = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, ...$data, 'active' => true, 'created_at' => now(), 'updated_at' => now()];
        $this->write($request, 'class.created', fn (string $tenant) => DB::table('uom_classes')->insert($record));

        return $this->respond($request, 'Kelas satuan ditambahkan.', $record);
    }

    public function storeSystem(Request $request): JsonResponse|RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('uom_systems', 'code')->where('tenant_id', $tenant)], 'name' => ['required', 'string', 'max:150']], self::VALIDATION_MESSAGES, self::VALIDATION_ATTRIBUTES);
        $record = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, ...$data, 'active' => true, 'created_at' => now(), 'updated_at' => now()];
        $this->write($request, 'system.created', fn (string $tenant) => DB::table('uom_systems')->insert($record));

        return $this->respond($request, 'Sistem satuan ditambahkan.', $record);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('units_of_measure', 'code')->where('tenant_id', $this->tenant($request))->whereNull('deleted_at')], 'name' => ['required', 'string', 'max:150'], 'symbol' => ['nullable', 'string', 'max:30'], 'decimal_places' => ['required', 'integer', 'between:0,12'], 'uom_class_id' => ['required', 'ulid'], 'uom_system_id' => ['nullable', 'ulid']], self::VALIDATION_MESSAGES, self::VALIDATION_ATTRIBUTES);
        $unit = null;
        $this->write($request, 'unit.created', function (string $tenant) use ($data, &$unit): void {
            if (! DB::table('uom_classes')->where(['tenant_id' => $tenant, 'id' => $data['uom_class_id'], 'active' => true])->exists()) {
                throw ValidationException::withMessages(['uom_class_id' => 'Kelas satuan tidak tersedia.']);
            }
            if ($data['uom_system_id'] ?? null) {
                if (! DB::table('uom_systems')->where(['tenant_id' => $tenant, 'id' => $data['uom_system_id'], 'active' => true])->exists()) {
                    throw ValidationException::withMessages(['uom_system_id' => 'Sistem satuan tidak tersedia.']);
                }
            }
            $unit = UnitOfMeasure::query()->create(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, ...$data, 'active' => true]);
        });

        return $this->respond($request, 'Satuan ditambahkan.', (array) $this->unitQuery($this->tenant($request))->where('u.id', $unit?->id)->first());
    }

    public function update(Request $request, UnitOfMeasure $unit): JsonResponse|RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless($unit->tenant_id === $tenant, 404);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:150'], 'symbol' => ['nullable', 'string', 'max:30'], 'decimal_places' => ['sometimes', 'integer', 'between:0,12'], 'active' => ['sometimes', 'boolean']], self::VALIDATION_MESSAGES, self::VALIDATION_ATTRIBUTES);
        $this->write($request, 'unit.updated', function () use ($request, $unit, $data): void {
            RowVersion::claim($unit, RowVersion::expected($request));
            $unit->update($data);
        });

        if ($request->is('api/*')) {
            // Versi terbaru ikut dikembalikan supaya penyimpanan berikutnya tidak perlu membaca ulang.
            return response()->json(['data' => ['message' => 'Satuan diperbarui.', ...(array) $this->unitQuery($tenant)->where('u.id', $unit->id)->first()]], 201);
        }

        return $this->respond($request, 'Satuan diperbarui.');
    }

    public function storeConversion(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['from_unit_id' => ['required', 'ulid', 'different:to_unit_id'], 'to_unit_id' => ['required', 'ulid'], 'factor' => ['required', 'numeric', 'gt:0'], 'offset' => ['nullable', 'numeric'], 'rounding_scale' => ['nullable', 'integer', 'between:0,12']], self::VALIDATION_MESSAGES, self::VALIDATION_ATTRIBUTES);
        $record = null;
        $this->write($request, 'conversion.created', function (string $tenant) use ($data, &$record): void {
            $units = UnitOfMeasure::query()->where('tenant_id', $tenant)->whereIn('id', [$data['from_unit_id'], $data['to_unit_id']])->where('active', true)->get();
            abort_unless($units->count() === 2 && $units->pluck('uom_class_id')->unique()->count() === 1, 422, 'Pilih dua satuan aktif dalam kelas yang sama.');
            $key = ['tenant_id' => $tenant, 'from_unit_id' => $data['from_unit_id'], 'to_unit_id' => $data['to_unit_id']];
            $values = ['factor' => $data['factor'], 'offset' => $data['offset'] ?? 0, 'rounding_scale' => $data['rounding_scale'] ?? null, 'updated_at' => now()];
            DB::table('uom_conversions')->insertOrIgnore([...$key, ...$values, 'id' => (string) Str::ulid(), 'created_at' => now()]);
            DB::table('uom_conversions')->where($key)->update($values);
            $record = (array) $this->conversionQuery($tenant)->where('c.from_unit_id', $data['from_unit_id'])->where('c.to_unit_id', $data['to_unit_id'])->first();
        });

        return $this->respond($request, 'Aturan konversi disimpan.', $record ?? []);
    }

    private function tenant(Request $request): string
    {
        return (string) app(CurrentWorkspace::class)->membership($request)?->tenant_id;
    }

    private function write(Request $request, string $action, callable $write): void
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);
        $tenant = $this->tenant($request);
        DB::transaction(function () use ($tenant, $action, $write): void {
            $write($tenant);
            DB::table('outbox_events')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'type' => 'core.units-of-measure.'.$action.'.v1', 'payload' => json_encode(['action' => $action]), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    /** @param array<string, mixed> $record */
    private function respond(Request $request, string $message, array $record = []): JsonResponse|RedirectResponse
    {
        return $request->is('api/*') ? response()->json(['data' => ['message' => $message, ...$record]], 201) : back()->with('status', $message);
    }
}
