<?php

declare(strict_types=1);

namespace App\Foundation\PaymentMethod\ModuleServices;

use App\Foundation\PaymentMethod\Models\PaymentMethod;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\PaymentMethodDirectory;
use Illuminate\Validation\Rule;

final class PaymentMethodDirectoryCore implements PaymentMethodDirectory
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

        $query = PaymentMethod::query()
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
            $query->select(['id', 'code', 'name', 'description', 'active'])->toBase(),
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

        return PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'name', 'description', 'active', 'version'])
            ->keyBy('id')
            ->map(fn (PaymentMethod $record): array => [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'description' => $record->description,
                'active' => (bool) $record->active,
                'version' => (int) $record->version,
            ])
            ->all();
    }

    public function active(string $tenantId): array
    {
        $records = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $result = [];

        foreach ($records as $record) {
            $result[] = [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
            ];
        }

        return $result;
    }
}
