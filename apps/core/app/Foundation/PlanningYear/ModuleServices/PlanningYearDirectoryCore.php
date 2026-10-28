<?php

declare(strict_types=1);

namespace App\Foundation\PlanningYear\ModuleServices;

use App\Foundation\PlanningYear\Models\PlanningYear;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\PlanningYearDirectory;
use App\Platform\Organization\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlanningYearDirectoryCore implements PlanningYearDirectory
{
    public function lookup(string $tenantId, string $legalEntityId, array $input): array
    {
        $query = PlanningYear::query()->where('tenant_id', $tenantId)->where('legal_entity_id', $legalEntityId);
        if (isset($input['record_ids']) || isset($input['record_id'])) {
            $query->withTrashed();
        } else {
            $query->where('active', true);
        }

        return IncrementalList::query(Request::create('/lookup', 'GET', $input),
            $query->select(['id', 'legal_entity_id', 'year', 'active', 'version'])->toBase(),
            ['id' => 'id', 'year' => 'year'], ['year'], 'year', ['year'], 'desc');
    }

    public function resolve(string $tenantId, string $legalEntityId, array $ids): array
    {
        return PlanningYear::query()->where('tenant_id', $tenantId)->where('legal_entity_id', $legalEntityId)
            ->whereIn('id', array_unique($ids))->get(['id', 'legal_entity_id', 'year', 'active', 'version'])->keyBy('id')->toArray();
    }

    public function importLegacy(string $tenantId, string $legalEntityId, int $year): array
    {
        abort_unless(Organization::query()->where('tenant_id', $tenantId)->whereKey($legalEntityId)->where('classification', 'legal_entity')->exists(), 422);

        return DB::transaction(function () use ($tenantId, $legalEntityId, $year): array {
            $key = 'legacy:'.$legalEntityId.':'.$year;
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$tenantId.':planning-year:'.$key]);
            $record = PlanningYear::withTrashed()->where(['tenant_id' => $tenantId, 'legal_entity_id' => $legalEntityId, 'year' => $year])->orderByRaw('deleted_at IS NOT NULL')->orderBy('id')->first();
            $record ??= PlanningYear::query()->create(['tenant_id' => $tenantId, 'legal_entity_id' => $legalEntityId, 'year' => $year, 'active' => true, 'creation_key' => $key])->refresh();

            return $record->only(['id', 'legal_entity_id', 'year', 'active', 'version']);
        });
    }
}
