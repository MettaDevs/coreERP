<?php

declare(strict_types=1);

namespace App\Foundation\PaymentTerm\ModuleServices;

use App\Foundation\PaymentTerm\Models\PaymentTerm;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\PaymentTermDirectory;
use Illuminate\Validation\Rule;

final class PaymentTermDirectoryCore implements PaymentTermDirectory
{
    public function lookup(string $tenantId, array $input): array
    {
        $validated = validator($input, [
            'q' => ['nullable', 'string', 'max:250'],
            'record_ids' => ['nullable', 'array'],
            'record_ids.*' => ['string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ])->validate();

        $query = PaymentTerm::query()
            ->where('tenant_id', $tenantId);

        if (! empty($validated['record_ids'])) {
            $query->withTrashed()->whereIn('id', $validated['record_ids']);
        } else {
            if (isset($validated['status'])) {
                $query->where('active', $validated['status'] === 'active');
            } else {
                $query->where('active', true);
            }
        }

        return IncrementalList::query(
            request(),
            $query->select(['id', 'code', 'name', 'due_days', 'description', 'active'])->toBase(),
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

        return PaymentTerm::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'name', 'due_days', 'description', 'active', 'version'])
            ->keyBy('id')
            ->map(fn (PaymentTerm $record): array => [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'due_days' => (int) $record->due_days,
                'description' => $record->description,
                'active' => (bool) $record->active,
                'version' => (int) $record->version,
            ])
            ->all();
    }

    public function active(string $tenantId): array
    {
        $records = PaymentTerm::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'due_days']);

        $result = [];

        foreach ($records as $record) {
            $result[] = [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'due_days' => (int) $record->due_days,
            ];
        }

        return $result;
    }
}
