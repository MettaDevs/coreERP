<?php

namespace App\Http\Controllers;

use App\Services\CoreAccessClient;
use App\Services\CoreDirectoryClient;
use App\Services\NumberSequenceClient;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HumanResourcesController extends Controller
{
    public function operatingUnits(Request $request, CoreDirectoryClient $core): JsonResponse
    {
        $this->requirePermission($request, 'positions', 'read');

        return response()->json([
            'data' => $core->operatingUnits($this->tenantId($request)),
        ]);
    }

    public function coreMembers(Request $request, CoreDirectoryClient $core): JsonResponse
    {
        $this->requirePermission($request, 'core-account-link', 'invoke');
        $query = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json([
            'data' => $core->members($this->tenantId($request), $query['q'] ?? ''),
        ]);
    }

    public function workers(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'workers', 'read');

        $tenantId = $this->tenantId($request);
        $query = DB::table('hr_workers as worker')
            ->where('worker.tenant_id', $tenantId)
            ->orderBy('worker.name');

        if (! $this->hasTenantWideScope($request)) {
            $query->whereExists(function ($assignments) use ($tenantId, $request): void {
                $assignments->selectRaw('1')
                    ->from('hr_worker_position_assignments as assignment')
                    ->join('hr_positions as position', function ($join): void {
                        $join->on('position.id', '=', 'assignment.position_id')
                            ->on('position.tenant_id', '=', 'assignment.tenant_id');
                    })
                    ->whereColumn('assignment.worker_id', 'worker.id')
                    ->where('assignment.tenant_id', $tenantId)
                    ->whereDate('assignment.valid_from', '<=', today())
                    ->where(fn ($dates) => $dates->whereNull('assignment.valid_until')->orWhereDate('assignment.valid_until', '>=', today()))
                    ->whereIn('position.operating_unit_id', $this->operatingUnitIds($request));
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'jobs', 'read');

        return response()->json(['data' => DB::table('hr_jobs')
            ->where('tenant_id', $this->tenantId($request))
            ->orderBy('name')
            ->get()]);
    }

    public function positions(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'positions', 'read');

        $query = DB::table('hr_positions')
            ->where('tenant_id', $this->tenantId($request))
            ->orderBy('name');
        $this->applyOperatingUnitScope($query, $request);

        return response()->json(['data' => $query->get()]);
    }

    public function assignments(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'assignments', 'read');

        $query = DB::table('hr_worker_position_assignments as assignment')
            ->join('hr_positions as position', function ($join): void {
                $join->on('position.id', '=', 'assignment.position_id')
                    ->on('position.tenant_id', '=', 'assignment.tenant_id');
            })
            ->where('assignment.tenant_id', $this->tenantId($request))
            ->select('assignment.*')
            ->orderByDesc('assignment.valid_from');
        $this->applyOperatingUnitScope($query, $request, 'position.operating_unit_id');

        return response()->json(['data' => $query->get()]);
    }

    public function storeWorker(Request $request, NumberSequenceClient $numbers, CoreDirectoryClient $core): JsonResponse
    {
        $this->requirePermission($request, 'workers', 'create');
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'core_membership_id' => ['nullable', 'ulid'],
        ]);

        if ($data['core_membership_id'] ?? null) {
            $this->requirePermission($request, 'core-account-link', 'invoke');
            $core->member($tenantId, $data['core_membership_id']);
        }
        abort_if(! $this->hasTenantWideScope($request), 403, 'Pekerja tanpa posisi hanya dapat dibuat oleh pengguna dengan akses seluruh organisasi.');

        $idempotencyKey = $this->idempotencyKey($request);
        $existing = DB::table('hr_workers')
            ->where(['tenant_id' => $tenantId, 'creation_key' => $idempotencyKey])
            ->first();

        if ($existing) {
            return response()->json(['data' => $existing]);
        }

        $worker = [
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'creation_key' => $idempotencyKey,
            'personnel_number' => $numbers->issue('human-resources.pekerja', $tenantId, 'worker:'.$idempotencyKey),
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('hr_workers')->insert($worker);

        return response()->json(['data' => $worker], 201);
    }

    public function storeJob(Request $request, NumberSequenceClient $numbers): JsonResponse
    {
        $this->requirePermission($request, 'jobs', 'create');
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $idempotencyKey = $this->idempotencyKey($request);

        $existing = DB::table('hr_jobs')
            ->where(['tenant_id' => $tenantId, 'creation_key' => $idempotencyKey])
            ->first();

        if ($existing) {
            return response()->json(['data' => $existing]);
        }

        $job = [
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'creation_key' => $idempotencyKey,
            'code' => $numbers->issue('human-resources.jabatan', $tenantId, 'job:'.$idempotencyKey),
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('hr_jobs')->insert($job);

        return response()->json(['data' => $job], 201);
    }

    public function storePosition(Request $request, NumberSequenceClient $numbers): JsonResponse
    {
        $this->requirePermission($request, 'positions', 'create');
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'job_id' => ['required', 'ulid'],
            'operating_unit_id' => ['required', 'ulid'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
        ]);

        abort_unless(DB::table('hr_jobs')->where(['id' => $data['job_id'], 'tenant_id' => $tenantId])->exists(), 422, 'Jabatan tidak tersedia.');
        $this->requireOperatingUnit($request, $data['operating_unit_id']);

        $idempotencyKey = $this->idempotencyKey($request);

        $existing = DB::table('hr_positions')
            ->where(['tenant_id' => $tenantId, 'creation_key' => $idempotencyKey])
            ->first();

        if ($existing) {
            return response()->json(['data' => $existing]);
        }

        $position = [
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'creation_key' => $idempotencyKey,
            'code' => $numbers->issue('human-resources.posisi', $tenantId, 'position:'.$idempotencyKey),
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('hr_positions')->insert($position);

        return response()->json(['data' => $position], 201);
    }

    public function storeAssignment(Request $request, CoreAccessClient $core): JsonResponse
    {
        $this->requirePermission($request, 'assignments', 'create');
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'worker_id' => ['required', 'ulid'],
            'position_id' => ['required', 'ulid'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'is_primary' => ['required', 'boolean'],
        ]);

        $worker = DB::table('hr_workers')->where(['id' => $data['worker_id'], 'tenant_id' => $tenantId])->first();
        $position = DB::table('hr_positions')->where(['id' => $data['position_id'], 'tenant_id' => $tenantId])->first();
        abort_unless($worker && $position, 422, 'Pekerja atau posisi tidak tersedia.');
        $this->requireOperatingUnit($request, $position->operating_unit_id);

        $positionIsFilled = DB::table('hr_worker_position_assignments')
            ->where(['tenant_id' => $tenantId, 'position_id' => $data['position_id']])
            ->where('valid_from', '<=', $data['valid_until'] ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $data['valid_from']))
            ->exists();
        abort_if($positionIsFilled, 422, 'Posisi sudah terisi pada periode tersebut.');

        $assignment = [
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('hr_worker_position_assignments')->insert($assignment);

        if ($worker->core_membership_id) {
            $core->positionAssignment($tenantId, [
                'membership_id' => $worker->core_membership_id,
                'position_id' => $position->id,
                'operating_unit_id' => $position->operating_unit_id,
                'assignment_id' => $assignment['id'],
                'active' => true,
            ]);
        }

        return response()->json(['data' => $assignment], 201);
    }

    private function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }

    private function requirePermission(Request $request, string $resource, string $action): void
    {
        $permission = "human-resources.{$resource}.{$action}";
        abort_unless(in_array($permission, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function idempotencyKey(Request $request): string
    {
        return (string) $request->validate([
            'idempotency_key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ])['idempotency_key'];
    }

    private function hasTenantWideScope(Request $request): bool
    {
        return (bool) ($this->policyScope($request)['all'] ?? false);
    }

    /** @return list<string> */
    private function operatingUnitIds(Request $request): array
    {
        return array_values(array_filter(
            collect($this->policyScope($request)['scope_grants'] ?? [])
                ->filter(fn (mixed $grant): bool => is_array($grant))
                ->flatMap(fn (array $grant): array => $grant['operating_unit_ids'] ?? [])
                ->unique()
                ->all(),
            'is_string',
        ));
    }

    private function applyOperatingUnitScope(Builder $query, Request $request, string $column = 'operating_unit_id'): void
    {
        if (! $this->hasTenantWideScope($request)) {
            $query->whereIn($column, $this->operatingUnitIds($request) ?: ['__none__']);
        }
    }

    private function requireOperatingUnit(Request $request, string $operatingUnitId): void
    {
        abort_unless($this->hasTenantWideScope($request) || in_array($operatingUnitId, $this->operatingUnitIds($request), true), 403, 'Unit kerja ini berada di luar akses Anda.');
    }

    /** @return array<string, mixed> */
    private function policyScope(Request $request): array
    {
        $policies = $request->attributes->get('coreerp.data_policies', []);

        return is_array($policies) && is_array($policies['human-resources.workforce-responsibility'] ?? null)
            ? $policies['human-resources.workforce-responsibility']
            : [];
    }
}
