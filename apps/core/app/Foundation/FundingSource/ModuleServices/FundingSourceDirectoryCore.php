<?php

declare(strict_types=1);

namespace App\Foundation\FundingSource\ModuleServices;

use App\Foundation\FundingSource\Models\FundingSource;
use App\Platform\Modules\Contracts\FundingSourceDirectory;
use App\Platform\Modules\Contracts\IncrementalList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FundingSourceDirectoryCore implements FundingSourceDirectory
{
    public function lookup(string $tenantId, array $input): array
    {
        $selected = isset($input['record_ids']) || isset($input['record_id']);
        $query = FundingSource::query()->where('tenant_id', $tenantId)
            ->select(['id', 'name', 'description', 'active', 'version']);
        if ($selected) {
            $query->withTrashed();
        } else {
            $query->where('active', true);
        }

        return IncrementalList::query(Request::create('/lookup', 'GET', $input), $query->toBase(),
            ['id' => 'id', 'name' => 'name', 'description' => 'description'], ['name', 'description'], 'name');
    }

    public function resolve(string $tenantId, array $ids): array
    {
        return FundingSource::query()->where('tenant_id', $tenantId)->whereIn('id', array_unique($ids))
            ->get(['id', 'name', 'description', 'active', 'version'])->keyBy('id')->toArray();
    }

    public function importLegacy(string $tenantId, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Sumber dana lama harus memiliki nama.');
        }

        return DB::transaction(function () use ($tenantId, $name): array {
            $key = 'legacy:'.hash('sha256', $name);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$tenantId.':funding-source:'.$key]);
            $source = FundingSource::withTrashed()->where(['tenant_id' => $tenantId, 'creation_key' => $key])->first()
                ?? FundingSource::withTrashed()->where('tenant_id', $tenantId)->where('name', $name)->orderBy('id')->first();
            if ($source === null) {
                $source = FundingSource::query()->create(['tenant_id' => $tenantId,
                    'code' => 'LEGACY-'.strtoupper(substr(hash('sha256', $name), 0, 24)),
                    'name' => $name, 'active' => true, 'creation_key' => $key]);
                $source->refresh();
            }

            return $source->only(['id', 'name', 'description', 'active', 'version']);
        });
    }
}
