<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query\Formula;

/**
 * Memotong teks rumus menjadi {@see Token}. Hanya potongan yang dikenal bahasa rumus yang diterima; karakter
 * lain ditolak di posisinya, sebelum ada pohon rumus apa pun, apalagi SQL.
 *
 * - Angka ditulis cara Indonesia, sama dengan filter tambahan K-30 (`FieldFilterExpression`): titik pemisah
 *   ribuan dan koma desimal (`1.000,5`). Titik yang tidak membentuk kelompok ribuan dibaca desimal (`1.5`),
 *   supaya angka salinan dari sistem lain tetap terbaca. Karena koma adalah desimal, isian fungsi dipisah
 *   titik koma.
 * - Measure ditulis di dalam kurung siku, `[acquisition_value]`; isinya harus kunci yang sah (huruf kecil,
 *   angka, garis bawah, diawali huruf). Apakah kunci itu dikenal dataset diperiksa `QueryValidator`.
 * - Nama fungsi huruf apa pun; apakah ia ada di daftar tertutup diperiksa {@see Parser}.
 */
final class Lexer
{
    private const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    /** @var array<string, string> tanda satu karakter => jenis potongan */
    private const SINGLE = [
        '(' => Token::OPEN,
        ')' => Token::CLOSE,
        ';' => Token::SEPARATOR,
        '+' => Token::ARITHMETIC,
        '-' => Token::ARITHMETIC,
        '*' => Token::ARITHMETIC,
        '/' => Token::ARITHMETIC,
        '=' => Token::COMPARISON,
    ];

    /**
     * @return list<Token> diakhiri satu potongan {@see Token::END}
     *
     * @throws InvalidFormula
     */
    public static function tokenize(string $formula): array
    {
        $chars = mb_str_split($formula);
        $count = count($chars);
        $tokens = [];
        $i = 0;

        while ($i < $count) {
            $char = $chars[$i];
            $position = $i + 1;

            if (in_array($char, [' ', "\t", "\n", "\r"], true)) {
                $i++;

                continue;
            }

            if (ctype_digit($char)) {
                $text = '';
                while ($i < $count && (ctype_digit($chars[$i]) || $chars[$i] === '.' || $chars[$i] === ',')) {
                    $text .= $chars[$i++];
                }
                $tokens[] = new Token(Token::NUMBER, $text, $position, self::number($text, $position));

                continue;
            }

            if ($char === '[') {
                $close = array_search(']', array_slice($chars, $i + 1), true);
                if ($close === false) {
                    throw InvalidFormula::at($position, '`[` tanpa pasangan');
                }
                $key = implode('', array_slice($chars, $i + 1, $close));
                if (preg_match(self::KEY, $key) !== 1) {
                    throw InvalidFormula::at($position, 'nama nilai di dalam `[ ]` hanya boleh huruf kecil, angka, dan garis bawah, misalnya [count]');
                }
                $tokens[] = new Token(Token::MEASURE, '['.$key.']', $position, $key);
                $i += $close + 2;

                continue;
            }

            if (ctype_alpha($char)) {
                $text = '';
                while ($i < $count && (ctype_alnum($chars[$i]) || $chars[$i] === '_')) {
                    $text .= $chars[$i++];
                }
                $tokens[] = new Token(Token::NAME, $text, $position, strtoupper($text));

                continue;
            }

            if ($char === '<' || $char === '>') {
                $next = $chars[$i + 1] ?? '';
                $text = $char.(($next === '=' || ($char === '<' && $next === '>')) ? $next : '');
                $tokens[] = new Token(Token::COMPARISON, $text, $position, $text);
                $i += strlen($text);

                continue;
            }

            if (isset(self::SINGLE[$char])) {
                $tokens[] = new Token(self::SINGLE[$char], $char, $position, $char);
                $i++;

                continue;
            }

            throw InvalidFormula::at($position, match ($char) {
                ']' => '`]` tanpa pasangan',
                ',' => 'koma hanya dipakai sebagai desimal; pisahkan isian fungsi dengan titik koma (;)',
                default => 'tanda `'.$char.'` tidak dikenal',
            });
        }

        $tokens[] = new Token(Token::END, '', $count + 1, '');

        return $tokens;
    }

    /**
     * Angka cara Indonesia menjadi desimal bertitik untuk binding: `1.000,5` → `1000.5`, `1,5` dan `1.5` →
     * `1.5`. Aturannya sama dengan pembaca angka filter tambahan.
     *
     * @throws InvalidFormula
     */
    private static function number(string $text, int $position): string
    {
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $text) === 1) {
            return str_replace(['.', ','], ['', '.'], $text);
        }

        if (preg_match('/^\d+(?:[.,]\d+)?$/', $text) === 1) {
            return str_replace(',', '.', $text);
        }

        throw InvalidFormula::at($position, 'angka `'.$text.'` tidak dapat dibaca; tulis seperti 1000, 1.000, atau 1.000,5');
    }
}
