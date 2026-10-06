<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula;

use App\Platform\Analytics\Query\Formula\Node\ArithmeticNode;
use App\Platform\Analytics\Query\Formula\Node\CallNode;
use App\Platform\Analytics\Query\Formula\Node\ComparisonNode;
use App\Platform\Analytics\Query\Formula\Node\MeasureNode;
use App\Platform\Analytics\Query\Formula\Node\NegateNode;
use App\Platform\Analytics\Query\Formula\Node\Node;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;

/**
 * Satu rumus di `formulas` query: kunci yang dipakai di `measures`, `sort`, dan kolom hasil; teks rumusnya apa
 * adanya (yang disimpan widget dan dikirim kembali ke layar); pohon hasil bacaan {@see Parser}; nama tampilan;
 * dan format angkanya.
 *
 * Teks dan pohon selalu berpasangan: rumus hanya dibuat oleh pembaca query sesudah teksnya terbaca, jadi tidak
 * ada pohon tanpa teks yang bisa berbeda arti.
 */
final readonly class Formula
{
    public function __construct(
        public string $key,
        public string $expression,
        public Node $node,
        public ?string $caption = null,
        public ?MeasureFormat $format = null,
    ) {}

    public function caption(): string
    {
        return $this->caption ?? $this->key;
    }

    /** Tanpa format, hasil rumus ditampilkan sebagai angka biasa. */
    public function format(): MeasureFormat
    {
        return $this->format ?? MeasureFormat::Number;
    }

    /**
     * Setiap rujukan measure dalam urutan tulisannya, beserta karakter tempatnya, untuk galat yang menunjuk
     * posisi.
     *
     * @return list<array{key: string, position: int}>
     */
    public function references(): array
    {
        $out = [];
        self::collect($this->node, $out);

        return $out;
    }

    /**
     * Kunci measure yang dirujuk, tanpa ganda, dalam urutan kemunculan pertamanya.
     *
     * @return list<string>
     */
    public function measures(): array
    {
        return array_values(array_unique(array_column($this->references(), 'key')));
    }

    /**
     * Bentuk lengkap untuk kunci cache dan log: isian yang tidak diisi ditulis null.
     *
     * @return array{key: string, caption: ?string, expression: string, format: ?string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'caption' => $this->caption, 'expression' => $this->expression, 'format' => $this->format?->value];
    }

    /**
     * Bentuk simpan widget dan kirim ke layar: isian yang tidak diisi tidak ditulis.
     *
     * @return array<string, string>
     */
    public function toCompact(): array
    {
        return array_filter($this->toArray(), static fn (?string $value): bool => $value !== null);
    }

    /**
     * Teks rumus dengan kunci measure diganti lewat peta kunci lama => baru, untuk widget yang disimpan sebelum
     * module mengganti nama measure (`version(n+1, renamed: [...])`). Hanya isi `[kunci]` yang diganti; sisanya,
     * termasuk spasi tulisan penyusunnya, tetap. Teks yang tidak terbaca dikembalikan apa adanya, supaya
     * pembacaan ulang query melaporkannya di tempatnya.
     *
     * @param  array<string, string>  $map
     */
    public static function renameMeasures(string $expression, array $map): string
    {
        if ($map === []) {
            return $expression;
        }
        try {
            $tokens = Lexer::tokenize($expression);
        } catch (InvalidFormula) {
            return $expression;
        }

        foreach (array_reverse($tokens) as $token) {
            if ($token->type === Token::MEASURE && isset($map[$token->value])) {
                $expression = mb_substr($expression, 0, $token->position - 1).'['.$map[$token->value].']'.mb_substr($expression, $token->position - 1 + mb_strlen($token->text));
            }
        }

        return $expression;
    }

    /** @param list<array{key: string, position: int}> $out */
    private static function collect(Node $node, array &$out): void
    {
        if ($node instanceof MeasureNode) {
            $out[] = ['key' => $node->key, 'position' => $node->position()];
        } elseif ($node instanceof NegateNode) {
            self::collect($node->operand, $out);
        } elseif ($node instanceof ArithmeticNode || $node instanceof ComparisonNode) {
            self::collect($node->left, $out);
            self::collect($node->right, $out);
        } elseif ($node instanceof CallNode) {
            foreach ($node->arguments as $argument) {
                self::collect($argument, $out);
            }
        }
    }
}
