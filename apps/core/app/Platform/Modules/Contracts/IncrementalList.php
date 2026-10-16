<?php

namespace App\Platform\Modules\Contracts;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Membatasi daftar setelah scope pemilik diterapkan; kolom selalu berasal dari allowlist server. */
final class IncrementalList
{
    /**
     * @param  array<string, string>  $columns
     * @param  list<string>  $searchColumns
     * @param  list<string>  $numericColumns
     * @return array{data: Collection<int, \stdClass>, next_cursor: ?string}
     */
    public static function query(Request $request, Builder $query, array $columns, array $searchColumns, string $defaultSort = 'id', array $numericColumns = [], string $defaultDirection = 'asc', string $identity = 'id'): array
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', Rule::in(array_keys($columns))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'cursor' => ['nullable', 'string', 'max:2000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'record_id' => ['nullable', 'string', 'max:100'],
            'record_ids' => ['sometimes', 'array', 'max:100'],
            'record_ids.*' => ['required', 'string', 'max:100'],
        ]);
        // Alias lookup dan agregat menjadi kolom nyata pada query luar; tidak menghitung total daftar.
        $list = $query->newQuery()->fromSub($query->reorder(), 'list_records');
        if (($input['record_id'] ?? '') !== '' && ($input['record_id'] ?? null) !== null) {
            $list->where('id', $input['record_id']);
        }
        if (array_key_exists('record_ids', $input)) {
            $list->whereIn('id', $input['record_ids']);
        }
        $keyword = trim((string) ($input['q'] ?? ''));
        if ($keyword !== '') {
            $pattern = '%'.addcslashes(mb_strtolower($keyword), '\\%_').'%';
            $list->where(function (Builder $match) use ($searchColumns, $pattern): void {
                foreach ($searchColumns as $column) {
                    $match->orWhere(self::columnExpression($column, 'LOWER(CAST(%s AS TEXT))'), 'like', $pattern);
                }
            });
        }
        $sort = $columns[$input['sort'] ?? $defaultSort];
        $direction = $input['direction'] ?? (isset($input['sort']) ? 'asc' : $defaultDirection);
        // NULL juga harus memiliki posisi supaya cursor tidak melewatkan record.
        $list->select('list_records.*')->addSelect(
            self::columnExpression($sort, 'CASE WHEN %s IS NULL THEN 1 ELSE 0 END AS list_sort_null'),
            self::columnExpression($sort, in_array($sort, $numericColumns, true) ? 'COALESCE(%s, 0) AS list_sort_value' : "COALESCE(CAST(%s AS TEXT), '') AS list_sort_value"),
        );
        $list = $list->newQuery()->fromSub($list, 'sorted_records');
        $list->orderBy('list_sort_null')->orderBy('list_sort_value', $direction);
        if ($sort !== $identity) {
            $list->orderBy($identity, $direction);
        }
        $hasCursor = ($input['cursor'] ?? '') !== '' && ($input['cursor'] ?? null) !== null;
        try {
            $cursor = $hasCursor ? Cursor::fromEncoded($input['cursor']) : null;
        } catch (\TypeError) {
            $cursor = null;
        }
        $parameters = $cursor?->toArray() ?? [];
        if ($hasCursor && ($cursor === null
            || array_diff(['list_sort_null', 'list_sort_value', ...($sort === $identity ? [] : [$identity])], array_keys($parameters)) !== []
            || count(array_filter($parameters, is_scalar(...))) !== count($parameters)
            || ! in_array($parameters['list_sort_null'] ?? null, [0, 1], true)
            || (in_array($sort, $numericColumns, true) && ! is_numeric($parameters['list_sort_value'] ?? null)))) {
            throw ValidationException::withMessages(['cursor' => 'Posisi daftar tidak valid. Muat ulang daftar.']);
        }
        $batch = $list->cursorPaginate((int) ($input['per_page'] ?? 40), ['*'], 'cursor', $cursor);
        $nextCursor = $batch->nextCursor()?->encode();
        $rows = collect($batch->items())->map(function ($row) {
            unset($row->list_sort_null, $row->list_sort_value);

            return $row;
        });

        return ['data' => $rows, 'next_cursor' => $nextCursor];
    }

    /** @param literal-string $template SQL tetap; hanya nama kolom dari allowlist yang dibungkus grammar. */
    private static function columnExpression(string $column, string $template): Expression
    {
        return new class($column, $template) implements Expression
        {
            public function __construct(private readonly string $column, private readonly string $template) {}

            public function getValue(Grammar $grammar): string
            {
                return sprintf($this->template, $grammar->wrap($this->column));
            }
        };
    }
}
