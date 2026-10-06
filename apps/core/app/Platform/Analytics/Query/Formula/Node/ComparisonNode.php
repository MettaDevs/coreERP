<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula\Node;

/** Perbandingan dua ekspresi; hanya sah sebagai isian pertama `JIKA`. */
final readonly class ComparisonNode implements Node
{
    /** @param '='|'<>'|'<'|'<='|'>'|'>=' $operator */
    public function __construct(
        public string $operator,
        public Node $left,
        public Node $right,
        private int $position,
    ) {}

    public function position(): int
    {
        return $this->position;
    }
}
