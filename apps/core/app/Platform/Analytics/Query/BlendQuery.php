<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;

/**
 * Dua query terpisah yang boleh digabung karena mengelompokkan nilai dimensi bersama yang sama.
 * Query tetap menunjuk dataset masing-masing; tidak ada sumber tabel gabungan.
 *
 * @phpstan-type Source array{dataset: CompiledDataset, query: AnalyticsQuery}
 */
final readonly class BlendQuery
{
    /**
     * @param  list<array{dataset: CompiledDataset, query: AnalyticsQuery}>  $sources
     */
    public function __construct(
        public SharedDimension $dimension,
        public array $sources,
    ) {}

    /** @return array{dimension: string, queries: list<array<string, mixed>>} */
    public function normalized(): array
    {
        return [
            'dimension' => $this->dimension->value,
            'queries' => array_map(
                static fn (array $source): array => $source['query']->normalized(),
                $this->sources,
            ),
        ];
    }
}
