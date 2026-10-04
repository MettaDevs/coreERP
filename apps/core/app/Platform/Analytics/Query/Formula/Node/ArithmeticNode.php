<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula\Node;

/** Tanda hitung di antara dua suku atau faktor. */
final readonly class ArithmeticNode implements Node
{
    /** @param '+'|'-'|'*'|'/' $operator */
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
