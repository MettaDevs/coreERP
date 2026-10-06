<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/** Hasil dua query mandiri yang digabung penuh menurut satu dimensi bersama. */
final readonly class BlendResultSet
{
    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, scalar|null>>  $rows
     * @param  list<array<string, scalar|null>>  $totals
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public array $totals,
        public array $meta,
    ) {}

    /**
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, scalar|null>>, totals: list<array<string, scalar|null>>, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'meta' => $this->meta,
        ];
    }
}
