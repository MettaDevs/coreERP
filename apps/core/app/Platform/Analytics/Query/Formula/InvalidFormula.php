<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula;

use RuntimeException;

/**
 * Rumus yang tidak dapat dibaca. `position` karakter tempat masalahnya (mulai dari 1), supaya layar dapat
 * menandai tempatnya; pesannya sudah menyebut posisi itu dalam bahasa sehari-hari.
 */
final class InvalidFormula extends RuntimeException
{
    public function __construct(string $message, public readonly int $position)
    {
        parent::__construct($message);
    }

    /** "Rumus tidak dapat dibaca di karakter 14: `]` tanpa pasangan." */
    public static function at(int $position, string $detail): self
    {
        return new self("Rumus tidak dapat dibaca di karakter {$position}: {$detail}.", $position);
    }
}
