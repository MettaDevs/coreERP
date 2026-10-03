<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Query yang sudah disusun tetapi belum dijalankan. `builder` sudah membawa `LIMIT limit + 1`, sehingga
 * baris ke-`limit + 1` hanya menandai hasil terpotong; `totals` query total (area 3), null bila tidak
 * diminta.
 */
final readonly class CompiledQuery
{
    /**
     * @param  Builder<Model>  $builder
     * @param  Builder<Model>|null  $totals
     * @param  list<ResultColumn>  $columns
     */
    public function __construct(
        public Builder $builder,
        public ?Builder $totals,
        public array $columns,
        public int $limit,
    ) {}
}
