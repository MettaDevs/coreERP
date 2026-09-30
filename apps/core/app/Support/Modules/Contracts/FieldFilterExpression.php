<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Filter tambahan pada laporan (K-30): isian filter pengguna untuk satu kolom, diterapkan ke query builder.
 *
 * Padanannya kolom filter di Business Central, dengan sintaks dari "Filter criteria and operators"
 * (MS Learn, ui-enter-criteria-filters). Kolom teks, angka, tanggal, dan tanggal-jam diisi sebagai
 * ekspresi:
 *
 * - `|` berarti ATAU dan `&` berarti DAN; `&` mengikat lebih kuat. Tanda kurung tidak didukung, sama
 *   seperti di BC.
 * - Tiap syarat berupa `a..b` (antara, inklusif), `..b`, `a..`, `<>x`, `>x`, `>=x`, `<x`, `<=x`, `=x`,
 *   atau nilai polos (sama dengan).
 * - Teks: `*` sembarang banyak karakter, `?` satu karakter. Pencocokan peka huruf besar seperti BC, kecuali
 *   diawali `@`. `*`, `?`, dan `@` hanya berlaku tanpa operator atau dengan `<>`; pada `<`, `>`, dan
 *   rentang ia ditolak. Isi di dalam petik tunggal (`'A|B*'`) dibaca apa adanya; `''` di dalam petik
 *   berarti satu petik.
 * - `''` sendirian berarti kosong. BC tidak mengenal NULL, jadi di sini kosong mencakup NULL, dan `<>x`
 *   ikut meloloskan baris NULL.
 * - Angka ditulis cara Indonesia: titik pemisah ribuan, koma desimal (`1.000.000,50`). Titik yang tidak
 *   membentuk kelompok ribuan tetap dibaca desimal (`1.5`), supaya angka salinan dari sistem lain terbaca.
 * - Tanggal ditulis `YYYY-MM-DD`, `DD/MM/YYYY`, atau `DD-MM-YYYY`; `t` berarti hari ini menurut zona
 *   pengguna. Pada kolom tanggal-jam (disimpan UTC) sebuah tanggal berarti satu hari penuh di zona itu.
 *
 * Yang sengaja tidak diikuti dari BC: rumus tanggal (`CM`, `-1M`), nama hari/bulan, token `w`/`p`, dan
 * filter terhadap field lain (`%1`). Ya/tidak, pilihan, dan rujukan tidak diketik: nilainya daftar hasil
 * pemilih, dan daftar itu dibaca sebagai ATAU.
 *
 * Seluruh isian diurai dan diperiksa lebih dulu; query baru disentuh setelah semuanya sah, dan hanya
 * dengan satu klausa `where` terbungkus supaya ATAU di dalamnya tidak bocor ke syarat lain. Nilai selalu
 * lewat binding. Nama kolom datang dari katalog ({@see FilterField::$column}), tidak pernah dari pengguna.
 */
final class FieldFilterExpression
{
    public const MAX_LENGTH = 250;

    public const MAX_TERMS = 50;

    public const MAX_SELECTIONS = 100;

    public const MAX_REFERENCE_LENGTH = 64;

    /** Urutannya penting: operator dua karakter diperiksa sebelum awalannya yang satu karakter. */
    private const OPERATORS = ['<>', '>=', '<=', '>', '<', '='];

    private const DATE_HINT = 'Tulis seperti 31/12/2026, 2026-12-31, atau t untuk hari ini.';

    /**
     * @param  string|array<array-key, mixed>  $value
     *
     * @throws InvalidFilterExpression
     */
    public static function apply(Builder $query, FilterField $field, string|array $value, string $timezone): void
    {
        $condition = match ($field->type) {
            FieldType::Boolean, FieldType::Option, FieldType::Reference => self::selection($field, $value),
            FieldType::Text, FieldType::Number, FieldType::Date, FieldType::DateTime => self::expression($field, $value, $timezone),
        };

        if ($condition !== null) {
            $query->where($condition);
        }
    }

    /**
     * @param  string|array<array-key, mixed>  $value
     * @return (Closure(Builder): void)|null
     */
    private static function expression(FilterField $field, string|array $value, string $timezone): ?Closure
    {
        if (is_array($value)) {
            throw self::fail($field, 'isiannya harus berupa teks filter, bukan daftar pilihan.');
        }

        $expression = trim($value);
        if ($expression === '') {
            return null;
        }

        if (mb_strlen($expression) > self::MAX_LENGTH) {
            throw self::fail($field, 'isiannya terlalu panjang, maksimal '.self::MAX_LENGTH.' karakter.');
        }

        $groups = self::split($field, $expression);

        if (array_sum(array_map('count', $groups)) > self::MAX_TERMS) {
            throw self::fail($field, 'syaratnya terlalu banyak, maksimal '.self::MAX_TERMS.'.');
        }

        $compiled = [];
        foreach ($groups as $terms) {
            $compiled[] = array_map(
                static fn (array $term): Closure => self::condition($field, self::parse($field, $term), $timezone),
                $terms,
            );
        }

        return static function (Builder $query) use ($compiled): void {
            if (count($compiled) === 1) {
                foreach ($compiled[0] as $condition) {
                    $condition($query);
                }

                return;
            }

            foreach ($compiled as $terms) {
                $query->orWhere(static function (Builder $group) use ($terms): void {
                    foreach ($terms as $condition) {
                        $condition($group);
                    }
                });
            }
        };
    }

    /**
     * Memecah isian menjadi kelompok ATAU yang masing-masing berisi syarat DAN. Tiap karakter dibawa
     * bersama tanda apakah ia berada di dalam petik; karakter kosong bertanda petik menandai adanya
     * sepasang petik, sehingga `''` dapat dibedakan dari isian yang memang tidak ada.
     *
     * @return list<list<array{raw: string, chars: list<array{0: string, 1: bool}>}>>
     */
    private static function split(FilterField $field, string $expression): array
    {
        $characters = mb_str_split($expression);
        $count = count($characters);
        $groups = [];
        $terms = [];
        $chars = [];
        $raw = '';
        $quoted = false;

        for ($i = 0; $i < $count; $i++) {
            $character = $characters[$i];

            if ($quoted) {
                $raw .= $character;
                if ($character !== "'") {
                    $chars[] = [$character, true];
                } elseif (($characters[$i + 1] ?? null) === "'") {
                    $chars[] = ["'", true];
                    $raw .= "'";
                    $i++;
                } else {
                    $quoted = false;
                }

                continue;
            }

            if ($character === '|' || $character === '&') {
                $terms[] = self::term($field, $expression, $raw, $chars);
                $chars = [];
                $raw = '';
                if ($character === '|') {
                    $groups[] = $terms;
                    $terms = [];
                }

                continue;
            }

            $raw .= $character;
            if ($character === "'") {
                $quoted = true;
                $chars[] = ['', true];
            } else {
                $chars[] = [$character, false];
            }
        }

        if ($quoted) {
            throw self::fail($field, 'tanda petik di "'.trim($raw).'" belum ditutup.');
        }

        $terms[] = self::term($field, $expression, $raw, $chars);
        $groups[] = $terms;

        return $groups;
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $chars
     * @return array{raw: string, chars: list<array{0: string, 1: bool}>}
     */
    private static function term(FilterField $field, string $expression, string $raw, array $chars): array
    {
        $chars = self::trim($chars);
        if ($chars === []) {
            throw self::fail($field, '"'.$expression.'" punya bagian kosong di sekitar tanda | atau &.');
        }

        return ['raw' => trim($raw), 'chars' => $chars];
    }

    /**
     * Satu syarat menjadi operator dan nilainya. Pada rentang, `left` dan `right` adalah batasnya dan
     * salah satunya boleh tidak ada; pada operator lain nilainya di `left`.
     *
     * @param  array{raw: string, chars: list<array{0: string, 1: bool}>}  $term
     * @return array{raw: string, op: string, left: list<array{0: string, 1: bool}>|null, right: list<array{0: string, 1: bool}>|null}
     */
    private static function parse(FilterField $field, array $term): array
    {
        $chars = $term['chars'];
        $raw = $term['raw'];

        $ranges = [];
        for ($i = 0, $count = count($chars); $i < $count - 1; $i++) {
            if ($chars[$i] === ['.', false] && $chars[$i + 1] === ['.', false]) {
                $ranges[] = $i;
                $i++;
            }
        }

        if (count($ranges) > 1) {
            throw self::fail($field, '"'.$raw.'" hanya boleh berisi satu "..".');
        }

        if (count($ranges) === 1) {
            $left = self::trim(array_slice($chars, 0, $ranges[0]));
            $right = self::trim(array_slice($chars, $ranges[0] + 2));

            if ($left === [] && $right === []) {
                throw self::fail($field, '"'.$raw.'" belum diberi batas awal atau akhir.');
            }

            foreach ([$left, $right] as $side) {
                if (self::operator($side) !== null) {
                    throw self::fail($field, '"'.$raw.'" tidak bisa memakai <, >, atau = bersama "..".');
                }
            }

            return ['raw' => $raw, 'op' => '..', 'left' => $left ?: null, 'right' => $right ?: null];
        }

        $op = self::operator($chars);
        if ($op !== null) {
            $chars = self::trim(array_slice($chars, strlen($op)));
        }

        if ($chars === []) {
            throw self::fail($field, '"'.$raw.'" belum diberi nilai.');
        }

        return ['raw' => $raw, 'op' => $op ?? '=', 'left' => $chars, 'right' => null];
    }

    /**
     * @param  array{raw: string, op: string, left: list<array{0: string, 1: bool}>|null, right: list<array{0: string, 1: bool}>|null}  $term
     * @return Closure(Builder): void
     */
    private static function condition(FilterField $field, array $term, string $timezone): Closure
    {
        foreach ([$term['left'], $term['right']] as $side) {
            if ($side === null) {
                continue;
            }

            if (self::isBlank($side) && ! in_array($term['op'], ['=', '<>'], true)) {
                throw self::blankMisused($field, $term['raw']);
            }

            if ($field->type !== FieldType::Text && self::hasWildcard($side)) {
                throw self::fail($field, '"'.$term['raw'].'": tanda * dan ? hanya bisa dipakai pada kolom teks.');
            }
        }

        return match ($field->type) {
            FieldType::Text => self::textCondition($field, $term),
            FieldType::DateTime => self::dateTimeCondition($field, $term, $timezone),
            default => self::scalarCondition($field, $term, $timezone),
        };
    }

    /**
     * Teks tanpa `*`, `?`, atau `@` dibandingkan apa adanya (peka huruf besar, seperti BC). Bila ada salah
     * satunya, perbandingannya LIKE atau ILIKE; `%`, `_`, dan `\` milik pengguna di-escape dengan `\`, escape
     * bawaan LIKE di PostgreSQL.
     *
     * @param  array{raw: string, op: string, left: list<array{0: string, 1: bool}>|null, right: list<array{0: string, 1: bool}>|null}  $term
     * @return Closure(Builder): void
     */
    private static function textCondition(FilterField $field, array $term): Closure
    {
        $column = $field->column;
        $op = $term['op'];

        if ($op === '..') {
            $bounds = [];
            foreach (['>=' => $term['left'], '<=' => $term['right']] as $boundOp => $side) {
                if ($side === null) {
                    continue;
                }

                if (self::hasWildcard($side) || ($side[0] ?? null) === ['@', false]) {
                    throw self::fail($field, '"'.$term['raw'].'": tanda *, ?, dan @ tidak bisa dipakai bersama "..".');
                }

                $bounds[] = [$boundOp, self::text($side)];
            }

            return self::bounds($column, $bounds);
        }

        [$chars, $insensitive] = self::caseMode($field, $term['raw'], $term['left'] ?? []);
        $pattern = $insensitive || self::hasWildcard($chars);

        if (self::isBlank($chars)) {
            if (! in_array($op, ['=', '<>'], true)) {
                throw self::blankMisused($field, $term['raw']);
            }

            return static function (Builder $query) use ($column, $op): void {
                if ($op === '<>') {
                    $query->where($column, '<>', '');

                    return;
                }

                $query->where(static function (Builder $blank) use ($column): void {
                    $blank->where($column, '=', '')->orWhereNull($column);
                });
            };
        }

        if ($pattern && ! in_array($op, ['=', '<>'], true)) {
            throw self::fail($field, '"'.$term['raw'].'": tanda *, ?, dan @ hanya bisa dipakai tanpa operator atau dengan <>.');
        }

        $value = $pattern ? self::likePattern($chars) : self::text($chars);

        return static function (Builder $query) use ($column, $op, $value, $pattern, $insensitive): void {
            if ($op === '<>') {
                $query->where(static function (Builder $not) use ($column, $value, $pattern, $insensitive): void {
                    $pattern
                        ? $not->whereNotLike($column, $value, caseSensitive: ! $insensitive)
                        : $not->where($column, '<>', $value);
                    $not->orWhereNull($column);
                });

                return;
            }

            $pattern
                ? $query->whereLike($column, $value, caseSensitive: ! $insensitive)
                : $query->where($column, $op, $value);
        };
    }

    /**
     * Angka dan tanggal: nilainya diubah ke bentuk database, lalu dibandingkan apa adanya.
     *
     * @param  array{raw: string, op: string, left: list<array{0: string, 1: bool}>|null, right: list<array{0: string, 1: bool}>|null}  $term
     * @return Closure(Builder): void
     */
    private static function scalarCondition(FilterField $field, array $term, string $timezone): Closure
    {
        $column = $field->column;
        $op = $term['op'];

        if ($op === '..') {
            $bounds = [];
            if ($term['left'] !== null) {
                $bounds[] = ['>=', self::scalar($field, $term['left'], $timezone)];
            }
            if ($term['right'] !== null) {
                $bounds[] = ['<=', self::scalar($field, $term['right'], $timezone)];
            }

            return self::bounds($column, $bounds);
        }

        $chars = $term['left'] ?? [];
        if (self::isBlank($chars)) {
            return self::nullCheck($column, $op);
        }

        $value = self::scalar($field, $chars, $timezone);

        return static function (Builder $query) use ($column, $op, $value): void {
            if ($op !== '<>') {
                $query->where($column, $op, $value);

                return;
            }

            $query->where(static function (Builder $not) use ($column, $value): void {
                $not->where($column, '<>', $value)->orWhereNull($column);
            });
        };
    }

    /**
     * Tanggal pada kolom tanggal-jam berarti satu hari penuh di zona pengguna: dari awal hari itu sampai
     * sebelum awal hari berikutnya, keduanya dalam UTC.
     *
     * @param  array{raw: string, op: string, left: list<array{0: string, 1: bool}>|null, right: list<array{0: string, 1: bool}>|null}  $term
     * @return Closure(Builder): void
     */
    private static function dateTimeCondition(FilterField $field, array $term, string $timezone): Closure
    {
        $column = $field->column;
        $op = $term['op'];

        if ($op === '..') {
            $bounds = [];
            if ($term['left'] !== null) {
                $bounds[] = ['>=', self::day($field, $term['left'], $timezone)[0]];
            }
            if ($term['right'] !== null) {
                $bounds[] = ['<', self::day($field, $term['right'], $timezone)[1]];
            }

            return self::bounds($column, $bounds);
        }

        $chars = $term['left'] ?? [];
        if (self::isBlank($chars)) {
            return self::nullCheck($column, $op);
        }

        [$start, $end] = self::day($field, $chars, $timezone);

        if ($op === '<>') {
            return static function (Builder $query) use ($column, $start, $end): void {
                $query->where(static function (Builder $not) use ($column, $start, $end): void {
                    $not->where($column, '<', $start)->orWhere($column, '>=', $end)->orWhereNull($column);
                });
            };
        }

        return self::bounds($column, match ($op) {
            '=' => [['>=', $start], ['<', $end]],
            '>' => [['>=', $end]],
            '>=' => [['>=', $start]],
            '<' => [['<', $start]],
            default => [['<', $end]],
        });
    }

    /**
     * Ya/tidak, pilihan, dan rujukan: daftar nilai dari pemilih, dibaca sebagai ATAU. String tunggal
     * dianggap daftar berisi satu nilai; nilai kosong dilewati.
     *
     * @param  string|array<array-key, mixed>  $value
     * @return (Closure(Builder): void)|null
     */
    private static function selection(FilterField $field, string|array $value): ?Closure
    {
        $values = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_bool($item) && $field->type === FieldType::Boolean) {
                $item = $item ? '1' : '0';
            } elseif (is_int($item)) {
                $item = (string) $item;
            }

            if (! is_string($item)) {
                throw self::fail($field, 'isiannya harus berupa daftar pilihan.');
            }

            $item = trim($item);
            if ($item !== '') {
                $values[] = $item;
            }
        }

        $values = array_values(array_unique($values));
        if ($values === []) {
            return null;
        }

        if (count($values) > self::MAX_SELECTIONS) {
            throw self::fail($field, 'pilihannya terlalu banyak, maksimal '.self::MAX_SELECTIONS.'.');
        }

        $column = $field->column;

        if ($field->type === FieldType::Boolean) {
            $booleans = array_values(array_unique(array_map(static fn (string $item): bool => match ($item) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => throw self::fail($field, '"'.$item.'" bukan pilihan ya atau tidak.'),
            }, $values)));

            return static function (Builder $query) use ($column, $booleans): void {
                if (count($booleans) === 1) {
                    $query->where($column, '=', $booleans[0]);

                    return;
                }

                $query->whereIn($column, $booleans);
            };
        }

        foreach ($values as $item) {
            if ($field->type === FieldType::Option && ! array_key_exists($item, $field->options)) {
                throw self::fail($field, '"'.$item.'" tidak ada di daftar pilihan.');
            }

            if ($field->type === FieldType::Reference && mb_strlen($item) > self::MAX_REFERENCE_LENGTH) {
                throw self::fail($field, '"'.mb_substr($item, 0, 20).'…" terlalu panjang untuk sebuah pilihan.');
            }
        }

        return static function (Builder $query) use ($column, $values): void {
            $query->whereIn($column, $values);
        };
    }

    /**
     * @param  list<array{0: string, 1: string}>  $bounds  pasangan operator dan nilai, digabung DAN
     * @return Closure(Builder): void
     */
    private static function bounds(string $column, array $bounds): Closure
    {
        return static function (Builder $query) use ($column, $bounds): void {
            foreach ($bounds as [$op, $value]) {
                $query->where($column, $op, $value);
            }
        };
    }

    /**
     * `''` pada angka dan tanggal: kolom itu tidak punya "kosong" selain NULL.
     *
     * @return Closure(Builder): void
     */
    private static function nullCheck(string $column, string $op): Closure
    {
        return static function (Builder $query) use ($column, $op): void {
            $query->whereNull($column, not: $op === '<>');
        };
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $chars
     */
    private static function scalar(FilterField $field, array $chars, string $timezone): string
    {
        return $field->type === FieldType::Number
            ? self::number($field, self::text($chars))
            : self::date($field, self::text($chars), $timezone);
    }

    /**
     * Awal hari itu dan awal hari berikutnya di zona pengguna, dalam UTC.
     *
     * @param  list<array{0: string, 1: bool}>  $chars
     * @return array{0: string, 1: string}
     */
    private static function day(FilterField $field, array $chars, string $timezone): array
    {
        $start = new CarbonImmutable(self::date($field, self::text($chars), $timezone), $timezone);

        return [
            $start->utc()->format('Y-m-d H:i:s'),
            $start->addDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * `@` di depan nilai teks (di luar petik) berarti tidak peka huruf besar.
     *
     * @param  list<array{0: string, 1: bool}>  $chars
     * @return array{0: list<array{0: string, 1: bool}>, 1: bool}
     */
    private static function caseMode(FilterField $field, string $raw, array $chars): array
    {
        if (($chars[0] ?? null) !== ['@', false]) {
            return [$chars, false];
        }

        $chars = self::trim(array_slice($chars, 1));
        if ($chars === []) {
            throw self::fail($field, '"'.$raw.'" belum diberi nilai.');
        }

        return [$chars, true];
    }

    private static function number(FilterField $field, string $text): string
    {
        // `1.000` berarti seribu, seperti yang diketik pengguna Indonesia, bukan satu.
        if (preg_match('/^-?\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $text) === 1) {
            return str_replace(['.', ','], ['', '.'], $text);
        }

        if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $text) === 1) {
            return str_replace(',', '.', $text);
        }

        throw self::fail($field, '"'.$text.'" bukan angka. Contoh: 1000000, 1.000.000, atau 1.000.000,50.');
    }

    /** Tanggal dalam bentuk `Y-m-d`. `t` dibaca menurut zona pengguna, bukan jam server. */
    private static function date(FilterField $field, string $text, string $timezone): string
    {
        if ($text === 't' || $text === 'T') {
            return CarbonImmutable::now($timezone)->format('Y-m-d');
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $match) === 1) {
            [, $year, $month, $day] = $match;
        } elseif (preg_match('/^(\d{1,2})([\/-])(\d{1,2})\2(\d{4})$/', $text, $match) === 1) {
            [, $day, , $month, $year] = $match;
        } else {
            throw self::fail($field, '"'.$text.'" bukan tanggal. '.self::DATE_HINT);
        }

        if ((int) $year < 1 || ! checkdate((int) $month, (int) $day, (int) $year)) {
            throw self::fail($field, '"'.$text.'" bukan tanggal yang ada di kalender.');
        }

        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $chars
     */
    private static function likePattern(array $chars): string
    {
        $pattern = '';
        foreach ($chars as [$character, $quoted]) {
            $pattern .= match (true) {
                ! $quoted && $character === '*' => '%',
                ! $quoted && $character === '?' => '_',
                in_array($character, ['\\', '%', '_'], true) => '\\'.$character,
                default => $character,
            };
        }

        return $pattern;
    }

    /**
     * Operator di awal nilai, hanya bila berada di luar petik.
     *
     * @param  list<array{0: string, 1: bool}>  $chars
     */
    private static function operator(array $chars): ?string
    {
        foreach (self::OPERATORS as $operator) {
            $matches = true;
            foreach (str_split($operator) as $i => $character) {
                if (($chars[$i] ?? null) !== [$character, false]) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return $operator;
            }
        }

        return null;
    }

    /**
     * Spasi di luar petik pada kedua ujung dibuang; spasi di tengah nilai tetap bagian dari nilai.
     *
     * @param  list<array{0: string, 1: bool}>  $chars
     * @return list<array{0: string, 1: bool}>
     */
    private static function trim(array $chars): array
    {
        while ($chars !== [] && ! $chars[0][1] && trim($chars[0][0]) === '') {
            array_shift($chars);
        }

        while ($chars !== [] && ! $chars[count($chars) - 1][1] && trim($chars[count($chars) - 1][0]) === '') {
            array_pop($chars);
        }

        return $chars;
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $chars
     */
    private static function text(array $chars): string
    {
        return implode('', array_column($chars, 0));
    }

    /**
     * Kosong berarti hanya sepasang petik tanpa isi: `''`.
     *
     * @param  list<array{0: string, 1: bool}>  $chars
     */
    private static function isBlank(array $chars): bool
    {
        return $chars !== [] && self::text($chars) === '';
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $chars
     */
    private static function hasWildcard(array $chars): bool
    {
        foreach ($chars as [$character, $quoted]) {
            if (! $quoted && ($character === '*' || $character === '?')) {
                return true;
            }
        }

        return false;
    }

    private static function blankMisused(FilterField $field, string $raw): InvalidFilterExpression
    {
        return self::fail($field, '"'.$raw.'": kosong (\'\') hanya bisa dipakai sendiri atau dengan <>.');
    }

    private static function fail(FilterField $field, string $message): InvalidFilterExpression
    {
        return new InvalidFilterExpression('Filter "'.$field->caption.'": '.$message);
    }
}
