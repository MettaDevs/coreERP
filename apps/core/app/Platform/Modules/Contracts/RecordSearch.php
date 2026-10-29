<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;
use Normalizer;

/** Aturan pencarian record bersama; scope, kolom, dan urutan tetap milik pemilik data. */
final class RecordSearch
{
    public const MAX_QUERY_LENGTH = 200;

    /** @return list<array{phrase: bool, text: string}> */
    public static function terms(string $query): array
    {
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw ValidationException::withMessages(['q' => 'Gunakan paling banyak 200 karakter untuk pencarian.']);
        }

        $query = self::normalize($query);
        preg_match_all('/"([^"]*)"|([^\s"]+)/u', $query, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $terms = [];
        foreach ($matches as $match) {
            $phrase = $match[1] !== null;
            $text = trim($phrase ? $match[1] : $match[2]);
            if (self::words($text) !== []) {
                $terms[] = ['phrase' => $phrase, 'text' => $text];
            }
        }

        return $terms;
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', self::normalize($text), $matches);

        return $matches[0];
    }

    /**
     * @param EloquentBuilder<*>|Builder $query
     * @param  list<string>  $searchColumns
     * @param  list<string>  $identifierColumns
     */
    public static function apply(EloquentBuilder|Builder $query, array $searchColumns, string $keyword, array $identifierColumns = []): void
    {
        if ($searchColumns === []) {
            return;
        }
        $identifierColumns = array_values(array_intersect($identifierColumns, $searchColumns));

        self::compose($query, $keyword, function (Builder $terms, string $text, bool $phrase) use ($searchColumns, $identifierColumns): void {
            $term = ['text' => $text, 'phrase' => $phrase];
            $terms->where(function (Builder $fields) use ($searchColumns, $identifierColumns, $term): void {
                foreach ($searchColumns as $column) {
                    $fields->orWhere(fn (Builder $field) => self::matchTerm($field, $column, $term, in_array($column, $identifierColumns, true)));
                }
            });
        }, $identifierColumns === [] ? null : function (Builder $fields, string $text, bool $phrase) use ($identifierColumns): void {
            foreach ($identifierColumns as $column) {
                $fields->orWhere(fn (Builder $field) => self::matchTerm($field, $column, ['text' => $text, 'phrase' => $phrase], true));
            }
        });
    }

    /**
     * Kandidat relasi tetap dibangun pemilik resource agar indeks sumber dapat dipakai.
     *
     * @param EloquentBuilder<*>|Builder $query
     * @param  Closure(array{phrase: bool, text: string}): Builder  $candidates
     * @param  ?Closure(array{phrase: bool, text: string}): Builder  $identifierCandidates
     */
    public static function applyCandidates(EloquentBuilder|Builder $query, string $keyword, Closure $candidates, ?Closure $identifierCandidates = null, string $identity = 'id'): void
    {
        self::compose($query, $keyword,
            fn (Builder $terms, string $text, bool $phrase) => $terms->whereIn($identity, $candidates(['text' => $text, 'phrase' => $phrase])),
            $identifierCandidates === null ? null : fn (Builder $fields, string $text, bool $phrase) => $fields->whereIn($identity, $identifierCandidates(['text' => $text, 'phrase' => $phrase])),
        );
    }

    /** @param array{phrase: bool, text: string} $term */
    public static function matchTerm(Builder $query, string $column, array $term, bool $identifier = false): void
    {
        $vector = self::vectorExpression($query->getGrammar(), $column, $identifier);
        $words = self::words($term['text']);
        if ($words === []) {
            $query->whereRaw('false');

            return;
        }

        if (! $term['phrase']) {
            $prefix = self::prefixQuery($term['text']);
            $compact = self::compactIdentifier($term['text']);
            if ($identifier && $compact !== self::normalize($term['text']) && count(self::words($compact)) === 1) {
                $prefix = '('.$prefix.') | ('.self::prefixQuery($compact).')';
            }
            $query->whereRaw(self::expression(fn (Grammar $grammar) => $vector." @@ to_tsquery('simple'::regconfig, ?)"), [$prefix]);

            return;
        }

        $phrase = implode(' ', $words);
        if (! $identifier) {
            $query->whereRaw(self::expression(fn (Grammar $grammar) => $vector." @@ phraseto_tsquery('simple'::regconfig, ?)"), [$phrase]);

            return;
        }
        $query->where(function (Builder $match) use ($column, $vector, $phrase, $term): void {
            $match->where(function (Builder $field) use ($column, $vector, $phrase): void {
                $field->whereRaw(self::expression(fn (Grammar $grammar) => $vector." @@ phraseto_tsquery('simple'::regconfig, ?)"), [$phrase])
                    ->whereRaw(self::expression(fn (Grammar $grammar) => self::vectorExpression($grammar, $column)." @@ phraseto_tsquery('simple'::regconfig, ?)"), [$phrase]);
            });
            $compact = self::compactIdentifier($term['text']);
            $match->orWhere(function (Builder $field) use ($column, $vector, $compact): void {
                $field->whereRaw(self::expression(fn (Grammar $grammar) => $vector." @@ plainto_tsquery('simple'::regconfig, ?)"), [$compact])
                    ->whereRaw(self::expression(fn (Grammar $grammar) => 'lower('.self::compactExpression($grammar, $column).') = ?'), [$compact]);
            });
        });
    }

    public static function vectorExpression(Grammar $grammar, string $column, bool $identifier = false): string
    {
        $text = self::textExpression($grammar, $column);
        $input = "regexp_replace({$text}, '[^[:alnum:]]+', ' ', 'g')";
        if ($identifier) {
            $input .= " || ' ' || ".self::compactExpression($grammar, $column);
        }

        return "to_tsvector('simple'::regconfig, {$input})";
    }

    public static function compactIdentifier(string $text): string
    {
        return preg_replace('/[\s.\-]+/u', '', self::normalize($text)) ?? '';
    }

    public static function prefixQuery(string $text): string
    {
        return implode(' & ', array_map(static fn (string $word): string => "'{$word}':*", self::words($text)));
    }

    /**
     * @param EloquentBuilder<*>|Builder $query
     * @param  Closure(Builder, string, bool): mixed  $termMatcher
     * @param  ?Closure(Builder, string, bool): mixed  $identifierMatcher
     */
    private static function compose(EloquentBuilder|Builder $query, string $keyword, Closure $termMatcher, ?Closure $identifierMatcher): void
    {
        $terms = self::terms($keyword);
        if (trim(self::normalize($keyword)) === '') {
            return;
        }
        $builder = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        if ($terms === []) {
            $builder->whereRaw('false');

            return;
        }
        $compact = self::compactIdentifier($keyword);
        $wholeIdentifier = ! str_contains($keyword, '"') && preg_match('/^[\p{L}\p{N}]+$/u', $compact) === 1;
        $builder->where(function (Builder $match) use ($terms, $termMatcher, $identifierMatcher, $wholeIdentifier, $compact): void {
            $match->where(function (Builder $all) use ($terms, $termMatcher): void {
                foreach ($terms as $term) {
                    $termMatcher($all, $term['text'], $term['phrase']);
                }
            });
            if ($wholeIdentifier && $identifierMatcher !== null) {
                $match->orWhere(fn (Builder $identifiers) => $identifierMatcher($identifiers, $compact, false));
            }
        });
    }

    public static function normalize(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
        if ($normalized === false) {
            throw ValidationException::withMessages(['q' => 'Gunakan teks pencarian yang valid.']);
        }

        return mb_strtolower(preg_replace('/[\s\p{Z}\x{FEFF}]+/u', ' ', $normalized) ?? $normalized);
    }

    private static function textExpression(Grammar $grammar, string $column): string
    {
        return 'normalize(coalesce(CAST('.$grammar->wrap($column)." AS text), ''), NFC)";
    }

    private static function compactExpression(Grammar $grammar, string $column): string
    {
        return 'regexp_replace('.self::textExpression($grammar, $column).", '[[:space:].\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\ufeff-]+', '', 'g')";
    }

    /** @param Closure(Grammar): string $compile SQL dari grammar dan metadata server; nilai tetap memakai binding. */
    private static function expression(Closure $compile): Expression
    {
        return new class($compile) implements Expression
        {
            public function __construct(private readonly Closure $compile) {}

            public function getValue(Grammar $grammar): string
            {
                return ($this->compile)($grammar);
            }
        };
    }
}
