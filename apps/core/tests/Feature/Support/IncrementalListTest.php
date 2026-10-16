<?php

namespace Tests\Feature\Support;

use App\Platform\Modules\Contracts\IncrementalList;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IncrementalListTest extends TestCase
{
    private function batch(array $input, bool $native = false): array
    {
        $request = Request::create('/records', 'GET', $input);
        $this->app->instance('request', $request);
        $query = DB::query()->fromRaw("(VALUES ('a', 'Same', 2, 'one'), ('b', 'Same', 10, 'one'), ('c', NULL, 3, 'one'), ('d', 'Other', 1, 'two')) AS fixture(id, name, amount, tenant)")->select('id', 'name', 'amount')->where('tenant', 'one');

        return IncrementalList::query($request, $query, ['id' => 'id', 'name' => 'name', 'amount' => 'amount'], ['name'], 'name', ['amount'], nativeSortColumns: $native ? ['id' => 'string', 'name' => 'string', 'amount' => 'numeric'] : []);
    }

    public function test_native_order_preserves_legacy_cursors_ties_nulls_and_numeric_order(): void
    {
        foreach (['id', 'name', 'amount'] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $expected = $this->batch(compact('sort', 'direction'))['data']->pluck('id')->all();
                $first = $this->batch(['per_page' => 1, ...compact('sort', 'direction')]);
                $actual = $first['data']->pluck('id')->all();
                $cursor = $first['next_cursor'];
                while ($cursor !== null) {
                    $next = $this->batch(['per_page' => 1, 'cursor' => $cursor, ...compact('sort', 'direction')], true);
                    array_push($actual, ...$next['data']->pluck('id')->all());
                    $cursor = $next['next_cursor'];
                    $this->assertLessThanOrEqual(3, count($actual));
                }
                $this->assertSame($expected, $actual);
            }
        }
        $this->assertSame(['a', 'b'], $this->batch(['q' => 'Same'], true)['data']->pluck('id')->all());
    }

    public function test_native_previous_cursor_keeps_a_forward_cursor(): void
    {
        $cursor = (new Cursor(['list_sort_null' => 0, 'list_sort_value' => 'Same', 'id' => 'b'], false))->encode();
        $previous = $this->batch(['per_page' => 1, 'cursor' => $cursor], true);
        $this->assertSame(['a'], $previous['data']->pluck('id')->all());
        $next = $this->batch(['per_page' => 1, 'cursor' => $previous['next_cursor']], true);
        $this->assertSame(['b'], $next['data']->pluck('id')->all());
    }

    public function test_native_date_sort_rejects_a_cursor_from_another_column(): void
    {
        $cursor = (new Cursor(['list_sort_null' => 0, 'list_sort_value' => 'not-a-date', 'id' => 'a']))->encode();
        $request = Request::create('/records', 'GET', ['cursor' => $cursor]);
        $this->app->instance('request', $request);
        $query = DB::query()->fromRaw("(VALUES ('a', TIMESTAMP '2026-10-17 09:00:00')) AS fixture(id, recorded_on)")->select('id', 'recorded_on');
        $this->expectException(ValidationException::class);
        IncrementalList::query($request, $query, ['date' => 'recorded_on'], [], 'date', nativeSortColumns: ['recorded_on' => 'date']);
    }

    public function test_native_dates_keep_ties_and_nulls_with_legacy_cursors(): void
    {
        foreach (['asc' => ['a', 'b', 'c'], 'desc' => ['b', 'a', 'c']] as $direction => $expected) {
            $ids = [];
            $cursor = null;
            do {
                $request = Request::create('/records', 'GET', ['direction' => $direction, 'per_page' => 1, 'cursor' => $cursor]);
                $this->app->instance('request', $request);
                $query = DB::query()->fromRaw("(VALUES ('a', TIMESTAMPTZ '2026-10-17 09:00:00.123456+00'), ('b', TIMESTAMPTZ '2026-10-17 09:00:00.123456+00'), ('c', NULL)) AS fixture(id, recorded_on)")->select('id', 'recorded_on');
                $batch = IncrementalList::query($request, $query, ['date' => 'recorded_on'], [], 'date', nativeSortColumns: $ids === [] ? [] : ['recorded_on' => 'date']);
                array_push($ids, ...$batch['data']->pluck('id')->all());
                $cursor = $batch['next_cursor'];
                $this->assertLessThanOrEqual(3, count($ids));
            } while ($cursor !== null);
            $this->assertSame($expected, $ids);
        }
    }

    public function test_cursor_walk_keeps_scope_ties_and_null_rows(): void
    {
        $ids = [];
        $cursor = null;
        do {
            $batch = $this->batch(['per_page' => 1, 'cursor' => $cursor]);
            array_push($ids, ...$batch['data']->pluck('id')->all());
            $cursor = $batch['next_cursor'];
            self::assertLessThanOrEqual(3, count($ids));
        } while ($cursor !== null);
        self::assertSame(['a', 'b', 'c'], $ids);
        self::assertFalse(property_exists($batch['data'][0], 'list_sort_value'));
    }

    public function test_numeric_sort_and_search_apply_before_batch_limit(): void
    {
        self::assertSame(['b', 'c', 'a'], $this->batch(['sort' => 'amount', 'direction' => 'desc'])['data']->pluck('id')->all());
        self::assertCount(0, $this->batch(['q' => 'Other'])['data']);
        self::assertCount(2, $this->batch(['q' => 'Same'])['data']);
        self::assertCount(0, $this->batch(['q' => '%'])['data']);
    }

    public function test_unknown_sort_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->batch(['sort' => 'name desc; select 1']);
    }

    public function test_malformed_cursor_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->batch(['cursor' => 'invalid']);
    }
}
