<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Illuminate\Contracts\Database\Query\Expression;

/**
 * Ekspresi SQL yang membawa placeholder `?`. Pemanggil memasang {@see self::bindings()} pada bagian query tempat
 * ekspresi itu dipakai (`select`, atau `order` untuk kunci urutan kosong-di-akhir), tepat sesudah ekspresinya,
 * dalam urutan placeholder di teks SQL-nya.
 *
 * Dipakai measure ({@see MeasureExpression}), rumus ({@see Formula\FormulaExpression}), dan kolom turunan
 * ({@see SqlTemplate}) yang membungkus keduanya.
 */
interface BoundExpression extends Expression
{
    /** @return list<string|int|bool> */
    public function bindings(): array;
}
