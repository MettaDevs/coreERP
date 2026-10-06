<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula\Node;

/** Angka, sudah dibaca menjadi desimal bertitik (`1000.5`). Menjadi binding, tidak pernah disambung ke SQL. */
final readonly class NumberNode implements Node
{
    public function __construct(
        public string $value,
        private int $position,
    ) {}

    public function position(): int
    {
        return $this->position;
    }
}
