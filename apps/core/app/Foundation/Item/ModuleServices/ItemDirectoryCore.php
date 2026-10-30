<?php

declare(strict_types=1);

namespace App\Foundation\Item\ModuleServices;

use App\Foundation\Item\Models\Item;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\ItemDirectory;
use Illuminate\Validation\Rule;

final class ItemDirectoryCore implements ItemDirectory
{
    public function lookup(string $tenantId, array $input): array
    {
        $validated = validator($input, [
            'q' => ['nullable', 'string', 'max:250'],
            'type' => ['nullable', Rule::in(['service', 'inventory', 'non_inventory'])],
            'record_ids' => ['nullable', 'array'],
            'record_ids.*' => ['string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ])->validate();

        $query = Item::query()
            ->where('tenant_id', $tenantId);

        if (! empty($validated['record_ids'])) {
            $query->withTrashed()->whereIn('id', $validated['record_ids']);
        } else {
            if (isset($validated['status'])) {
                $query->where('active', $validated['status'] === 'active');
            } else {
                $query->where('active', true);
            }

            if (! empty($validated['type'])) {
                $query->where('type', $validated['type']);
            }
        }

        return IncrementalList::query(
            request(),
            $query->select(['id', 'code', 'name', 'type', 'unit_of_measure_id', 'description', 'active'])->toBase(),
            ['id' => 'id', 'code' => 'code', 'name' => 'name'],
            ['code', 'name', 'description'],
            defaultSort: 'code',
            defaultDirection: 'asc',
        );
    }

    public function resolve(string $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Item::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'name', 'type', 'unit_of_measure_id', 'description', 'active', 'version'])
            ->keyBy('id')
            ->map(fn (Item $record): array => [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'type' => $record->type,
                'unit_of_measure_id' => $record->unit_of_measure_id,
                'description' => $record->description,
                'active' => (bool) $record->active,
                'version' => (int) $record->version,
            ])
            ->all();
    }

    public function active(string $tenantId, ?string $type = null): array
    {
        $query = Item::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true);

        if ($type !== null) {
            $query->where('type', $type);
        }

        $records = $query->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'unit_of_measure_id']);

        $result = [];

        foreach ($records as $record) {
            $result[] = [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'type' => $record->type,
                'unit_of_measure_id' => $record->unit_of_measure_id,
            ];
        }

        return $result;
    }
}
