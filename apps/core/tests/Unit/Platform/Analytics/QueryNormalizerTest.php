<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\Dimension;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\TimeGranularity;
use App\Platform\Analytics\Query\TimeRange;
use PHPUnit\Framework\TestCase;

/**
 * Normalisasi query analitik (area 2), tanpa database: dua JSON yang setara menjadi satu bentuk, dan dua
 * yang berbeda arti tetap berbeda. Hash query dan kunci cache dihitung dari bentuk normalnya, jadi
 * dua query yang setara tetapi berbeda penulisan yang menghasilkan hash berbeda berarti cache ganda.
 */
class QueryNormalizerTest extends TestCase
{
    private const BASE = ['dataset' => 'modul.dataset-contoh', 'measures' => ['count']];

    /** @param array<string, mixed> $input */
    private function normalized(array $input): AnalyticsQuery
    {
        return (new QueryNormalizer)->normalize((new QueryParser)->parse($input));
    }

    /** @param array<string, mixed> $input */
    private function hash(array $input): string
    {
        return hash('sha256', json_encode($this->normalized($input)->normalized(), JSON_THROW_ON_ERROR));
    }

    public function test_two_json_documents_that_differ_only_in_writing_become_one_query(): void
    {
        $a = [
            'dataset' => 'modul.dataset-contoh',
            'dimensions' => ['group_id', ['field' => 'acquired_on', 'granularity' => 'month']],
            'measures' => ['count', 'value'],
            'filters' => ['name' => '*laptop*', 'lifecycle_state' => ['received', 'disposed']],
            'time_range' => ['range' => '@this_year'],
            'sort' => [['key' => 'value', 'direction' => 'desc']],
            'limit' => 10,
        ];
        // Urutan kunci berbeda, pilihan berbeda urutan dan berulang, spasi di ujung isian, bawaan ditulis,
        // dan dimensi tanpa ember waktu ditulis sebagai objek.
        $b = [
            'limit' => 10,
            'sort' => [['direction' => 'desc', 'key' => 'value']],
            'time_range' => ['range' => ' @this_year '],
            'filters' => ['lifecycle_state' => [' disposed', 'received', 'disposed'], 'name' => '  *laptop*'],
            'measures' => ['count', 'value'],
            'dimensions' => [['field' => 'group_id'], ['granularity' => 'month', 'field' => 'acquired_on']],
            'dataset' => ' modul.dataset-contoh ',
            'totals' => false,
            'fill_gaps' => true,
        ];

        $this->assertEquals($this->normalized($a), $this->normalized($b));
        $this->assertSame($this->hash($a), $this->hash($b));
    }

    public function test_filter_keys_are_ordered_and_choice_values_are_sorted_and_deduplicated(): void
    {
        $query = $this->normalized([...self::BASE, 'filters' => [
            'z_field' => 'x',
            'a_field' => ['10', '9', 'b', 'a', 'a', '9'],
        ]]);

        $this->assertSame(['a_field' => ['10', '9', 'a', 'b'], 'z_field' => 'x'], $query->filters);
        $this->assertSame(['a_field', 'z_field'], array_keys($query->filters));
    }

    public function test_blank_filters_are_dropped_because_they_filter_nothing(): void
    {
        $query = $this->normalized([...self::BASE, 'filters' => [
            'name' => '   ',
            'lifecycle_state' => [],
            'currency_code' => ['', '  '],
            'kept' => ' x ',
        ]]);

        $this->assertSame(['kept' => 'x'], $query->filters);
        $this->assertSame($this->hash(self::BASE), $this->hash([...self::BASE, 'filters' => ['name' => '', 'lifecycle_state' => []]]));
    }

    public function test_an_empty_marker_in_quotes_is_not_blank(): void
    {
        // `''` pada filter teks berarti "kosong" di sintaks Business Central; itu isian yang berarti.
        $this->assertSame(['name' => "''"], $this->normalized([...self::BASE, 'filters' => ['name' => "''"]])->filters);
    }

    public function test_order_that_changes_the_result_is_kept(): void
    {
        $this->assertNotSame($this->hash([...self::BASE, 'measures' => ['count', 'value']]), $this->hash([...self::BASE, 'measures' => ['value', 'count']]));
        $this->assertNotSame($this->hash([...self::BASE, 'dimensions' => ['a', 'b']]), $this->hash([...self::BASE, 'dimensions' => ['b', 'a']]));

        $ascendingFirst = [['key' => 'a', 'direction' => 'asc'], ['key' => 'b', 'direction' => 'desc']];
        $descendingFirst = [['key' => 'b', 'direction' => 'desc'], ['key' => 'a', 'direction' => 'asc']];
        $this->assertNotSame($this->hash([...self::BASE, 'sort' => $ascendingFirst]), $this->hash([...self::BASE, 'sort' => $descendingFirst]));
        $this->assertSame($ascendingFirst, $this->normalized([...self::BASE, 'sort' => $ascendingFirst])->sort);
    }

    public function test_queries_that_mean_something_different_stay_different(): void
    {
        $base = $this->hash(self::BASE);

        $this->assertNotSame($base, $this->hash([...self::BASE, 'limit' => 5]));
        $this->assertNotSame($base, $this->hash([...self::BASE, 'totals' => true]));
        $this->assertNotSame($base, $this->hash([...self::BASE, 'time_range' => ['range' => '@today']]));
        $this->assertNotSame($this->hash([...self::BASE, 'time_range' => ['range' => '@today']]), $this->hash([...self::BASE, 'time_range' => ['range' => '@yesterday']]));
        $this->assertNotSame($this->hash([...self::BASE, 'filters' => ['name' => 'a']]), $this->hash([...self::BASE, 'filters' => ['name' => 'A']]));
        $this->assertNotSame($this->hash([...self::BASE, 'dimensions' => [['field' => 'acquired_on', 'granularity' => 'month']]]), $this->hash([...self::BASE, 'dimensions' => [['field' => 'acquired_on', 'granularity' => 'year']]]));
        $this->assertNotSame($this->hash([...self::BASE, 'dimensions' => ['acquired_on']]), $this->hash([...self::BASE, 'dimensions' => [['field' => 'acquired_on', 'granularity' => 'day']]]));
    }

    public function test_the_time_range_field_is_kept_because_only_the_dataset_knows_its_default(): void
    {
        $query = $this->normalized([...self::BASE, 'time_range' => ['field' => 'acquired_on', 'range' => ' @today ']]);

        $this->assertEquals(new TimeRange('@today', 'acquired_on'), $query->timeRange);
    }

    public function test_gap_filling_without_a_time_bucket_means_nothing_and_is_turned_off(): void
    {
        $bucketed = [['field' => 'acquired_on', 'granularity' => 'month']];

        $this->assertFalse($this->normalized([...self::BASE, 'fill_gaps' => true])->fillGaps);
        $this->assertFalse($this->normalized([...self::BASE, 'dimensions' => ['acquired_on'], 'fill_gaps' => true])->fillGaps);
        $this->assertTrue($this->normalized([...self::BASE, 'dimensions' => $bucketed])->fillGaps);
        $this->assertTrue($this->normalized([...self::BASE, 'dimensions' => $bucketed, 'fill_gaps' => true])->fillGaps);
        $this->assertFalse($this->normalized([...self::BASE, 'dimensions' => $bucketed, 'fill_gaps' => false])->fillGaps);

        $this->assertSame($this->hash(self::BASE), $this->hash([...self::BASE, 'fill_gaps' => true]));
        $this->assertSame($this->hash([...self::BASE, 'dimensions' => $bucketed]), $this->hash([...self::BASE, 'dimensions' => $bucketed, 'fill_gaps' => true]));
    }

    public function test_normalizing_twice_changes_nothing(): void
    {
        $once = $this->normalized([
            ...self::BASE,
            'dimensions' => [['field' => 'acquired_on', 'granularity' => 'week']],
            'filters' => ['b' => ['y', 'x', 'x'], 'a' => ' z '],
            'time_range' => ['range' => ' @last_7_days '],
        ]);

        $this->assertEquals($once, (new QueryNormalizer)->normalize($once));
    }

    public function test_it_only_rewrites_the_form_and_never_adds_or_drops_a_requested_part(): void
    {
        $query = $this->normalized([
            'dataset' => 'modul.dataset-contoh',
            'dimensions' => ['group_id', ['field' => 'acquired_on', 'granularity' => 'month']],
            'measures' => ['count', 'value'],
            'time_range' => ['field' => 'acquired_on', 'range' => '@this_year'],
            'sort' => [['key' => 'count', 'direction' => 'asc']],
            'limit' => 7,
            'totals' => true,
        ]);

        $this->assertEquals([new Dimension('group_id'), new Dimension('acquired_on', TimeGranularity::Month)], $query->dimensions);
        $this->assertSame(['count', 'value'], $query->measures);
        $this->assertSame([['key' => 'count', 'direction' => 'asc']], $query->sort);
        $this->assertSame(7, $query->limit);
        $this->assertTrue($query->totals);
        $this->assertTrue($query->fillGaps);
    }
}
