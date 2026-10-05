<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\CompareMode;
use App\Platform\Analytics\Query\Dimension;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\TimeGranularity;
use App\Platform\Analytics\Query\TimeRange;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pembaca JSON query analitik (area 2), tanpa database: bentuk yang sah menjadi `AnalyticsQuery`, dan
 * setiap bentuk yang salah ditolak 422 `analytics.invalid_query` dengan path bagian yang salah.
 */
class QueryParserTest extends TestCase
{
    public function test_reads_every_key_of_the_documented_query(): void
    {
        $query = (new QueryParser)->parse([
            'dataset' => 'modul.dataset-contoh',
            'dimensions' => [
                'group_id',
                ['field' => 'acquired_on', 'granularity' => 'month'],
            ],
            'measures' => ['count', 'value'],
            'filters' => [
                'lifecycle_state' => ['received', 'decommissioned'],
                'name' => '@*laptop*',
                'value' => '>=5.000.000',
            ],
            'time_range' => ['field' => 'acquired_on', 'range' => '@last_12_months'],
            'sort' => [['key' => 'value', 'direction' => 'desc']],
            'limit' => 10,
            'totals' => true,
            'fill_gaps' => true,
        ]);

        $this->assertEquals(new AnalyticsQuery(
            dataset: 'modul.dataset-contoh',
            dimensions: [new Dimension('group_id'), new Dimension('acquired_on', TimeGranularity::Month)],
            measures: ['count', 'value'],
            filters: [
                'lifecycle_state' => ['received', 'decommissioned'],
                'name' => '@*laptop*',
                'value' => '>=5.000.000',
            ],
            timeRange: new TimeRange('@last_12_months', 'acquired_on'),
            sort: [['key' => 'value', 'direction' => 'desc']],
            limit: 10,
            totals: true,
            fillGaps: true,
        ), $query);
    }

    public function test_the_smallest_query_takes_the_defaults(): void
    {
        $query = (new QueryParser)->parse(['dataset' => 'modul.dataset-contoh', 'measures' => ['count']]);

        $this->assertEquals(new AnalyticsQuery('modul.dataset-contoh', [], ['count'], [], null, [], null, false, false), $query);
    }

    public function test_a_column_written_as_text_or_as_an_object_without_granularity_is_the_same_dimension(): void
    {
        $parser = new QueryParser;
        $base = ['dataset' => 'modul.dataset-contoh', 'measures' => ['count']];

        $this->assertEquals(
            $parser->parse([...$base, 'dimensions' => ['group_id']]),
            $parser->parse([...$base, 'dimensions' => [['field' => 'group_id']]]),
        );
        $this->assertEquals(
            $parser->parse([...$base, 'dimensions' => ['group_id']]),
            $parser->parse([...$base, 'dimensions' => [['field' => 'group_id', 'granularity' => null]]]),
        );
    }

    public function test_optional_keys_set_to_null_read_as_absent(): void
    {
        $parser = new QueryParser;
        $base = ['dataset' => 'modul.dataset-contoh', 'measures' => ['count']];

        $this->assertEquals($parser->parse($base), $parser->parse([
            ...$base,
            'dimensions' => null, 'filters' => null, 'time_range' => null, 'sort' => null,
            'limit' => null, 'totals' => null, 'fill_gaps' => null,
        ]));
    }

    public function test_an_empty_json_object_for_filters_means_no_filter(): void
    {
        // `{}` dari JSON terbaca PHP sebagai daftar kosong.
        $query = (new QueryParser)->parse(['dataset' => 'modul.dataset-contoh', 'measures' => ['count'], 'filters' => []]);

        $this->assertSame([], $query->filters);
    }

    public function test_gap_filling_defaults_to_on_only_when_there_is_a_time_bucket(): void
    {
        $parser = new QueryParser;
        $base = ['dataset' => 'modul.dataset-contoh', 'measures' => ['count']];
        $bucketed = [['field' => 'acquired_on', 'granularity' => 'week']];

        $this->assertTrue($parser->parse([...$base, 'dimensions' => $bucketed])->fillGaps);
        $this->assertFalse($parser->parse([...$base, 'dimensions' => ['acquired_on']])->fillGaps);
        $this->assertFalse($parser->parse($base)->fillGaps);
        // Yang ditulis pengguna menang atas bawaan.
        $this->assertFalse($parser->parse([...$base, 'dimensions' => $bucketed, 'fill_gaps' => false])->fillGaps);
        $this->assertTrue($parser->parse([...$base, 'dimensions' => ['acquired_on'], 'fill_gaps' => true])->fillGaps);
    }

    public function test_every_granularity_is_read(): void
    {
        foreach (TimeGranularity::cases() as $case) {
            $query = (new QueryParser)->parse([
                'dataset' => 'modul.dataset-contoh', 'measures' => ['count'],
                'dimensions' => [['field' => 'acquired_on', 'granularity' => $case->value]],
            ]);

            $this->assertSame($case, $query->dimensions[0]->granularity);
        }
    }

    public function test_an_empty_filter_object_a_blank_string_and_an_empty_list_are_read_as_written(): void
    {
        // Membuang isian kosong adalah tugas normalizer; parser membaca apa adanya.
        $query = (new QueryParser)->parse([
            'dataset' => 'modul.dataset-contoh', 'measures' => ['count'],
            'filters' => ['name' => '  ', 'lifecycle_state' => []],
        ]);

        $this->assertSame(['name' => '  ', 'lifecycle_state' => []], $query->filters);
    }

    public function test_a_filter_cleared_on_screen_arrives_as_null_and_means_no_filter(): void
    {
        // `ConvertEmptyStringsToNull` mengubah teks kosong di badan JSON menjadi null sebelum sampai ke sini.
        $query = (new QueryParser)->parse([
            'dataset' => 'modul.dataset-contoh', 'measures' => ['count'],
            'filters' => ['name' => null, 'lifecycle_state' => ['received', null]],
        ]);

        $this->assertSame(['name' => '', 'lifecycle_state' => ['received', '']], $query->filters);
    }

    /**
     * Setiap bentuk yang salah, dengan path yang ditunjuk galatnya.
     *
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function malformed(): iterable
    {
        $base = ['dataset' => 'modul.dataset-contoh', 'measures' => ['count']];

        yield 'kunci tingkat atas tidak dikenal' => [[...$base, 'foo' => 1], 'foo'];
        yield 'compare tidak dikenal' => [[...$base, 'compare' => 'last_month'], 'compare'];
        yield 'compare bukan teks' => [[...$base, 'compare' => true], 'compare'];
        yield 'formulas bukan daftar' => [[...$base, 'formulas' => ['rasio' => '[count]']], 'formulas'];
        yield 'rumus bukan objek' => [[...$base, 'formulas' => ['[count] * 2']], 'formulas.0'];
        yield 'rumus berbagian asing' => [[...$base, 'formulas' => [['key' => 'r', 'expression' => '[count]', 'sql' => 'x']]], 'formulas.0.sql'];
        yield 'kunci rumus berhuruf besar' => [[...$base, 'formulas' => [['key' => 'Rasio', 'expression' => '[count]']]], 'formulas.0.key'];
        yield 'kunci rumus bergaris bawah ganda' => [[...$base, 'formulas' => [['key' => 'count__previous', 'expression' => '[count]']]], 'formulas.0.key'];
        yield 'kunci rumus ganda' => [[...$base, 'formulas' => [['key' => 'r', 'expression' => '[count]'], ['key' => 'r', 'expression' => '1']]], 'formulas.1.key'];
        yield 'format rumus tidak dikenal' => [[...$base, 'formulas' => [['key' => 'r', 'expression' => '[count]', 'format' => 'rupiah']]], 'formulas.0.format'];
        yield 'nama tampilan rumus kosong' => [[...$base, 'formulas' => [['key' => 'r', 'expression' => '[count]', 'caption' => ' ']]], 'formulas.0.caption'];
        yield 'percent_of_total bukan daftar' => [[...$base, 'percent_of_total' => 'count'], 'percent_of_total'];
        yield 'percent_of_total berisi angka' => [[...$base, 'percent_of_total' => [1]], 'percent_of_total.0'];
        yield 'badan berupa daftar' => [['count'], '0'];

        yield 'dataset hilang' => [['measures' => ['count']], 'dataset'];
        yield 'dataset kosong' => [[...$base, 'dataset' => '  '], 'dataset'];
        yield 'dataset bukan teks' => [[...$base, 'dataset' => 5], 'dataset'];
        yield 'dataset terlalu panjang' => [[...$base, 'dataset' => str_repeat('a', 121)], 'dataset'];

        yield 'dimensions bukan daftar' => [[...$base, 'dimensions' => ['group_id' => 'x']], 'dimensions'];
        yield 'dimensions berupa teks' => [[...$base, 'dimensions' => 'group_id'], 'dimensions'];
        yield 'dimensi berupa angka' => [[...$base, 'dimensions' => ['group_id', 5]], 'dimensions.1'];
        yield 'dimensi kosong' => [[...$base, 'dimensions' => ['group_id', '']], 'dimensions.1'];
        yield 'dimensi berupa daftar' => [[...$base, 'dimensions' => ['group_id', ['acquired_on', 'month']]], 'dimensions.1'];
        yield 'dimensi objek tanpa field' => [[...$base, 'dimensions' => ['group_id', ['granularity' => 'month']]], 'dimensions.1.field'];
        yield 'dimensi objek kosong' => [[...$base, 'dimensions' => ['group_id', []]], 'dimensions.1.field'];
        yield 'dimensi objek field bukan teks' => [[...$base, 'dimensions' => [['field' => 7]]], 'dimensions.0.field'];
        yield 'granularity tidak dikenal' => [[...$base, 'dimensions' => ['group_id', ['field' => 'acquired_on', 'granularity' => 'hour']]], 'dimensions.1.granularity'];
        yield 'granularity huruf besar' => [[...$base, 'dimensions' => [['field' => 'acquired_on', 'granularity' => 'Month']]], 'dimensions.0.granularity'];
        yield 'granularity bukan teks' => [[...$base, 'dimensions' => [['field' => 'acquired_on', 'granularity' => 5]]], 'dimensions.0.granularity'];
        yield 'dimensi objek dengan kunci lain' => [[...$base, 'dimensions' => [['field' => 'acquired_on', 'alias' => 'x']]], 'dimensions.0.alias'];

        yield 'measures hilang' => [['dataset' => 'modul.dataset-contoh'], 'measures'];
        yield 'measures kosong' => [[...$base, 'measures' => []], 'measures'];
        yield 'measures bukan daftar' => [[...$base, 'measures' => 'count'], 'measures'];
        yield 'measure bukan teks' => [[...$base, 'measures' => ['count', 3]], 'measures.1'];
        yield 'measure terlalu panjang' => [[...$base, 'measures' => ['count', str_repeat('m', 121)]], 'measures.1'];

        yield 'filters berupa daftar' => [[...$base, 'filters' => ['a', 'b']], 'filters'];
        yield 'filters berupa teks' => [[...$base, 'filters' => 'a'], 'filters'];
        yield 'kunci saringan angka' => [[...$base, 'filters' => ['7' => 'x']], 'filters'];
        yield 'isian saringan angka' => [[...$base, 'filters' => ['name' => 5]], 'filters.name'];
        yield 'isian saringan boolean' => [[...$base, 'filters' => ['name' => true]], 'filters.name'];
        yield 'isian saringan objek' => [[...$base, 'filters' => ['name' => ['a' => 'b']]], 'filters.name'];
        yield 'daftar saringan berisi angka' => [[...$base, 'filters' => ['name' => ['a', 2]]], 'filters.name'];

        yield 'time_range berupa teks' => [[...$base, 'time_range' => '@this_month'], 'time_range'];
        yield 'time_range berupa daftar' => [[...$base, 'time_range' => ['@this_month']], 'time_range'];
        yield 'time_range tanpa range' => [[...$base, 'time_range' => ['field' => 'acquired_on']], 'time_range.range'];
        yield 'time_range objek kosong' => [[...$base, 'time_range' => []], 'time_range.range'];
        yield 'range kosong' => [[...$base, 'time_range' => ['range' => '   ']], 'time_range.range'];
        yield 'range bukan teks' => [[...$base, 'time_range' => ['range' => 5]], 'time_range.range'];
        yield 'range terlalu panjang' => [[...$base, 'time_range' => ['range' => str_repeat('1', 251)]], 'time_range.range'];
        yield 'field rentang bukan teks' => [[...$base, 'time_range' => ['field' => 5, 'range' => '@today']], 'time_range.field'];
        yield 'field rentang kosong' => [[...$base, 'time_range' => ['field' => '', 'range' => '@today']], 'time_range.field'];
        yield 'time_range dengan kunci lain' => [[...$base, 'time_range' => ['range' => '@today', 'from' => 'x']], 'time_range.from'];

        yield 'sort bukan daftar' => [[...$base, 'sort' => ['key' => 'count', 'direction' => 'asc']], 'sort'];
        yield 'sort berupa teks' => [[...$base, 'sort' => 'count'], 'sort'];
        yield 'urutan berupa teks' => [[...$base, 'sort' => ['count']], 'sort.0'];
        yield 'urutan tanpa arah' => [[...$base, 'sort' => [['key' => 'count']]], 'sort.0.direction'];
        yield 'arah huruf besar' => [[...$base, 'sort' => [['key' => 'count', 'direction' => 'ASC']]], 'sort.0.direction'];
        yield 'arah tidak dikenal' => [[...$base, 'sort' => [['key' => 'count', 'direction' => 'up']]], 'sort.0.direction'];
        yield 'urutan tanpa key' => [[...$base, 'sort' => [['key' => 'count', 'direction' => 'asc'], ['direction' => 'desc']]], 'sort.1.key'];
        yield 'urutan key bukan teks' => [[...$base, 'sort' => [['key' => 3, 'direction' => 'asc']]], 'sort.0.key'];
        yield 'urutan dengan kunci lain' => [[...$base, 'sort' => [['key' => 'count', 'direction' => 'asc', 'nulls' => 'first']]], 'sort.0.nulls'];

        yield 'limit nol' => [[...$base, 'limit' => 0], 'limit'];
        yield 'limit negatif' => [[...$base, 'limit' => -5], 'limit'];
        yield 'limit teks' => [[...$base, 'limit' => '10'], 'limit'];
        yield 'limit pecahan' => [[...$base, 'limit' => 1.5], 'limit'];

        yield 'totals teks' => [[...$base, 'totals' => 'ya'], 'totals'];
        yield 'totals angka' => [[...$base, 'totals' => 1], 'totals'];
        yield 'fill_gaps teks' => [[...$base, 'fill_gaps' => 'true'], 'fill_gaps'];
    }

    /** @param array<array-key, mixed> $input */
    #[DataProvider('malformed')]
    public function test_a_malformed_query_is_rejected_with_the_path_of_the_bad_part(array $input, string $field): void
    {
        try {
            (new QueryParser)->parse($input);
            $this->fail('Query yang salah bentuk seharusnya ditolak.');
        } catch (AnalyticsQueryException $e) {
            $this->assertSame('analytics.invalid_query', $e->errorCode);
            $this->assertSame(422, $e->status);
            $this->assertSame($field, $e->field);
            $this->assertNotSame('', $e->getMessage());
        }
    }

    /** Kunci area 13 dibaca, bukan ditolak: rumus beserta pohonnya, perbandingan, dan persen terhadap total. */
    public function test_the_phase_two_keys_are_read(): void
    {
        $query = (new QueryParser)->parse([
            'dataset' => 'modul.dataset-contoh',
            'measures' => ['count', 'rasio'],
            'formulas' => [['key' => 'rasio', 'caption' => ' Rasio ', 'expression' => 'BAGI([total]; [count])', 'format' => 'money']],
            'compare' => 'previous_year',
            'percent_of_total' => ['count'],
        ]);

        $this->assertSame(CompareMode::PreviousYear, $query->compare);
        $this->assertSame(['count'], $query->percentOfTotal);
        $this->assertCount(1, $query->formulas);
        $this->assertSame('rasio', $query->formulas[0]->key);
        $this->assertSame('Rasio', $query->formulas[0]->caption);
        $this->assertSame(MeasureFormat::Money, $query->formulas[0]->format);
        $this->assertSame(['total', 'count'], $query->formulas[0]->measures());
        $this->assertSame($query->formulas[0], $query->formula('rasio'));
        $this->assertNull($query->formula('count'));

        try {
            (new QueryParser)->parse(['dataset' => 'modul.dataset-contoh', 'measures' => ['r'], 'formulas' => [['key' => 'r', 'expression' => '[count] +']]]);
            $this->fail('Rumus yang terpotong seharusnya ditolak.');
        } catch (AnalyticsQueryException $e) {
            $this->assertSame('analytics.invalid_formula', $e->errorCode);
            $this->assertSame(10, $e->position);
            $this->assertSame(['error' => ['code' => 'analytics.invalid_formula', 'message' => $e->getMessage(), 'field' => 'formulas.0.expression', 'position' => 10]], $e->toArray());
        }

        try {
            (new QueryParser)->parse(['dataset' => 'modul.dataset-contoh', 'measures' => ['count'], 'foo' => 1]);
            $this->fail('Bagian foo seharusnya ditolak.');
        } catch (AnalyticsQueryException $e) {
            $this->assertStringContainsString('tidak dikenal', $e->getMessage());
        }
    }

    public function test_the_message_for_a_bad_granularity_lists_the_valid_ones(): void
    {
        try {
            (new QueryParser)->parse(['dataset' => 'modul.dataset-contoh', 'measures' => ['count'], 'dimensions' => [['field' => 'acquired_on', 'granularity' => 'hour']]]);
            $this->fail('Granularity hour seharusnya ditolak.');
        } catch (AnalyticsQueryException $e) {
            $this->assertStringContainsString('day, week, month, quarter, year', $e->getMessage());
        }
    }
}
