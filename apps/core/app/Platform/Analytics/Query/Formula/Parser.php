<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula;

use App\Platform\Analytics\Query\Formula\Node\ArithmeticNode;
use App\Platform\Analytics\Query\Formula\Node\CallNode;
use App\Platform\Analytics\Query\Formula\Node\ComparisonNode;
use App\Platform\Analytics\Query\Formula\Node\MeasureNode;
use App\Platform\Analytics\Query\Formula\Node\NegateNode;
use App\Platform\Analytics\Query\Formula\Node\Node;
use App\Platform\Analytics\Query\Formula\Node\NumberNode;

/**
 * Membaca teks rumus menjadi pohon simpul (KA-19, `docs/todo/analitik/mesin-query.md` bagian *Bahasa rumus*).
 * Pembaca turun-rekursif untuk tata bahasa ini:
 *
 * ```text
 * ekspresi  := suku (('+' | '-') suku)*
 * suku      := faktor (('*' | '/') faktor)*
 * faktor    := angka | measure | fungsi | '(' ekspresi ')' | '-' faktor
 * fungsi    := NAMA '(' argumen (';' argumen)* ')'
 * kondisi   := ekspresi ('=' | '<>' | '<' | '<=' | '>' | '>=') ekspresi     hanya isian pertama JIKA
 * ```
 *
 * Yang ditolak di sini, sebelum ada SQL apa pun: karakter yang tidak dikenal, fungsi di luar
 * {@see self::FUNCTIONS}, jumlah isian fungsi yang salah, kurung tanpa pasangan, perbandingan di luar `JIKA`,
 * rumus lebih panjang dari {@see self::MAX_LENGTH} karakter, dan pohon lebih dalam dari {@see self::MAX_DEPTH}
 * tingkat. Setiap galat menyebut karakter tempatnya. Apakah measure yang dirujuk dikenal dataset, dan apakah
 * rumus mencampur mata uang, diperiksa `QueryValidator`, karena butuh dataset.
 */
final class Parser
{
    public const MAX_LENGTH = 500;

    public const MAX_DEPTH = 20;

    /**
     * Daftar fungsi tertutup: nama => [isian paling sedikit, isian paling banyak atau null tanpa batas]. SQL
     * tiap fungsi ditulis `FormulaExpression`; nama di luar daftar ini ditolak saat dibaca.
     */
    public const FUNCTIONS = [
        'BAGI' => [2, 3],
        'JIKA' => [3, 3],
        'ABS' => [1, 1],
        'BULAT' => [2, 2],
        'MIN' => [2, null],
        'MAKS' => [2, null],
    ];

    private int $at = 0;

    private int $depth = 0;

    /** @param list<Token> $tokens */
    private function __construct(private readonly array $tokens) {}

    /** @throws InvalidFormula */
    public static function parse(string $formula): Node
    {
        if (trim($formula) === '') {
            throw new InvalidFormula('Isi rumusnya, misalnya BAGI([disposed]; [count]) * 100.', 1);
        }
        if (mb_strlen($formula) > self::MAX_LENGTH) {
            throw new InvalidFormula('Rumus terlalu panjang. Maksimal '.self::MAX_LENGTH.' karakter.', self::MAX_LENGTH + 1);
        }

        $parser = new self(Lexer::tokenize($formula));
        $node = $parser->expression();
        $end = $parser->peek();
        if ($end->type !== Token::END) {
            throw self::unexpected($end);
        }

        return $node;
    }

    private function expression(): Node
    {
        $this->enter();
        $node = $this->term();
        while ($this->peek()->type === Token::ARITHMETIC && in_array($this->peek()->value, ['+', '-'], true)) {
            $operator = $this->next();
            $node = new ArithmeticNode($operator->value === '+' ? '+' : '-', $node, $this->term(), $operator->position);
        }
        $this->depth--;

        return $node;
    }

    private function term(): Node
    {
        $node = $this->factor();
        while ($this->peek()->type === Token::ARITHMETIC && in_array($this->peek()->value, ['*', '/'], true)) {
            $operator = $this->next();
            $node = new ArithmeticNode($operator->value === '*' ? '*' : '/', $node, $this->factor(), $operator->position);
        }

        return $node;
    }

    private function factor(): Node
    {
        $token = $this->next();

        switch ($token->type) {
            case Token::NUMBER:
                return new NumberNode($token->value, $token->position);
            case Token::MEASURE:
                return new MeasureNode($token->value, $token->position);
            case Token::NAME:
                return $this->call($token);
            case Token::OPEN:
                $node = $this->expression();
                $this->close($token);

                return $node;
            case Token::ARITHMETIC:
                if ($token->value === '-') {
                    $this->enter();
                    $node = new NegateNode($this->factor(), $token->position);
                    $this->depth--;

                    return $node;
                }
        }

        throw self::unexpected($token);
    }

    private function call(Token $name): CallNode
    {
        $arity = self::FUNCTIONS[$name->value] ?? throw InvalidFormula::at(
            $name->position,
            'fungsi `'.$name->text.'` tidak dikenal. Fungsi yang tersedia: '.implode(', ', array_keys(self::FUNCTIONS)),
        );

        $open = $this->next();
        if ($open->type !== Token::OPEN) {
            throw InvalidFormula::at($open->position, 'sesudah '.$name->value.' harus ada `(`');
        }

        $arguments = [];
        do {
            $arguments[] = $name->value === 'JIKA' && $arguments === [] ? $this->condition() : $this->expression();
        } while ($this->accept(Token::SEPARATOR));
        $this->close($open);

        [$min, $max] = $arity;
        if (count($arguments) < $min || ($max !== null && count($arguments) > $max)) {
            $expected = match (true) {
                $max === null => "sedikitnya {$min}",
                $min === $max => (string) $min,
                default => "{$min} atau {$max}",
            };
            throw InvalidFormula::at($name->position, "{$name->value} butuh {$expected} isian, dipisah titik koma (;)");
        }

        return new CallNode($name->value, $arguments, $name->position);
    }

    private function condition(): ComparisonNode
    {
        $left = $this->expression();
        $operator = $this->peek();
        if ($operator->type !== Token::COMPARISON) {
            throw InvalidFormula::at($operator->position, 'isian pertama JIKA harus perbandingan, misalnya [count] > 0');
        }
        $this->next();

        return new ComparisonNode(match ($operator->value) {
            '=' => '=',
            '<>' => '<>',
            '<' => '<',
            '<=' => '<=',
            '>' => '>',
            default => '>=',
        }, $left, $this->expression(), $operator->position);
    }

    /** Kurung tutup pasangan `$open`; tanpanya galat menunjuk kurung buka yang tidak ditutup. */
    private function close(Token $open): void
    {
        $token = $this->peek();
        if ($token->type === Token::CLOSE) {
            $this->next();

            return;
        }

        throw $token->type === Token::END ? InvalidFormula::at($open->position, '`(` tanpa pasangan') : self::unexpected($token);
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw InvalidFormula::at($this->peek()->position, 'rumus terlalu bertingkat, paling banyak '.self::MAX_DEPTH.' tingkat');
        }
    }

    private function accept(string $type): bool
    {
        if ($this->peek()->type !== $type) {
            return false;
        }
        $this->next();

        return true;
    }

    private function peek(): Token
    {
        return $this->tokens[$this->at];
    }

    private function next(): Token
    {
        $token = $this->tokens[$this->at];
        // Potongan terakhir selalu END; pembaca tidak pernah melangkah melewatinya.
        if ($token->type !== Token::END) {
            $this->at++;
        }

        return $token;
    }

    private static function unexpected(Token $token): InvalidFormula
    {
        return InvalidFormula::at($token->position, match ($token->type) {
            Token::END => 'rumus berakhir sebelum selesai',
            Token::CLOSE => '`)` tanpa pasangan',
            Token::SEPARATOR => '`;` hanya dipakai untuk memisahkan isian fungsi',
            Token::COMPARISON => 'perbandingan `'.$token->text.'` hanya dapat dipakai di isian pertama JIKA',
            Token::ARITHMETIC => 'tanda `'.$token->text.'` tidak dapat ditaruh di sini',
            default => 'kurang tanda hitung sebelum `'.$token->text.'`',
        });
    }
}
