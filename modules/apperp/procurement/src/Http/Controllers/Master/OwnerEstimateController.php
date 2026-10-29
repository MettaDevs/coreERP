<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\Master;

use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Modules\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\Procurement\Models\Master\OwnerEstimate;

final class OwnerEstimateController extends Controller
{
    /**
     * Daftar harga perkiraan secara bertahap (cursor/incremental).
     */
    public function index(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.harga-perkiraan.read');
        $tenantId = $tenant->tenantId();

        $filters = $request->validate([
            'group_item' => ['nullable', 'string', 'max:100'],
            'jenis_item' => ['nullable', 'string', 'max:100'],
        ]);

        $query = OwnerEstimate::query()
            ->where('tenant_id', $tenantId)
            ->select([
                'id',
                'tenant_id',
                'group_item',
                'jenis_item',
                'kode_item',
                'nama_item',
                'satuan',
                'harga_perkiraan',
                'tanggal_update',
                'keterangan',
                'version',
                'created_at',
                'updated_at',
            ]);

        if (($filters['group_item'] ?? '') !== '' && ($filters['group_item'] ?? null) !== null) {
            $query->where('group_item', $filters['group_item']);
        }

        if (($filters['jenis_item'] ?? '') !== '' && ($filters['jenis_item'] ?? null) !== null) {
            $query->where('jenis_item', $filters['jenis_item']);
        }

        $batch = IncrementalList::query(
            $request,
            $query->toBase(),
            [
                'id' => 'id',
                'kode_item' => 'kode_item',
                'nama_item' => 'nama_item',
                'group_item' => 'group_item',
                'jenis_item' => 'jenis_item',
                'satuan' => 'satuan',
                'harga_perkiraan' => 'harga_perkiraan',
                'tanggal_update' => 'tanggal_update',
            ],
            [
                'kode_item',
                'nama_item',
                'group_item',
                'jenis_item',
                'satuan',
                'keterangan',
            ],
            defaultSort: 'kode_item',
            defaultDirection: 'asc',
        );

        $batch['data'] = OwnerEstimate::hydrate(
            $batch['data']->map(static fn ($row): array => (array) $row)->all(),
        )->map(fn (OwnerEstimate $record): array => $this->presentRecord($record))->values();

        return response()->json($batch);
    }

    /**
     * Detail satu harga perkiraan untuk card detail/edit.
     */
    public function show(
        RequestContext $context,
        TenantContext $tenant,
        string $id,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.harga-perkiraan.read');
        $tenantId = $tenant->tenantId();

        $record = OwnerEstimate::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(
            ['data' => $this->presentRecord($record)],
            200,
            ['ETag' => RowVersion::etag($record->version)],
        );
    }

    /**
     * Simpan harga perkiraan baru secara idempotent.
     */
    public function store(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.harga-perkiraan.create');
        $tenantId = $tenant->tenantId();

        $creationKey = (string) $request->header('Idempotency-Key');
        validator(
            ['key' => $creationKey],
            ['key' => ['required', 'string', 'max:133', 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate();

        $validated = $this->validatePayload($request);
        $payload = $this->buildPayload($validated, $context);
        $payloadHash = $this->computePayloadHash($payload);

        $existing = OwnerEstimate::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('creation_key', $creationKey)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $payloadHash);
        }

        $duplicateExists = OwnerEstimate::query()
            ->where('tenant_id', $tenantId)
            ->where('kode_item', $payload['kode_item'])
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'kode_item' => 'Kode item ini sudah digunakan oleh harga perkiraan aktif lain.',
            ]);
        }

        try {
            $record = DB::transaction(function () use (
                $tenantId,
                $creationKey,
                $payload,
                $payloadHash,
            ): OwnerEstimate {
                $record = OwnerEstimate::query()->create([
                    'tenant_id' => $tenantId,
                    'creation_key' => $creationKey,
                    'creation_payload_hash' => $payloadHash,
                    ...$payload,
                ]);

                return $record->refresh();
            });
        } catch (QueryException $exception) {
            $existing = OwnerEstimate::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('creation_key', $creationKey)
                ->first();

            if ($existing !== null) {
                return $this->replay($existing, $payloadHash);
            }

            if ($this->isDuplicateItemCodeException($exception)) {
                throw ValidationException::withMessages([
                    'kode_item' => 'Kode item ini sudah digunakan oleh harga perkiraan aktif lain.',
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
     * Perbarui harga perkiraan yang sudah ada dengan pengaman versi baris (RowVersion).
     */
    public function update(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
        string $id,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.harga-perkiraan.update');
        $tenantId = $tenant->tenantId();
        $expectedVersion = RowVersion::expected($request);

        $validated = $this->validatePayload($request);
        $payload = $this->buildPayload($validated, $context);

        try {
            return DB::transaction(function () use (
                $tenantId,
                $id,
                $expectedVersion,
                $payload,
            ): JsonResponse {
                $record = OwnerEstimate::query()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($id);

                RowVersion::claim($record, $expectedVersion);

                if ($payload['kode_item'] !== $record->kode_item) {
                    $duplicateExists = OwnerEstimate::query()
                        ->where('tenant_id', $tenantId)
                        ->where('kode_item', $payload['kode_item'])
                        ->whereKeyNot($record->getKey())
                        ->exists();

                    if ($duplicateExists) {
                        throw ValidationException::withMessages([
                            'kode_item' => 'Kode item ini sudah digunakan oleh harga perkiraan aktif lain.',
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
            if ($this->isDuplicateItemCodeException($exception)) {
                throw ValidationException::withMessages([
                    'kode_item' => 'Kode item ini sudah digunakan oleh harga perkiraan aktif lain.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Arsipkan (soft delete) harga perkiraan dengan pengaman versi baris.
     */
    public function destroy(
        Request $request,
        RequestContext $context,
        TenantContext $tenant,
        string $id,
    ): JsonResponse {
        $this->requirePermission($context, 'procurement.harga-perkiraan.archive');
        $tenantId = $tenant->tenantId();
        $expectedVersion = RowVersion::expected($request);

        return DB::transaction(function () use ($tenantId, $id, $expectedVersion): JsonResponse {
            $record = OwnerEstimate::query()
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
        $input = $request->all();

        if (isset($input['harga_perkiraan']) && is_string($input['harga_perkiraan'])) {
            $input['harga_perkiraan'] = trim($input['harga_perkiraan']);
        }

        return validator($input, [
            'group_item' => ['required', 'string', 'max:100'],
            'jenis_item' => ['required', 'string', 'max:100'],
            'kode_item' => ['required', 'string', 'max:100'],
            'nama_item' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:50'],
            'harga_perkiraan' => [
                'required',
                'numeric',
                'min:0',
                'max:9999999999999999.99',
                'regex:/^\d{1,16}(\.\d{1,2})?$/',
            ],
            'tanggal_update' => ['nullable', 'date_format:Y-m-d'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
        ], [
            'group_item.required' => 'Group item wajib diisi.',
            'jenis_item.required' => 'Jenis item wajib diisi.',
            'kode_item.required' => 'Kode item wajib diisi.',
            'nama_item.required' => 'Nama item wajib diisi.',
            'satuan.required' => 'Satuan wajib diisi.',
            'harga_perkiraan.required' => 'Harga perkiraan wajib diisi.',
            'harga_perkiraan.numeric' => 'Harga perkiraan harus berupa angka desimal yang valid.',
            'harga_perkiraan.min' => 'Harga perkiraan tidak boleh kurang dari 0.',
            'harga_perkiraan.max' => 'Harga perkiraan melebihi batas maksimum yang diizinkan.',
            'harga_perkiraan.regex' => 'Harga perkiraan maksimal 16 digit bulat dan 2 angka desimal.',
            'tanggal_update.date_format' => 'Format tanggal update harus YYYY-MM-DD.',
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     group_item: string,
     *     jenis_item: string,
     *     kode_item: string,
     *     nama_item: string,
     *     satuan: string,
     *     harga_perkiraan: string,
     *     tanggal_update: string,
     *     keterangan: ?string
     * }
     */
    private function buildPayload(array $validated, RequestContext $context): array
    {
        return [
            'group_item' => trim((string) $validated['group_item']),
            'jenis_item' => trim((string) $validated['jenis_item']),
            'kode_item' => trim((string) $validated['kode_item']),
            'nama_item' => trim((string) $validated['nama_item']),
            'satuan' => trim((string) $validated['satuan']),
            'harga_perkiraan' => $this->formatCanonicalDecimal((string) $validated['harga_perkiraan']),
            'tanggal_update' => $this->trimmedOrNull($validated['tanggal_update'] ?? null)
                ?? CarbonImmutable::now($context->timezone())->toDateString(),
            'keterangan' => $this->trimmedOrNull($validated['keterangan'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function computePayloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function replay(OwnerEstimate $existing, string $payloadHash): JsonResponse
    {
        $storedHash = $existing->creation_payload_hash;

        if ($storedHash !== null && ! hash_equals($storedHash, $payloadHash)) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_conflict',
                    'message' => 'Kunci permintaan sudah dipakai untuk data harga perkiraan yang berbeda.',
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
    private function presentRecord(OwnerEstimate $record): array
    {
        return [
            'id' => $record->id,
            'group_item' => $record->group_item,
            'jenis_item' => $record->jenis_item,
            'kode_item' => $record->kode_item,
            'nama_item' => $record->nama_item,
            'satuan' => $record->satuan,
            'harga_perkiraan' => $record->harga_perkiraan,
            'tanggal_update' => $record->tanggal_update?->format('Y-m-d')
                ?? ($record->getRawOriginal('tanggal_update') !== null ? (string) $record->getRawOriginal('tanggal_update') : null),
            'keterangan' => $record->keterangan,
            'version' => $record->version,
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    private function formatCanonicalDecimal(string $value): string
    {
        $raw = trim($value);
        [$integerPart, $fractionPart] = array_pad(explode('.', $raw, 2), 2, '');
        $normalizedInt = ltrim($integerPart, '0');
        if ($normalizedInt === '') {
            $normalizedInt = '0';
        }

        return $normalizedInt.'.'.str_pad(substr($fractionPart, 0, 2), 2, '0');
    }

    private function isDuplicateItemCodeException(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return $exception->getCode() === '23505'
            && str_contains($message, 'procurement_harga_perkiraan_kode_item_unique');
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

