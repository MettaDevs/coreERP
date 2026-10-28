<?php

namespace App\Foundation\PlanningYear\Http\Controllers;

use App\Foundation\PlanningYear\Models\PlanningYear;
use App\Http\Controllers\Controller;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PlanningYearController extends Controller
{
    public function page(Request $request): Response
    {
        $workspace = app(CurrentWorkspace::class);
        $membership = $workspace->membership($request);
        abort_unless($membership !== null, 403);
        $companyId = $request->input('legal_entity_id') ?? $workspace->legalEntity($request, $membership)?->id;
        $this->scope($request, $companyId);

        return Inertia::render('foundation/planning-year/planning-years', [
            'legalEntityId' => $companyId,
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        [$tenantId, $companyId] = $this->scope($request, $request->input('legal_entity_id'));
        $query = PlanningYear::query()->where('tenant_id', $tenantId)->where('legal_entity_id', $companyId);

        return response()->json(IncrementalList::query($request,
            $query->select(['id', 'legal_entity_id', 'year', 'active', 'version'])->toBase(),
            ['id' => 'id', 'year' => 'year'], ['year'], 'year', ['year'], 'desc'));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);
        $data = $request->validate(['legal_entity_id' => ['required', 'ulid'], 'year' => ['required', 'integer', 'between:2000,2100']], [], ['year' => 'Tahun rencana', 'legal_entity_id' => 'Perusahaan']);
        [$tenantId, $companyId] = $this->scope($request, $data['legal_entity_id']);
        $data = ['legal_entity_id' => $companyId, 'year' => (int) $data['year']];
        $key = (string) $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($tenantId, $key, $hash, $data): JsonResponse {
                if ($record = PlanningYear::withTrashed()->where(['tenant_id' => $tenantId, 'creation_key' => $key])->first()) {
                    abort_unless(hash_equals((string) $record->creation_payload_hash, $hash), 409, 'Permintaan ini sudah dipakai untuk tahun atau perusahaan yang berbeda.');

                    return response()->json(['data' => $record]);
                }
                $record = PlanningYear::query()->create(['tenant_id' => $tenantId, 'creation_key' => $key, 'creation_payload_hash' => $hash, ...$data, 'active' => true])->refresh();

                return response()->json(['data' => $record], 201);
            });
        } catch (QueryException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }
            $record = PlanningYear::withTrashed()->where(['tenant_id' => $tenantId, 'creation_key' => $key])->first();
            if ($record !== null) {
                abort_unless(hash_equals((string) $record->creation_payload_hash, $hash), 409, 'Permintaan ini sudah dipakai untuk tahun atau perusahaan yang berbeda.');

                return response()->json(['data' => $record]);
            }
            throw ValidationException::withMessages(['year' => 'Tahun rencana ini sudah tersedia pada perusahaan tersebut.']);
        }
    }

    public function update(Request $request, PlanningYear $planningYear): JsonResponse
    {
        $this->writeScope($request, $planningYear);
        $data = $request->validate(['active' => ['required', 'boolean'], 'year' => ['prohibited'], 'legal_entity_id' => ['prohibited']]);
        DB::transaction(function () use ($request, $planningYear, $data): void {
            RowVersion::claim($planningYear, RowVersion::expected($request));
            $planningYear->update($data);
        });

        return response()->json(['data' => $planningYear->refresh()]);
    }

    public function destroy(Request $request, PlanningYear $planningYear): JsonResponse
    {
        $this->writeScope($request, $planningYear);
        DB::transaction(function () use ($request, $planningYear): void {
            RowVersion::claim($planningYear, RowVersion::expected($request));
            $planningYear->delete();
        });

        return response()->json(null, 204);
    }

    private function writeScope(Request $request, PlanningYear $planningYear): void
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);
        [$tenantId] = $this->scope($request, $planningYear->legal_entity_id);
        abort_unless($planningYear->tenant_id === $tenantId, 404);
    }

    /** @return array{string, string} */
    private function scope(Request $request, mixed $companyId): array
    {
        validator(['legal_entity_id' => $companyId], ['legal_entity_id' => ['required', 'ulid']])->validate();
        $workspace = app(CurrentWorkspace::class);
        $membership = $workspace->membership($request);
        abort_unless($membership !== null, 403);
        abort_unless($workspace->organizations($membership)->contains(fn ($organization) => $organization->id === $companyId && $organization->classification === 'legal_entity'), 403, 'Perusahaan ini tidak termasuk akses Anda.');

        return [(string) $membership->tenant_id, (string) $companyId];
    }
}
