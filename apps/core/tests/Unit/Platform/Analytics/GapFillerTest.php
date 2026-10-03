<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledMeasure;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\Dimension;
use App\Platform\Analytics\Query\GapFiller;
use App\Platform\Analytics\Query\ResultColumn;
use App\Platform\Analytics\Query\TimeGranularity;
use App\Platform\Analytics\Query\TimeRange;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

/**
 * Pengisi celah deret waktu (area 3) tanpa database: titik yang diisi untuk setiap ukuran ember, rentang
 * dari token, kombinasi dimensi lain, dan keadaan ketika celah sengaja tidak diisi.
 */
class GapFillerTest extends TestCase
{
    public function test_weeks_start_on_monday_and_each_other_combination_gets_its_own_points(): void
    {
        $rows = [
            ['tanggal' => '2026-09-28', 'unit' => 'A', 'unit__label' => 'Unit A', 'count' => 2, 'rata' => '5.00'],
            ['tanggal' => '2026-10-12', 'unit' => 'B', 'unit__label' => 'Unit B', 'count' => 1, 'rata' => '7.00'],
        ];

        $filled = GapFiller::fill($this->dataset(), $this->query(TimeGranularity::Week, ['unit']), $this->columns(TimeGranularity::Week, true), $rows, $this->now(), 5000);

        $this->assertSame([
            ['2026-09-28', 'A', 2], ['2026-09-28', 'B', 0],
            ['2026-10-05', 'A', 0], ['2026-10-05', 'B', 0],
            ['2026-10-12', 'B', 1], ['2026-10-12', 'A', 0],
        ], array_map(static fn (array $row): array => [$row['tanggal'], $row['unit'], $row['count']], $filled));
        // Label dimensi lain ikut disalin; rata-rata titik kosong tetap kosong.
        $this->assertSame(['tanggal' => '2026-10-05', 'unit' => 'B', 'unit__label' => 'Unit B', 'count' => 0, 'rata' => null], $filled[3]);
    }

    public function test_a_token_range_on_the_bucketed_field_fills_the_whole_range(): void
    {
        $query = $this->query(TimeGranularity::Quarter, range: new TimeRange('@this_year'));

        $filled = GapFiller::fill($this->dataset(), $query, $this->columns(TimeGranularity::Quarter), [['tanggal' => '2026-04-01', 'count' => 3, 'rata' => '1.00']], $this->now(), 5000);
        $this->assertSame([['2026-01-01', 0], ['2026-04-01', 3], ['2026-07-01', 0], ['2026-10-01', 0]], array_map(static fn (array $row): array => [$row['tanggal'], $row['count']], $filled));

        // Tanpa baris sama sekali dan tanpa dimensi lain, deretnya tetap utuh dengan nol.
        $this->assertCount(4, GapFiller::fill($this->dataset(), $query, $this->columns(TimeGranularity::Quarter), [], $this->now(), 5000));

        // Rentang pada field waktu lain tidak menentukan titik ember ini.
        $other = $this->query(TimeGranularity::Quarter, range: new TimeRange('@this_year', 'dicatat_pada'));
        $this->assertSame([], GapFiller::fill($this->dataset(), $other, $this->columns(TimeGranularity::Quarter), [], $this->now(), 5000));
    }

    public function test_gaps_are_left_alone_when_filling_would_mislead_or_swell(): void
    {
        $rows = [['tanggal' => '2026-01-01', 'count' => 1, 'rata' => '1.00'], ['tanggal' => '2026-03-01', 'count' => 2, 'rata' => '2.00']];
        $columns = $this->columns(TimeGranularity::Month);

        // Dimatikan.
        $this->assertSame($rows, GapFiller::fill($this->dataset(), $this->query(TimeGranularity::Month, fill: false), $columns, $rows, $this->now(), 5000));
        // Diurutkan menurut nilai, bukan menurut periode.
        $this->assertSame($rows, GapFiller::fill($this->dataset(), $this->query(TimeGranularity::Month, sort: [['key' => 'count', 'direction' => 'desc']]), $columns, $rows, $this->now(), 5000));
        // Sesudah diisi melebihi batas baris query.
        $this->assertSame($rows, GapFiller::fill($this->dataset(), $this->query(TimeGranularity::Month), $columns, $rows, $this->now(), 2));
        // Lebih dari 1000 titik.
        $days = [['tanggal' => '2020-01-01', 'count' => 1, 'rata' => null], ['tanggal' => '2026-01-01', 'count' => 1, 'rata' => null]];
        $this->assertSame($days, GapFiller::fill($this->dataset(), $this->query(TimeGranularity::Day), $this->columns(TimeGranularity::Day), $days, $this->now(), 100_000));

        // Periode diurutkan turun: titiknya juga turun.
        $this->assertSame(['2026-03-01', '2026-02-01', '2026-01-01'], array_column(GapFiller::fill($this->dataset(), $this->query(TimeGranularity::Month, sort: [['key' => 'tanggal', 'direction' => 'desc']]), $columns, array_reverse($rows), $this->now(), 5000), 'tanggal'));
    }

    public function test_exactly_one_thousand_points_are_still_filled(): void
    {
        $rows = [['tanggal' => '2024-01-01', 'count' => 1, 'rata' => null], ['tanggal' => '2026-09-26', 'count' => 1, 'rata' => null]];

        $this->assertCount(GapFiller::MAX_POINTS, GapFiller::fill($this->dataset(), $this->query(TimeGranularity::Day), $this->columns(TimeGranularity::Day), $rows, $this->now(), 5000));
    }

    /**
     * @param  list<string>  $others
     * @param  list<array{key: string, direction: 'asc'|'desc'}>  $sort
     */
    private function query(TimeGranularity $granularity, array $others = [], ?TimeRange $range = null, array $sort = [], bool $fill = true): AnalyticsQuery
    {
        return new AnalyticsQuery(
            'modul.penjualan',
            [new Dimension('tanggal', $granularity), ...array_map(static fn (string $field): Dimension => new Dimension($field), $others)],
            ['count', 'rata'],
            [],
            $range,
            $sort,
            null,
            false,
            $fill,
        );
    }

    /** @return list<ResultColumn> */
    private function columns(TimeGranularity $granularity, bool $unit = false): array
    {
        return [
            new ResultColumn('d0', 'tanggal', ResultColumn::DIMENSION, 'Tanggal', 'period', granularity: $granularity->value),
            ...($unit ? [new ResultColumn('d1', 'unit', ResultColumn::DIMENSION, 'Unit', 'reference', labelKey: 'unit__label')] : []),
            new ResultColumn('m0', 'count', ResultColumn::MEASURE, 'Jumlah', 'number', format: 'number', aggregate: Aggregate::Count),
            new ResultColumn('m1', 'rata', ResultColumn::MEASURE, 'Rata-rata', 'number', format: 'number', aggregate: Aggregate::Average),
        ];
    }

    private function dataset(): CompiledDataset
    {
        return new CompiledDataset(
            code: 'modul.penjualan',
            caption: 'Penjualan',
            moduleId: 'modul',
            version: 1,
            model: Model::class,
            table: 'penjualan',
            permission: 'modul.penjualan.read',
            policy: null,
            fields: [
                'tanggal' => new FilterField('tanggal', 'Tanggal', FieldType::Date, 'penjualan.tanggal'),
                'dicatat_pada' => new FilterField('dicatat_pada', 'Dicatat pada', FieldType::DateTime, 'penjualan.dicatat_pada'),
                'unit' => new FilterField('unit', 'Unit', FieldType::Reference, 'penjualan.unit'),
            ],
            measures: ['count' => new CompiledMeasure('count', 'Jumlah', Aggregate::Count, null, MeasureFormat::Number, null, null, [])],
            times: ['tanggal', 'dicatat_pada'],
            defaultTime: 'tanggal',
        );
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-15 09:00:00', 'Asia/Makassar');
    }
}
