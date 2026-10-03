<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/**
 * Satu pengelompok query: kunci field dataset, dengan ember waktu bila field itu field waktu.
 * Di JSON berbentuk `"group_aset_id"` atau `{"field": "acquired_on", "granularity": "month"}`.
 */
final readonly class Dimension
{
    public function __construct(
        public string $field,
        public ?TimeGranularity $granularity = null,
    ) {}

    /** @return array{field: string, granularity: ?string} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'granularity' => $this->granularity?->value];
    }
}
