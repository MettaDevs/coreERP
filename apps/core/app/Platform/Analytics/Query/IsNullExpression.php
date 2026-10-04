<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Kunci urutan `(<ekspresi>) is null`, dipasang tepat sebelum urutan turun pada kolom yang dapat kosong,
 * supaya kelompok kosong jatuh di akhir — `NULLS LAST` yang tidak dapat ditulis lewat `orderBy()`.
 *
 * Kenapa bukan `"m0" desc nulls last`: `orderBy()` hanya menerima arah `asc`/`desc`, dan `orderByRaw()`
 * hanya menerima `literal-string`. Nama alias juga tidak dapat dipakai di dalam ekspresi `ORDER BY`
 * PostgreSQL, jadi kuncinya mengulang ekspresi kolomnya sendiri: kolom berkualifikasi untuk dimensi,
 * ekspresi agregat untuk measure. Agregat di `ORDER BY` sah pada query berkelompok, begitu pula ekspresi
 * yang sama persis dengan ekspresi yang dikelompokkan.
 */
final readonly class IsNullExpression implements Expression
{
    /** @param Expression|string $subject ekspresi, atau kolom berkualifikasi yang dibungkus grammar */
    public function __construct(private Expression|string $subject) {}

    public function getValue(Grammar $grammar): string
    {
        $subject = is_string($this->subject) ? $grammar->wrap($this->subject) : $grammar->getValue($this->subject);

        return "({$subject}) is null";
    }
}
