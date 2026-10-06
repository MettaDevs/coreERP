<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Query\BoundExpression;
use App\Platform\Analytics\Query\Formula\Node\ArithmeticNode;
use App\Platform\Analytics\Query\Formula\Node\CallNode;
use App\Platform\Analytics\Query\Formula\Node\ComparisonNode;
use App\Platform\Analytics\Query\Formula\Node\MeasureNode;
use App\Platform\Analytics\Query\Formula\Node\NegateNode;
use App\Platform\Analytics\Query\Formula\Node\Node;
use App\Platform\Analytics\Query\Formula\Node\NumberNode;
use App\Platform\Analytics\Query\MeasureExpression;
use Illuminate\Database\Grammar;
use LogicException;

/**
 * SQL satu rumus, disusun dari pohon simpulnya di atas ekspresi agregat measure, sehingga urutan dan top-N dapat
 * memakai hasilnya. Tidak ada teks pengguna yang masuk ke SQL:
 *
 * - angka menjadi binding `cast(? as numeric)`;
 * - measure menjadi ekspresi agregatnya ({@see MeasureExpression}, sudah divalidasi dataset), dibulatkan ke
 *   `numeric` supaya `count / count` tidak menjadi pembagian bilangan bulat;
 * - tanda hitung, perbandingan, dan fungsi dipetakan dari daftar tetap di kelas ini, bukan disalin dari teks.
 *
 * Bagi nol tidak pernah menjadi galat: `a / b` kosong bila `b` nol, `BAGI(a; b)` bernilai nol hanya saat `b`
 * tepat nol, dan `BAGI(a; b; c)` memakai `c` hanya saat itu. Nilai `NULL` tetap `NULL` bila pembagi bukan nol.
 * Teks SQL dan binding disusun oleh satu penelusuran pohon ({@see self::emit()}), jadi urutan placeholder selalu
 * sama dengan urutan binding.
 */
final readonly class FormulaExpression implements BoundExpression
{
    /** Tanda hitung dan perbandingan yang sah, dipetakan ke SQL-nya. */
    private const OPERATORS = [
        '+' => '+',
        '-' => '-',
        '*' => '*',
        '=' => '=',
        '<>' => '<>',
        '<' => '<',
        '<=' => '<=',
        '>' => '>',
        '>=' => '>=',
    ];

    public function __construct(
        private Node $node,
        private CompiledDataset $dataset,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        $bindings = [];

        return $this->emit($this->node, $grammar, $bindings);
    }

    public function bindings(): array
    {
        $bindings = [];
        $this->emit($this->node, null, $bindings);

        return $bindings;
    }

    /**
     * SQL simpul ini, sambil mengumpulkan binding dalam urutan placeholder-nya. Tanpa grammar hanya binding yang
     * dikumpulkan; penelusurannya sama persis, sehingga {@see self::bindings()} tidak dapat menyimpang dari teks.
     *
     * @param  list<string|int|bool>  $bindings
     */
    private function emit(Node $node, ?Grammar $grammar, array &$bindings): string
    {
        if ($node instanceof NumberNode) {
            $bindings[] = $node->value;

            return 'cast(? as numeric)';
        }

        if ($node instanceof MeasureNode) {
            $expression = MeasureExpression::for($this->dataset, $this->dataset->measure($node->key));
            array_push($bindings, ...$expression->bindings());

            return $grammar === null ? '' : 'cast(('.$expression->getValue($grammar).') as numeric)';
        }

        if ($node instanceof NegateNode) {
            return '(-'.$this->emit($node->operand, $grammar, $bindings).')';
        }

        if ($node instanceof ArithmeticNode) {
            $left = $this->emit($node->left, $grammar, $bindings);
            $right = $this->emit($node->right, $grammar, $bindings);

            // Pembagi nol menjadi kosong, bukan galat database.
            return $node->operator === '/'
                ? "({$left} / nullif({$right}, 0))"
                : "({$left} ".self::OPERATORS[$node->operator]." {$right})";
        }

        if ($node instanceof ComparisonNode) {
            $left = $this->emit($node->left, $grammar, $bindings);
            $right = $this->emit($node->right, $grammar, $bindings);

            return "({$left} ".self::OPERATORS[$node->operator]." {$right})";
        }

        if ($node instanceof CallNode) {
            if ($node->function === 'BAGI') {
                return $this->divide($node, $grammar, $bindings);
            }

            $arguments = [];
            foreach ($node->arguments as $argument) {
                $arguments[] = $this->emit($argument, $grammar, $bindings);
            }

            return match ($node->function) {
                'JIKA' => 'case when '.$arguments[0].' then '.$arguments[1].' else '.$arguments[2].' end',
                'ABS' => 'abs('.$arguments[0].')',
                'BULAT' => 'round('.$arguments[0].', cast('.$arguments[1].' as integer))',
                'MIN' => 'least('.implode(', ', $arguments).')',
                'MAKS' => 'greatest('.implode(', ', $arguments).')',
                default => throw new LogicException("Fungsi `{$node->function}` lolos dari pembaca rumus tanpa SQL."),
            };
        }

        throw new LogicException('Simpul rumus tidak dikenal: '.$node::class);
    }

    /**
     * Nilai cadangan BAGI hanya dipakai ketika pembagi bernilai tepat nol, bukan ketika hasilnya NULL.
     *
     * @param  list<string|int|bool>  $bindings
     */
    private function divide(CallNode $node, ?Grammar $grammar, array &$bindings): string
    {
        $zeroCheck = $this->emit($node->arguments[1], $grammar, $bindings);
        $fallback = isset($node->arguments[2]) ? $this->emit($node->arguments[2], $grammar, $bindings) : '0';
        $numerator = $this->emit($node->arguments[0], $grammar, $bindings);
        $denominator = $this->emit($node->arguments[1], $grammar, $bindings);

        return 'case when '.$zeroCheck.' = 0 then '.$fallback.' else '.$numerator.' / '.$denominator.' end';
    }
}
