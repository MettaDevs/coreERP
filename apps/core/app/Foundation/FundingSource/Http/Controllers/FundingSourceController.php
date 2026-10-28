<?php

namespace App\Foundation\FundingSource\Http\Controllers;

use App\Foundation\FundingSource\Models\FundingSource;
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

final class FundingSourceController extends Controller
{
    public function page(Request $request): Response
    {
        $this->tenant($request);

        return Inertia::render('foundation/funding-source/funding-sources', [
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = FundingSource::query()->where('tenant_id', $this->tenant($request));
        $request->validate(['status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        if ($request->filled('status')) {
            $query->where('active', $request->input('status') === 'active');
        }

        return response()->json(IncrementalList::query($request, $query->select(['id', 'name', 'description', 'active', 'version'])->toBase(),
            ['id' => 'id', 'name' => 'name', 'description' => 'description'], ['name', 'description'], 'name'));
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->writeTenant($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'Nama', 'description' => 'Deskripsi']);
        $data = ['name' => trim($data['name']), 'description' => filled($data['description'] ?? null) ? trim($data['description']) : null];
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Nama wajib diisi.']);
        }
        $creationKey = (string) $request->header('Idempotency-Key');
        validator(['key' => $creationKey], ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($tenantId, $creationKey, $hash, $data): JsonResponse {
                if ($existing = FundingSource::withTrashed()->where(['tenant_id' => $tenantId, 'creation_key' => $creationKey])->first()) {
                    abort_unless(hash_equals((string) $existing->creation_payload_hash, $hash), 409, 'Permintaan ini sudah dipakai untuk isian yang berbeda.');

                    return response()->json(['data' => $existing]);
                }
                $source = FundingSource::query()->create(['tenant_id' => $tenantId, 'creation_key' => $creationKey,
                    'creation_payload_hash' => $hash, ...$data, 'active' => true]);

                return response()->json(['data' => $source->refresh()], 201);
            });
        } catch (QueryException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }
            $existing = FundingSource::withTrashed()->where(['tenant_id' => $tenantId, 'creation_key' => $creationKey])->first();
            if ($existing !== null) {
                abort_unless(hash_equals((string) $existing->creation_payload_hash, $hash), 409, 'Permintaan ini sudah dipakai untuk isian yang berbeda.');

                return response()->json(['data' => $existing]);
            }
            throw $error;
        }
    }

    public function update(Request $request, FundingSource $fundingSource): JsonResponse
    {
        abort_unless($fundingSource->tenant_id === $this->writeTenant($request), 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:250'], 'description' => ['nullable', 'string', 'max:2000'], 'active' => ['required', 'boolean']], [], ['name' => 'Nama', 'description' => 'Deskripsi', 'active' => 'Status aktif']);
        $data['name'] = trim($data['name']);
        if (array_key_exists('description', $data)) {
            $data['description'] = filled($data['description']) ? trim($data['description']) : null;
        }
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Nama wajib diisi.']);
        }
        DB::transaction(function () use ($request, $fundingSource, $data): void {
            RowVersion::claim($fundingSource, RowVersion::expected($request));
            $fundingSource->update($data);
        });

        return response()->json(['data' => $fundingSource->refresh()]);
    }

    public function destroy(Request $request, FundingSource $fundingSource): JsonResponse
    {
        abort_unless($fundingSource->tenant_id === $this->writeTenant($request), 404);
        DB::transaction(function () use ($request, $fundingSource): void {
            RowVersion::claim($fundingSource, RowVersion::expected($request));
            $fundingSource->delete();
        });

        return response()->json(null, 204);
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
