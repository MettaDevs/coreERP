<?php

namespace Tests\Feature\Support;

use App\Platform\Modules\Contracts\IncrementalList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IncrementalListTest extends TestCase
{
    private function batch(array $input): array
    {
        $request = Request::create('/records', 'GET', $input);
        $this->app->instance('request', $request);
        $query = DB::query()->fromRaw("(VALUES ('a', 'Same', 2, 'one'), ('b', 'Same', 10, 'one'), ('c', NULL, 3, 'one'), ('d', 'Other', 1, 'two')) AS fixture(id, name, amount, tenant)")->select('id', 'name', 'amount')->where('tenant', 'one');

        return IncrementalList::query($request, $query, ['id' => 'id', 'name' => 'name', 'amount' => 'amount'], ['name'], 'name', ['amount']);
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
