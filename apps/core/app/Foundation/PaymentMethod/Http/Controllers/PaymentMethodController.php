<?php

namespace App\Foundation\PaymentMethod\Http\Controllers;

use App\Foundation\PaymentMethod\Models\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PaymentMethodController extends Controller
{
    public function page(Request $request): Response
    {
        $this->tenant($request);

        return Inertia::render('foundation/payment-method/payment-methods', [
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenant($request);

        $request->validate([
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'q' => ['nullable', 'string', 'max:250'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        $query = PaymentMethod::query()->where('tenant_id', $tenantId);

        if ($request->filled('status')) {
            $query->where('active', $request->input('status') === 'active');
        }

        $batch = IncrementalList::query(
            $request,
            $query->select(['id', 'code', 'name', 'description', 'active', 'version'])->toBase(),
            ['id' => 'id', 'code' => 'code', 'name' => 'name'],
            ['code', 'name', 'description'],
            defaultSort: 'code',
            defaultDirection: 'asc',
        );

        return response()->json($batch);
    }

    public function show(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        abort_unless($paymentMethod->tenant_id === $this->tenant($request), 404);

        return response()->json(
            ['data' => $paymentMethod],
            200,
            ['ETag' => RowVersion::etag($paymentMethod->version)],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->writeTenant($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['nullable', 'boolean'],
        ], [
            'code.required' => 'Kode metode pembayaran wajib diisi.',
            'name.required' => 'Nama metode pembayaran wajib diisi.',
        ]);

        $payload = [
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'active' => isset($data['active']) ? (bool) $data['active'] : true,
        ];

        if ($payload['code'] === '') {
            throw ValidationException::withMessages(['code' => 'Kode metode pembayaran wajib diisi.']);
        }
        if ($payload['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Nama metode pembayaran wajib diisi.']);
        }

        $creationKey = (string) $request->header('Idempotency-Key');
        validator(
            ['key' => $creationKey],
            ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate();

        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $existing = PaymentMethod::withTrashed()
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

        $duplicateExists = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $payload['code'])
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'code' => 'Kode metode pembayaran ini sudah digunakan oleh metode pembayaran aktif lain.',
            ]);
        }

        try {
            $record = DB::transaction(function () use ($tenantId, $creationKey, $hash, $payload): PaymentMethod {
                $record = PaymentMethod::query()->create([
                    'tenant_id' => $tenantId,
                    'creation_key' => $creationKey,
                    'creation_payload_hash' => $hash,
                    ...$payload,
                ]);

                return $record->refresh();
            });
        } catch (QueryException $error) {
            $existing = PaymentMethod::withTrashed()
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

            if ($error->getCode() === '23505' && str_contains($error->getMessage(), 'payment_methods_active_code_unique')) {
                throw ValidationException::withMessages([
                    'code' => 'Kode metode pembayaran ini sudah digunakan oleh metode pembayaran aktif lain.',
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

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        abort_unless($paymentMethod->tenant_id === $this->writeTenant($request), 404);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ], [
            'code.required' => 'Kode metode pembayaran wajib diisi.',
            'name.required' => 'Nama metode pembayaran wajib diisi.',
            'active.required' => 'Status aktif wajib diisi.',
        ]);

        $payload = [
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'active' => (bool) $data['active'],
        ];

        if ($payload['code'] === '') {
            throw ValidationException::withMessages(['code' => 'Kode metode pembayaran wajib diisi.']);
        }
        if ($payload['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Nama metode pembayaran wajib diisi.']);
        }

        $expectedVersion = RowVersion::expected($request);

        try {
            DB::transaction(function () use ($paymentMethod, $expectedVersion, $payload): void {
                RowVersion::claim($paymentMethod, $expectedVersion);

                if ($payload['code'] !== $paymentMethod->code) {
                    $duplicateExists = PaymentMethod::query()
                        ->where('tenant_id', $paymentMethod->tenant_id)
                        ->where('code', $payload['code'])
                        ->whereKeyNot($paymentMethod->getKey())
                        ->exists();

                    if ($duplicateExists) {
                        throw ValidationException::withMessages([
                            'code' => 'Kode metode pembayaran ini sudah digunakan oleh metode pembayaran aktif lain.',
                        ]);
                    }
                }

                $paymentMethod->update($payload);
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505' && str_contains($error->getMessage(), 'payment_methods_active_code_unique')) {
                throw ValidationException::withMessages([
                    'code' => 'Kode metode pembayaran ini sudah digunakan oleh metode pembayaran aktif lain.',
                ]);
            }

            throw $error;
        }

        $fresh = $paymentMethod->refresh();

        return response()->json(
            ['data' => $fresh],
            200,
            ['ETag' => RowVersion::etag($fresh->version)],
        );
    }

    public function updateStatus(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        abort_unless($paymentMethod->tenant_id === $this->writeTenant($request), 404);

        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ], [
            'active.required' => 'Status aktif wajib disertakan.',
            'active.boolean' => 'Format status aktif harus berupa boolean.',
        ]);

        $expectedVersion = RowVersion::expected($request);

        DB::transaction(function () use ($paymentMethod, $expectedVersion, $validated): void {
            RowVersion::claim($paymentMethod, $expectedVersion);
            $paymentMethod->update(['active' => (bool) $validated['active']]);
        });

        $fresh = $paymentMethod->refresh();

        return response()->json(
            ['data' => $fresh],
            200,
            ['ETag' => RowVersion::etag($fresh->version)],
        );
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        abort_unless($paymentMethod->tenant_id === $this->writeTenant($request), 404);

        $expectedVersion = RowVersion::expected($request);

        DB::transaction(function () use ($paymentMethod, $expectedVersion): void {
            RowVersion::claim($paymentMethod, $expectedVersion);
            $paymentMethod->delete();
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
