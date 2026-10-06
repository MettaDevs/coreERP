<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula\Node;

/**
 * Satu simpul pohon rumus hasil `Formula\Parser`. Pohonnya hanya berisi bentuk yang dikenal bahasa rumus —
 * angka, measure, tanda hitung, perbandingan, dan fungsi dari daftar tertutup — jadi SQL yang disusun
 * `FormulaExpression` darinya tidak pernah memuat teks pengguna. `position` karakter awal simpul (mulai 1),
 * untuk galat yang menunjuk tempatnya.
 */
interface Node
{
    public function position(): int;
}
