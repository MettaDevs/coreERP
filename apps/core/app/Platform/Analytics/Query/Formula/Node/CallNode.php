<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula\Node;

/** Panggilan satu fungsi dari daftar tertutup `Formula\Parser::FUNCTIONS`, dengan nama huruf besar. */
final readonly class CallNode implements Node
{
    /** @param list<Node> $arguments */
    public function __construct(
        public string $function,
        public array $arguments,
        private int $position,
    ) {}

    public function position(): int
    {
        return $this->position;
    }
}
