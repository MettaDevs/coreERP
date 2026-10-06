<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Ekspresi SQL dari templat yang ditulis di kode engine, dengan operand `{0}`, `{1}`, … berupa kolom
 * berkualifikasi (dibungkus `wrap()` grammar) atau ekspresi lain. Dipakai untuk kolom turunan — perbandingan
 * periode dan persen terhadap total — yang membungkus ekspresi measure atau kolom subquery.
 *
 * Templatnya selalu literal kode, tidak pernah dari pemanggil, dan kolomnya berasal dari definisi dataset atau
 * alias engine; jadi tidak ada teks pengguna yang masuk ke SQL. Operand boleh dipakai lebih dari sekali; binding
 * operand ikut diulang dalam urutan kemunculannya di templat ({@see self::bindings()}).
 */
final readonly class SqlTemplate implements BoundExpression
{
    private const PLACEHOLDER = '/\{(\d+)\}/';

    /** @param list<Expression|string> $operands */
    public function __construct(
        private string $template,
        private array $operands,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($grammar): string {
            $operand = $this->operands[(int) $match[1]];

            return is_string($operand) ? $grammar->wrap($operand) : (string) $grammar->getValue($operand);
        }, $this->template);
    }

    public function bindings(): array
    {
        preg_match_all(self::PLACEHOLDER, $this->template, $matches);

        $bindings = [];
        foreach ($matches[1] as $index) {
            $operand = $this->operands[(int) $index];
            if ($operand instanceof BoundExpression) {
                array_push($bindings, ...$operand->bindings());
            }
        }

        return $bindings;
    }
}
