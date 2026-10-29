<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RecordSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordSearchTest extends TestCase
{
    public function test_database_matching_agrees_with_shared_sdk_cases(): void
    {
        $fixtures = json_decode(file_get_contents(dirname(__DIR__, 5).'/packages/ui/tests/fixtures/record-search.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            $columns = [];
            $identifiers = [];
            $bindings = [];
            $projection = [];
            foreach ($fixture['fields'] as $index => $field) {
                $column = 'field_'.$index;
                $columns[] = $column;
                $projection[] = 'CAST(? AS text) AS '.$column;
                $bindings[] = is_array($field) ? $field['text'] : $field;
                if (is_array($field) && ($field['identifier'] ?? false)) {
                    $identifiers[] = $column;
                }
            }
            $row = DB::query()->selectRaw(implode(', ', $projection), $bindings);
            $query = DB::query()->fromSub($row, 'fixture');
            RecordSearch::apply($query, $columns, $fixture['query'], $identifiers);
            $this->assertSame($fixture['matches'], $query->exists(), $fixture['name']);
        }
    }

    public function test_search_does_not_escape_existing_tenant_and_status_conditions(): void
    {
        $query = DB::query()->fromRaw("(VALUES ('a', 'one', 'active', 'VND000001', 'Surya'), ('b', 'two', 'active', 'VND000001', 'Surya'), ('c', 'one', 'inactive', 'VND000001', 'Surya')) AS fixture(id, tenant, status, number, name)")
            ->where('tenant', 'one')->where('status', 'active');
        RecordSearch::apply($query, ['number', 'name'], 'vnd 000001', ['number']);

        $this->assertSame(['a'], $query->pluck('id')->all());
    }

    public function test_empty_field_allowlist_preserves_a_resource_adapter_filter(): void
    {
        $query = DB::query()->fromRaw("(VALUES ('a', 'Surya Jaya'), ('b', 'Surya Utama')) AS fixture(id, name)")->where('id', 'a');
        RecordSearch::apply($query, [], 'sur ja');

        $this->assertSame(['a'], $query->pluck('id')->all());
    }

    public function test_identifier_metadata_cannot_expand_searchable_fields(): void
    {
        $query = DB::query()->fromRaw("(VALUES ('a', 'VND000001', 'Surya Jaya')) AS fixture(id, number, name)");
        RecordSearch::apply($query, ['name'], 'vnd 000001', ['number']);

        $this->assertFalse($query->exists());
    }

    public function test_shared_search_rejects_overlong_queries_without_truncation(): void
    {
        $this->expectException(ValidationException::class);
        RecordSearch::terms(str_repeat('é', RecordSearch::MAX_QUERY_LENGTH + 1));
    }

    public function test_quoted_repeated_words_are_not_deduplicated(): void
    {
        $query = DB::query()->fromRaw("(VALUES ('a', 'Jaya'), ('b', 'Jaya Jaya')) AS fixture(id, name)");
        RecordSearch::apply($query, ['name'], '"jaya jaya"');

        $this->assertSame(['b'], $query->pluck('id')->all());
    }

    public function test_incremental_lookup_uses_shared_matching_without_changing_cursor_order(): void
    {
        $next = null;
        $ids = [];
        do {
            $request = Request::create('/lookup', 'GET', ['q' => 'jaya sur', 'per_page' => 1, 'cursor' => $next]);
            $query = DB::query()->fromRaw("(VALUES ('a', 'one', 'Surya Jaya'), ('b', 'one', 'Surya Abadi Jaya'), ('c', 'one', 'Surya Utama'), ('d', 'two', 'Surya Jaya')) AS fixture(id, tenant, name)")
                ->where('tenant', 'one')->select('id', 'name');
            $page = IncrementalList::query($request, $query, ['id' => 'id', 'name' => 'name'], ['name'], 'id');
            array_push($ids, ...$page['data']->pluck('id')->all());
            $next = $page['next_cursor'];
            $this->assertLessThanOrEqual(2, count($ids));
        } while ($next !== null);

        $this->assertSame(['a', 'b'], $ids);
    }

    public function test_incremental_lookup_uses_declared_identifier_metadata(): void
    {
        $request = Request::create('/lookup', 'GET', ['q' => 'vnd 000001']);
        $query = DB::query()->fromRaw("(VALUES ('a', 'VND000001'), ('b', 'VND000002')) AS fixture(id, number)")->select('id', 'number');
        $page = IncrementalList::query($request, $query, ['id' => 'id', 'number' => 'number'], ['number'], 'number', identifierSearchColumns: ['number']);

        $this->assertSame(['a'], $page['data']->pluck('id')->all());
    }
}
