<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Query yang sudah disusun tetapi belum dijalankan. `builder` sudah membawa `LIMIT limit + 1`, sehingga
 * baris ke-`limit + 1` hanya menandai hasil terpotong; `totals` query total — satu baris per mata uang dan
 * satuan, tanpa batas baris — atau null bila tidak diminta. `columns` urutan kolom hasil beserta alias
 * SQL-nya, yang dipetakan {@see ResultSet} kembali ke kunci dataset.
 *
 * Dipegang {@see QueryExecutor} untuk dijalankan atau di-`EXPLAIN`, dan oleh test yang memeriksa SQL-nya
 * (`$compiled->builder->toBase()->toSql()`) tanpa membaca data.
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
