<?php

namespace App\Foundation\Item\Http\Controllers;

use App\Foundation\Item\Models\Item;
use App\Http\Controllers\Controller;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Modules\Contracts\UnitOfMeasureDirectory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ItemController extends Controller
{
    public function page(Request $request): Response
    {
        $this->tenant($request);

        return Inertia::render('foundation/item/service-items', [
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    public function index(Request $request, UnitOfMeasureDirectory $uomDirectory): JsonResponse
    {
        $tenantId = $this->tenant($request);

        $request->validate([
            'type' => ['nullable', Rule::in(['service', 'inventory', 'non_inventory'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'q' => ['nullable', 'string', 'max:250'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        $query = Item::query()->where('tenant_id', $tenantId);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('active', $request->input('status') === 'active');
        }

        $batch = IncrementalList::query(
            $request,
            $query->select(['id', 'code', 'name', 'type', 'unit_of_measure_id', 'description', 'active', 'version'])->toBase(),
            ['id' => 'id', 'code' => 'code', 'name' => 'name', 'type' => 'type'],
            ['code', 'name', 'description'],
            defaultSort: 'code',
            defaultDirection: 'asc',
        );

        /** @var list<string> $uomIds */
        $uomIds = collect($batch['data'])
            ->pluck('unit_of_measure_id')
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $resolvedUoms = $uomDirectory->resolve($tenantId, $uomIds);

        $enrichedData = collect($batch['data'])->map(function (object $item) use ($resolvedUoms): array {
            $uom = $item->unit_of_measure_id ? ($resolvedUoms[$item->unit_of_measure_id] ?? null) : null;

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'type' => $item->type,
                'unit_of_measure_id' => $item->unit_of_measure_id,
                'unit_of_measure' => $uom ? [
                    'id' => $uom['id'],
                    'code' => $uom['code'],
                    'name' => $uom['name'],
                    'symbol' => $uom['symbol'] ?? $uom['name'],
                ] : null,
                'description' => $item->description,
                'active' => (bool) $item->active,
                'version' => (int) $item->version,
            ];
        })->values()->all();

        return response()->json([
            'data' => $enrichedData,
            'next_cursor' => $batch['next_cursor'],
        ]);
    }

    public function show(Request $request, Item $item, UnitOfMeasureDirectory $uomDirectory): JsonResponse
    {
        $tenantId = $this->tenant($request);
        abort_unless($item->tenant_id === $tenantId, 404);

        $uom = null;
        if ($item->unit_of_measure_id) {
            $resolved = $uomDirectory->resolve($tenantId, [$item->unit_of_measure_id]);
            $uom = $resolved[$item->unit_of_measure_id] ?? null;
        }

        $data = $item->toArray();
        $data['unit_of_measure'] = $uom ? [
            'id' => $uom['id'],
            'code' => $uom['code'],
            'name' => $uom['name'],
            'symbol' => $uom['symbol'] ?? $uom['name'],
        ] : null;

        return response()->json(
            ['data' => $data],
            200,
            ['ETag' => RowVersion::etag($item->version)],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->writeTenant($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:250'],
            'type' => ['nullable', Rule::in(['service', 'inventory', 'non_inventory'])],
            'unit_of_measure_id' => [
                'required',
                'string',
                'ulid',
                Rule::exists('units_of_measure', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['nullable', 'boolean'],
        ], [
            'code.required' => 'Kode item/jasa wajib diisi.',
            'name.required' => 'Nama item/jasa wajib diisi.',
            'unit_of_measure_id.required' => 'Satuan item/jasa wajib dipilih.',
            'unit_of_measure_id.exists' => 'Satuan yang dipilih tidak valid atau tidak aktif pada master satuan Core.',
        ]);

        $payload = [
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            'type' => $data['type'] ?? 'service',
            'unit_of_measure_id' => $data['unit_of_measure_id'],
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'active' => isset($data['active']) ? (bool) $data['active'] : true,
        ];

        if ($payload['code'] === '') {
            throw ValidationException::withMessages(['code' => 'Kode item/jasa wajib diisi.']);
        }
        if ($payload['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Nama item/jasa wajib diisi.']);
        }

        $creationKey = (string) $request->header('Idempotency-Key');
        validator(
            ['key' => $creationKey],
            ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate();

        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $existing = Item::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('creation_key', $creationKey)
            ->first();

        if ($existing !== null) {
            abort_unless(
                hash_equals((string) $existing->creation_payload_hash, $hash),
                409,
                'Permintaan ini sudah dipakai untuk isian yang berbeda.',
            );

            return response()->json(
                ['data' => $existing],
                200,
                ['Idempotent-Replayed' => 'true', 'ETag' => RowVersion::etag($existing->version)],
            );
        }

        $duplicateExists = Item::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $payload['code'])
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'code' => 'Kode item/jasa ini sudah digunakan oleh item/jasa aktif lain.',
            ]);
        }

        try {
            $record = DB::transaction(function () use ($tenantId, $creationKey, $hash, $payload): Item {
                $record = Item::query()->create([
                    'tenant_id' => $tenantId,
                    'creation_key' => $creationKey,
                    'creation_payload_hash' => $hash,
                    ...$payload,
                ]);

                return $record->refresh();
            });
        } catch (QueryException $error) {
            $existing = Item::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('creation_key', $creationKey)
                ->first();

            if ($existing !== null) {
                abort_unless(
                    hash_equals((string) $existing->creation_payload_hash, $hash),
                    409,
                    'Permintaan ini sudah dipakai untuk isian yang berbeda.',
                );

                return response()->json(
                    ['data' => $existing],
                    200,
                    ['Idempotent-Replayed' => 'true', 'ETag' => RowVersion::etag($existing->version)],
                );
            }

            if ($error->getCode() === '23505' && str_contains($error->getMessage(), 'items_active_code_unique')) {
                throw ValidationException::withMessages([
                    'code' => 'Kode item/jasa ini sudah digunakan oleh item/jasa aktif lain.',
                ]);
            }

            throw $error;
        }

        return response()->json(
            ['data' => $record],
            201,
            [
                'Location' => $request->url().'/'.$record->getKey(),
                'ETag' => RowVersion::etag($record->version),
            ],
        );
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        $tenantId = $this->writeTenant($request);
        abort_unless($item->tenant_id === $tenantId, 404);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:250'],
            'type' => ['nullable', Rule::in(['service', 'inventory', 'non_inventory'])],
            'unit_of_measure_id' => [
                'required',
                'string',
                'ulid',
                Rule::exists('units_of_measure', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('active', true),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ], [
            'code.required' => 'Kode item/jasa wajib diisi.',
            'name.required' => 'Nama item/jasa wajib diisi.',
            'active.required' => 'Status aktif wajib diisi.',
            'unit_of_measure_id.required' => 'Satuan item/jasa wajib dipilih.',
            'unit_of_measure_id.exists' => 'Satuan yang dipilih tidak valid atau tidak aktif pada master satuan Core.',
        ]);

        $payload = [
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            'type' => $data['type'] ?? $item->type,
            'unit_of_measure_id' => $data['unit_of_measure_id'],
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'active' => (bool) $data['active'],
        ];

        if ($payload['code'] === '') {
            throw ValidationException::withMessages(['code' => 'Kode item/jasa wajib diisi.']);
        }
        if ($payload['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Nama item/jasa wajib diisi.']);
        }

        $expectedVersion = RowVersion::expected($request);

        try {
            DB::transaction(function () use ($item, $expectedVersion, $payload): void {
                RowVersion::claim($item, $expectedVersion);

                if ($payload['code'] !== $item->code) {
                    $duplicateExists = Item::query()
                        ->where('tenant_id', $item->tenant_id)
                        ->where('code', $payload['code'])
                        ->whereKeyNot($item->getKey())
                        ->exists();

                    if ($duplicateExists) {
                        throw ValidationException::withMessages([
                            'code' => 'Kode item/jasa ini sudah digunakan oleh item/jasa aktif lain.',
                        ]);
                    }
                }

                $item->update($payload);
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505' && str_contains($error->getMessage(), 'items_active_code_unique')) {
                throw ValidationException::withMessages([
                    'code' => 'Kode item/jasa ini sudah digunakan oleh item/jasa aktif lain.',
                ]);
            }

            throw $error;
        }

        $fresh = $item->refresh();

        return response()->json(
            ['data' => $fresh],
            200,
            ['ETag' => RowVersion::etag($fresh->version)],
        );
    }

    public function updateStatus(Request $request, Item $item): JsonResponse
    {
        abort_unless($item->tenant_id === $this->writeTenant($request), 404);

        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ], [
            'active.required' => 'Status aktif wajib disertakan.',
            'active.boolean' => 'Format status aktif harus berupa boolean.',
        ]);

        $expectedVersion = RowVersion::expected($request);

        DB::transaction(function () use ($item, $expectedVersion, $validated): void {
            RowVersion::claim($item, $expectedVersion);
            $item->update(['active' => (bool) $validated['active']]);
        });

        $fresh = $item->refresh();

        return response()->json(
            ['data' => $fresh],
            200,
            ['ETag' => RowVersion::etag($fresh->version)],
        );
    }

    public function destroy(Request $request, Item $item): JsonResponse
    {
        abort_unless($item->tenant_id === $this->writeTenant($request), 404);

        $expectedVersion = RowVersion::expected($request);

        DB::transaction(function () use ($item, $expectedVersion): void {
            RowVersion::claim($item, $expectedVersion);
            $item->delete();
        });

        return response()->json(status: 204);
    }

    private function tenant(Request $request): string
    {
        $membership = app(CurrentWorkspace::class)->membership($request);
        abort_unless($membership !== null, 403);

        return (string) $membership->tenant_id;
    }

    private function writeTenant(Request $request): string
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        return $this->tenant($request);
    }
}
