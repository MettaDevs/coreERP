<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula;

/**
 * Satu potongan rumus hasil {@see Lexer}. `position` adalah urutan karakter pertamanya di teks rumus, mulai
 * dari 1, supaya galat dapat menunjuk "di karakter 14" seperti yang dilihat pengguna.
 *
 * `value` bentuk yang sudah dibaca: angka sebagai desimal bertitik (`1.000,5` menjadi `1000.5`), kunci measure
 * tanpa kurung siku, nama fungsi dalam huruf besar, dan tanda apa adanya.
 */
final readonly class Token
{
    public const NUMBER = 'number';

    public const MEASURE = 'measure';

    public const NAME = 'name';

    public const OPEN = 'open';

    public const CLOSE = 'close';

    public const SEPARATOR = 'separator';

    /** `+`, `-`, `*`, `/`. */
    public const ARITHMETIC = 'arithmetic';

    /** `=`, `<>`, `<`, `<=`, `>`, `>=`. */
    public const COMPARISON = 'comparison';

    public const END = 'end';

    public function __construct(
        public string $type,
        public string $text,
        public int $position,
        public string $value,
    ) {}
}
