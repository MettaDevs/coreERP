<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\Master;

use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\Procurement\Models\Master\VendorType;

final class VendorTypeController extends Controller
{
    /**
     * Daftar jenis vendor secara bertahap (cursor/incremental).
     */
    public function index(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.jenis-vendor.read');
        $tenantId = $tenant->tenantId();

        $filters = $request->validate([
            'aktif' => ['nullable', 'string', Rule::in(['true', 'false', '1', '0'])],
        ]);

        $query = VendorType::query()
            ->where('tenant_id', $tenantId)
            ->select([
                'id',
                'tenant_id',
                'kode_jenis',
                'nama_jenis_vendor',
                'keterangan',
                'aktif',
                'version',
                'created_at',
                'updated_at',
            ]);

        if (isset($filters['aktif']) && $filters['aktif'] !== '') {
            $isActive = in_array($filters['aktif'], ['true', '1'], true);
            $query->where('aktif', $isActive);
        }

        $batch = IncrementalList::query(
            $request,
            $query->toBase(),
            [
                'id' => 'id',
                'kode_jenis' => 'kode_jenis',
                'nama_jenis_vendor' => 'nama_jenis_vendor',
                'aktif' => 'aktif',
                'created_at' => 'created_at',
            ],
            [
                'kode_jenis',
                'nama_jenis_vendor',
                'keterangan',
            ],
            defaultSort: 'kode_jenis',
            defaultDirection: 'asc',
        );

        $batch['data'] = VendorType::hydrate(
            $batch['data']->map(static fn ($row): array => (array) $row)->all(),
        )->map(fn (VendorType $record): array => $this->presentRecord($record))->values();

        return response()->json($batch);
    }

    /**
     * Detail satu jenis vendor.
     */
    public function show(
        RequestContext $context,
        TenantContext $tenant,
        string $id,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.jenis-vendor.read');
        $tenantId = $tenant->tenantId();

        $record = VendorType::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(
            ['data' => $this->presentRecord($record)],
            200,
            ['ETag' => RowVersion::etag($record->version)],
        );
    }

    /**
     * Simpan jenis vendor baru secara idempotent.
     */
    public function store(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.jenis-vendor.create');
        $tenantId = $tenant->tenantId();

        $creationKey = (string) $request->header('Idempotency-Key');
        validator(
            ['key' => $creationKey],
            ['key' => ['required', 'string', 'max:133', 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate();

        $validated = $this->validatePayload($request);
        $payload = $this->buildPayload($validated);
        $payloadHash = $this->computePayloadHash($payload);

        $existing = VendorType::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('creation_key', $creationKey)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $payloadHash);
        }

        $duplicateExists = VendorType::query()
            ->where('tenant_id', $tenantId)
            ->where('kode_jenis', $payload['kode_jenis'])
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'kode_jenis' => 'Kode jenis vendor ini sudah digunakan oleh jenis vendor aktif lain.',
            ]);
        }

        try {
            $record = DB::transaction(function () use (
                $tenantId,
                $creationKey,
                $payload,
                $payloadHash,
            ): VendorType {
                $record = VendorType::query()->create([
                    'tenant_id' => $tenantId,
                    'creation_key' => $creationKey,
                    'creation_payload_hash' => $payloadHash,
                    ...$payload,
                ]);

                return $record->refresh();
            });
        } catch (QueryException $exception) {
            $existing = VendorType::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('creation_key', $creationKey)
                ->first();

            if ($existing !== null) {
                return $this->replay($existing, $payloadHash);
            }

            if ($this->isDuplicateCodeException($exception)) {
                throw ValidationException::withMessages([
                    'kode_jenis' => 'Kode jenis vendor ini sudah digunakan oleh jenis vendor aktif lain.',
                ]);
            }

            throw $exception;
        }

        return response()->json(
            ['data' => $this->presentRecord($record)],
            201,
            [
                'Location' => $request->url().'/'.$record->getKey(),
                'ETag' => RowVersion::etag($record->version),
            ],
        );
    }

    /**
     * Perbarui jenis vendor yang sudah ada dengan pengaman versi baris (RowVersion).
     */
    public function update(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
        string $id,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.jenis-vendor.update');
        $tenantId = $tenant->tenantId();
        $expectedVersion = RowVersion::expected($request);

        $validated = $this->validatePayload($request);
        $payload = $this->buildPayload($validated);

        try {
            return DB::transaction(function () use (
                $tenantId,
                $id,
                $expectedVersion,
                $payload,
            ): JsonResponse {
                $record = VendorType::query()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($id);

                RowVersion::claim($record, $expectedVersion);

                if ($payload['kode_jenis'] !== $record->kode_jenis) {
                    $duplicateExists = VendorType::query()
                        ->where('tenant_id', $tenantId)
                        ->where('kode_jenis', $payload['kode_jenis'])
                        ->whereKeyNot($record->getKey())
                        ->exists();

                    if ($duplicateExists) {
                        throw ValidationException::withMessages([
                            'kode_jenis' => 'Kode jenis vendor ini sudah digunakan oleh jenis vendor aktif lain.',
                        ]);
                    }
                }

                $record->update($payload);
                $fresh = $record->refresh();

                return response()->json(
                    ['data' => $this->presentRecord($fresh)],
                    200,
                    ['ETag' => RowVersion::etag($fresh->version)],
                );
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateCodeException($exception)) {
                throw ValidationException::withMessages([
                    'kode_jenis' => 'Kode jenis vendor ini sudah digunakan oleh jenis vendor aktif lain.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Perbarui status aktif jenis vendor secara eksplisit dengan pengaman versi baris.
     */
    public function updateStatus(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
        string $id,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.jenis-vendor.update');
        $tenantId = $tenant->tenantId();
        $expectedVersion = RowVersion::expected($request);

        $validated = validator($request->all(), [
            'aktif' => ['required', 'boolean'],
        ], [
            'aktif.required' => 'Status aktif wajib disertakan.',
            'aktif.boolean' => 'Format status aktif harus berupa boolean.',
        ])->validate();

        return DB::transaction(function () use (
            $tenantId,
            $id,
            $expectedVersion,
            $validated,
        ): JsonResponse {
            $record = VendorType::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($id);

            RowVersion::claim($record, $expectedVersion);

            $record->update([
                'aktif' => (bool) $validated['aktif'],
            ]);

            $fresh = $record->refresh();

            return response()->json(
                ['data' => $this->presentRecord($fresh)],
                200,
                ['ETag' => RowVersion::etag($fresh->version)],
            );
        });
    }

    /**
     * Arsipkan (soft delete) jenis vendor dengan pengaman versi baris.
     */
    public function destroy(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
        string $id,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.jenis-vendor.archive');
        $tenantId = $tenant->tenantId();
        $expectedVersion = RowVersion::expected($request);

        return DB::transaction(function () use ($tenantId, $id, $expectedVersion): JsonResponse {
            $record = VendorType::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($id);

            RowVersion::claim($record, $expectedVersion);
            $record->delete();

            return response()->json(status: 204);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return validator($request->all(), [
            'kode_jenis' => ['required', 'string', 'max:100'],
            'nama_jenis_vendor' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'aktif' => ['nullable', 'boolean'],
        ], [
            'kode_jenis.required' => 'Kode jenis vendor wajib diisi.',
            'nama_jenis_vendor.required' => 'Nama jenis vendor wajib diisi.',
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     kode_jenis: string,
     *     nama_jenis_vendor: string,
     *     keterangan: ?string,
     *     aktif: bool
     * }
     */
    private function buildPayload(array $validated): array
    {
        return [
            'kode_jenis' => trim((string) $validated['kode_jenis']),
            'nama_jenis_vendor' => trim((string) $validated['nama_jenis_vendor']),
            'keterangan' => $this->trimmedOrNull($validated['keterangan'] ?? null),
            'aktif' => isset($validated['aktif']) ? (bool) $validated['aktif'] : true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function computePayloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function replay(VendorType $existing, string $payloadHash): JsonResponse
    {
        $storedHash = $existing->creation_payload_hash;

        if ($storedHash !== null && ! hash_equals($storedHash, $payloadHash)) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_conflict',
                    'message' => 'Kunci permintaan sudah dipakai untuk data jenis vendor yang berbeda.',
                ],
            ], 409);
        }

        return response()->json(
            ['data' => $this->presentRecord($existing)],
            200,
            ['Idempotent-Replayed' => 'true', 'ETag' => RowVersion::etag($existing->version)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRecord(VendorType $record): array
    {
        return [
            'id' => $record->id,
            'kode_jenis' => $record->kode_jenis,
            'nama_jenis_vendor' => $record->nama_jenis_vendor,
            'keterangan' => $record->keterangan,
            'aktif' => (bool) $record->aktif,
            'version' => $record->version,
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    private function isDuplicateCodeException(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return $exception->getCode() === '23505'
            && str_contains($message, 'procurement_jenis_vendor_kode_unique');
    }

    private function requirePermission(RequestContext $context, string $permission): void
    {
        if ($context->hasPermission($permission)) {
            return;
        }

        abort(response()->json([
            'error' => [
                'code' => 'forbidden',
                'message' => 'Hak '.$permission.' belum dimiliki pengguna pada tenant aktif.',
            ],
        ], 403));
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

