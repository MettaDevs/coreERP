<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula\Node;

/** Tanda minus di depan satu faktor: `-[count]`. */
final readonly class NegateNode implements Node
{
    public function __construct(
        public Node $operand,
        private int $position,
    ) {}

    public function position(): int
    {
        return $this->position;
    }
}
