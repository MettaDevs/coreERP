<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula\Node;

/** Rujukan ke measure dataset, `[kunci]`. Menjadi ekspresi agregat measure itu, bukan nama kolom. */
final readonly class MeasureNode implements Node
{
    public function __construct(
        public string $key,
        private int $position,
    ) {}

    public function position(): int
    {
        return $this->position;
    }
}
