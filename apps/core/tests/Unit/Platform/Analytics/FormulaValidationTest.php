<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Cache\QueryCache;
use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledMeasure;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\FieldUseGate;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Query\TimeRange;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Platform\Analytics\Support\TestPrincipal;
use Tests\TestCase;

/**
 * Pemeriksaan area 13 terhadap dataset, tanpa database: rumus (kunci, rujukan measure, rumus di dalam rumus,
 * warisan mata uang dan satuan, format), persen terhadap total, perbandingan periode, dan gerbang data pribadi
 * yang juga melihat kolom yang dibaca measure di dalam rumus. Juga pembacaan ulang query widget yang memuat rumus.
 */
class FormulaValidationTest extends TestCase
{
    /** @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}> */
    public static function rejected(): iterable
    {
        $formula = static fn (string $expression, string $key = 'rumus', array $extra = []): array => ['measures' => ['count', $key], 'formulas' => [['key' => $key, 'expression' => $expression, ...$extra]]];

        yield 'measure tidak dikenal' => [$formula('BAGI([count]; [hilang])'), 'analytics.field_unknown', 'formulas.0.expression', 'karakter 15'];
        yield 'rumus memakai rumus' => [['measures' => ['a', 'b'], 'formulas' => [['key' => 'a', 'expression' => '[count] * 2'], ['key' => 'b', 'expression' => '[a] + 1']]], 'analytics.invalid_formula', 'formulas.1.expression', 'tidak dapat memakai rumus lain'];
        yield 'mata uang campur' => [$formula('[total] - [biaya]'), 'analytics.invalid_formula', 'formulas.0.expression', 'mata uang berbeda'];
        yield 'satuan campur' => [$formula('[berat] + [panjang]'), 'analytics.invalid_formula', 'formulas.0.expression', 'satuan berbeda'];
        yield 'kunci sama dengan kolom' => [$formula('[count]', 'name'), 'analytics.invalid_query', 'formulas.0.key', 'sudah dipakai'];
        yield 'kunci sama dengan measure' => [['measures' => ['count'], 'formulas' => [['key' => 'total', 'expression' => '[count]']]], 'analytics.invalid_query', 'formulas.0.key', 'sudah dipakai'];
        yield 'rumus tidak dipilih' => [['measures' => ['count'], 'formulas' => [['key' => 'rumus', 'expression' => '[count]']]], 'analytics.invalid_query', 'formulas.0.key', 'belum dipilih'];
        yield 'rumus dipilih dua kali' => [['measures' => ['rumus', 'rumus'], 'formulas' => [['key' => 'rumus', 'expression' => '[count]']]], 'analytics.invalid_query', 'measures.1', 'lebih dari sekali'];
        yield 'format uang tanpa nilai uang' => [$formula('[count] * 1.000', extra: ['format' => 'money']), 'analytics.invalid_query', 'formulas.0.format', 'butuh nilai uang'];
        yield 'format kuantitas tanpa satuan' => [$formula('[count]', extra: ['format' => 'quantity']), 'analytics.invalid_query', 'formulas.0.format', 'butuh nilai kuantitas'];
        yield 'terlalu banyak rumus' => [['measures' => ['a', 'b', 'c', 'd', 'e', 'f'], 'formulas' => array_map(static fn (string $key): array => ['key' => $key, 'expression' => '[count]'], ['a', 'b', 'c', 'd', 'e', 'f'])], 'analytics.limit_exceeded', 'formulas', 'Maksimal 5'];
        yield 'persen untuk nilai yang tidak dipilih' => [['measures' => ['count'], 'percent_of_total' => ['total']], 'analytics.invalid_query', 'percent_of_total.0', 'tidak ada di pilihan'];
        yield 'persen untuk pengelompok' => [['dimensions' => ['name'], 'percent_of_total' => ['name']], 'analytics.invalid_query', 'percent_of_total.0', 'tidak ada di pilihan'];
        yield 'rumus atas tanggal' => [$formula('[terakhir] + 1'), 'analytics.invalid_formula', 'formulas.0.expression', 'bukan angka'];
        yield 'persen atas tanggal' => [['measures' => ['terakhir'], 'percent_of_total' => ['terakhir']], 'analytics.invalid_query', 'percent_of_total.0', 'bukan angka'];
        yield 'perbandingan atas tanggal' => [['measures' => ['count', 'terakhir'], 'compare' => 'previous_year', 'time_range' => ['range' => '@this_month']], 'analytics.invalid_query', 'measures.1', 'bukan angka'];
        yield 'perbandingan tanpa rentang' => [['compare' => 'previous_period'], 'analytics.invalid_query', 'compare', 'butuh rentang waktu'];
        yield 'perbandingan rentang terbuka' => [['compare' => 'previous_year', 'time_range' => ['range' => '>=01/01/2026']], 'analytics.invalid_query', 'time_range.range', 'awal dan akhirnya'];
        yield 'perbandingan tanggal tanpa ember' => [['compare' => 'previous_year', 'time_range' => ['range' => '@this_month'], 'dimensions' => ['acquired_on']], 'analytics.invalid_query', 'dimensions.0', 'kelompokkan kolom tanggal'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('rejected')]
    public function test_a_query_the_dataset_cannot_answer_is_rejected(array $input, string $code, string $field, string $message): void
    {
        try {
            $this->validate($input);
            $this->fail('Query seharusnya ditolak: '.json_encode($input));
        } catch (AnalyticsQueryException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
            $this->assertSame($field, $e->field);
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_formula_errors_inside_the_text_point_at_the_character(): void
    {
        try {
            $this->validate(['measures' => ['r'], 'formulas' => [['key' => 'r', 'expression' => '[count] + [hilang]']]]);
            $this->fail('Measure yang tidak dikenal seharusnya ditolak.');
        } catch (AnalyticsQueryException $e) {
            $this->assertSame(11, $e->position);
            $this->assertSame(11, $e->toArray()['error']['position'] ?? null);
        }
    }

    public function test_accepted_shapes(): void
    {
        foreach ([
            // Uang dengan hitungan: satu mata uang, boleh. Measure yang sama dua kali juga satu mata uang.
            ['measures' => ['rata'], 'formulas' => [['key' => 'rata', 'expression' => 'BAGI([total]; [count])', 'format' => 'money']]],
            ['measures' => ['selisih'], 'formulas' => [['key' => 'selisih', 'expression' => '[total] - [total_lain]', 'format' => 'money']]],
            // Mata uang dan satuan sekaligus: dikelompokkan menurut keduanya, tidak tercampur.
            ['measures' => ['harga_per_kg'], 'formulas' => [['key' => 'harga_per_kg', 'expression' => '[total] / [berat]']]],
            ['measures' => ['count', 'total'], 'percent_of_total' => ['total', 'count']],
            // Terkecil dan terbesar atas angka tetap angka.
            ['measures' => ['tertinggi', 'r'], 'formulas' => [['key' => 'r', 'expression' => '[tertinggi] / 2']], 'percent_of_total' => ['tertinggi'], 'compare' => 'previous_year', 'time_range' => ['range' => '@this_month']],
            ['measures' => ['count'], 'compare' => 'previous_period', 'time_range' => ['range' => '01/01/2026..31/03/2026'], 'dimensions' => [['field' => 'acquired_on', 'granularity' => 'month'], 'name']],
            // Tahun fiskal dihitung rentangnya sesudah validasi, jadi di sini cukup dikenal.
            ['measures' => ['count'], 'compare' => 'previous_year', 'time_range' => ['range' => '@this_fiscal_year']],
            ['measures' => ['r'], 'formulas' => [['key' => 'r', 'expression' => '[count]']], 'sort' => [['key' => 'r', 'direction' => 'desc']], 'limit' => 3],
        ] as $input) {
            $this->validate($input);
        }
        $this->addToAssertionCount(1);
    }

    public function test_the_personal_data_gate_sees_the_columns_read_by_measures_inside_a_formula(): void
    {
        $gate = new class implements FieldUseGate
        {
            /** @var list<array<string, string>> */
            public array $calls = [];

            public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void
            {
                $this->calls[] = $uses;
            }
        };

        $this->validate(['measures' => ['r'], 'formulas' => [['key' => 'r', 'expression' => 'BAGI([total]; [named])']]], $gate);

        $this->assertSame([[
            'formulas.0.measures.total' => 'value',
            'formulas.0.measures.named.where.name' => 'name',
        ]], $gate->calls);
    }

    public function test_a_stored_widget_query_with_formulas_is_read_against_the_current_dataset(): void
    {
        $stored = [
            'dataset' => 'modul.dataset-contoh',
            'measures' => ['nilai_lama', 'rasio'],
            'formulas' => [['key' => 'rasio', 'expression' => 'BAGI([nilai_lama]; [count])']],
            'percent_of_total' => ['nilai_lama'],
            'compare' => 'previous_year',
        ];

        // Versi sekarang: `nilai_lama` sudah diganti `total`. Kunci rumus di `measures` bukan measure yang hilang.
        $read = StoredQuery::read($this->dataset(renamed: ['nilai_lama' => 'total']), $stored, 1);
        $this->assertSame([], $read['missing']);
        $this->assertSame('BAGI([total]; [count])', $read['query']['formulas'][0]['expression']);
        $this->assertSame(['total'], $read['query']['percent_of_total']);
        $this->assertSame(['total', 'rasio'], $read['query']['measures']);

        // Tanpa peta ganti nama, measure di dalam rumus yang tidak ada lagi dilaporkan di path rumusnya.
        $this->assertEqualsCanonicalizing(['formulas.0.expression' => 'nilai_lama', 'measures.0' => 'nilai_lama'], StoredQuery::read($this->dataset(), $stored, 2)['missing']);
    }

    public function test_stored_form_writes_the_area_thirteen_keys_only_when_used(): void
    {
        $query = $this->parsed(['measures' => ['count', 'r'], 'formulas' => [['key' => 'r', 'expression' => '[count] * 2', 'format' => 'number']], 'compare' => 'previous_period', 'time_range' => ['range' => '@this_month'], 'percent_of_total' => ['count']]);

        $compact = StoredQuery::compact($query);
        $this->assertSame([['key' => 'r', 'expression' => '[count] * 2', 'format' => 'number']], $compact['formulas']);
        $this->assertSame('previous_period', $compact['compare']);
        $this->assertSame(['count'], $compact['percent_of_total']);
        // Bentuk simpan dibaca lagi menjadi query yang sama.
        $this->assertEquals($query->normalized(), $this->parsed($compact)->normalized());

        $plain = StoredQuery::compact($this->parsed([]));
        foreach (['formulas', 'compare', 'percent_of_total'] as $key) {
            $this->assertArrayNotHasKey($key, $plain);
        }
    }

    /**
     * Kunci cache area 9 dihitung dari bentuk normal query; query yang berbeda rumus, perbandingan, persen terhadap
     * total, atau rentang tahun fiskal tidak boleh berbagi hasil. Urutan daftar rumus dan persen tidak mengubah
     * hasil, jadi tidak mengubah kunci.
     */
    public function test_the_cache_key_tells_apart_formulas_comparisons_shares_and_fiscal_ranges(): void
    {
        $cache = app(QueryCache::class);
        $principal = new TestPrincipal('tenant-uji');
        $key = fn (array $input): string => $cache->key($this->dataset(), $this->parsed($input), $principal);
        $base = ['measures' => ['count', 'a', 'b'], 'time_range' => ['range' => '@this_month'], 'formulas' => [['key' => 'a', 'expression' => '[count] * 2'], ['key' => 'b', 'expression' => '[total]']]];

        $keys = [
            'dasar' => $key($base),
            'tanpa rumus' => $key(['measures' => ['count'], 'time_range' => ['range' => '@this_month']]),
            'teks rumus' => $key([...$base, 'formulas' => [['key' => 'a', 'expression' => '[count] * 3'], ['key' => 'b', 'expression' => '[total]']]]),
            'nama rumus' => $key([...$base, 'formulas' => [['key' => 'a', 'expression' => '[count] * 2', 'caption' => 'Dua kali'], ['key' => 'b', 'expression' => '[total]']]]),
            'format rumus' => $key([...$base, 'formulas' => [['key' => 'a', 'expression' => '[count] * 2', 'format' => 'percent'], ['key' => 'b', 'expression' => '[total]']]]),
            'periode lalu' => $key([...$base, 'compare' => 'previous_period']),
            'tahun lalu' => $key([...$base, 'compare' => 'previous_year']),
            'persen' => $key([...$base, 'percent_of_total' => ['count']]),
        ];
        $this->assertSame(array_keys($keys), array_keys(array_unique($keys)), 'Dua query berbeda berbagi kunci cache.');

        $this->assertSame($keys['dasar'], $key([...$base, 'formulas' => array_reverse($base['formulas'])]));
        $this->assertSame($key([...$base, 'percent_of_total' => ['count', 'a']]), $key([...$base, 'percent_of_total' => ['a', 'count', 'a']]));

        $fiscal = $this->parsed(['time_range' => ['range' => '@this_fiscal_year']]);
        $july = $cache->key($this->dataset(), $fiscal->withTimeRange(new TimeRange('@this_fiscal_year', null, ['2026-07-01', '2027-06-30'])), $principal);
        $january = $cache->key($this->dataset(), $fiscal->withTimeRange(new TimeRange('@this_fiscal_year', null, ['2026-01-01', '2026-12-31'])), $principal);
        $this->assertNotSame($july, $january, 'Tahun fiskal dua perusahaan berbagi kunci cache.');
    }

    /** @param array<string, mixed> $input */
    private function validate(array $input, ?FieldUseGate $gate = null): void
    {
        $gate ??= new class implements FieldUseGate
        {
            public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void {}
        };

        (new QueryValidator($gate))->validate($this->dataset(), $this->parsed($input), $this->principal());
    }

    /** @param array<string, mixed> $input */
    private function parsed(array $input): AnalyticsQuery
    {
        return (new QueryNormalizer)->normalize((new QueryParser)->parse([...['dataset' => 'modul.dataset-contoh', 'measures' => ['count']], ...$input]));
    }

    /** @param array<string, string> $renamed */
    private function dataset(array $renamed = []): CompiledDataset
    {
        $fields = [];
        foreach ([
            ['name', 'Nama', FieldType::Text],
            ['value', 'Nilai', FieldType::Number],
            ['acquired_on', 'Tanggal perolehan', FieldType::Date],
        ] as [$key, $caption, $type]) {
            $fields[$key] = new FilterField($key, $caption, $type, 'contoh.'.$key);
        }

        return new CompiledDataset(
            code: 'modul.dataset-contoh',
            caption: 'Dataset contoh',
            moduleId: 'modul',
            version: 2,
            model: Model::class,
            table: 'contoh',
            permission: 'modul.contoh.read',
            policy: null,
            fields: $fields,
            measures: [
                'count' => new CompiledMeasure('count', 'Jumlah', Aggregate::Count, null, MeasureFormat::Number, null, null, []),
                'total' => new CompiledMeasure('total', 'Total nilai', Aggregate::Sum, 'value', MeasureFormat::Money, 'currency_code', null, []),
                'total_lain' => new CompiledMeasure('total_lain', 'Total lain', Aggregate::Sum, 'value', MeasureFormat::Money, 'contoh.currency_code', null, []),
                'biaya' => new CompiledMeasure('biaya', 'Biaya', Aggregate::Sum, 'cost', MeasureFormat::Money, 'cost_currency', null, []),
                'berat' => new CompiledMeasure('berat', 'Berat', Aggregate::Sum, 'weight', MeasureFormat::Quantity, null, 'weight_unit', []),
                'panjang' => new CompiledMeasure('panjang', 'Panjang', Aggregate::Sum, 'length', MeasureFormat::Quantity, null, 'length_unit', []),
                'named' => new CompiledMeasure('named', 'Bernama', Aggregate::Count, null, MeasureFormat::Number, null, null, ['name' => ['x']]),
                'terakhir' => new CompiledMeasure('terakhir', 'Perolehan terakhir', Aggregate::Maximum, 'acquired_on', MeasureFormat::Number, null, null, []),
                'tertinggi' => new CompiledMeasure('tertinggi', 'Nilai tertinggi', Aggregate::Maximum, 'value', MeasureFormat::Number, null, null, []),
            ],
            times: ['acquired_on'],
            defaultTime: 'acquired_on',
            renamed: $renamed,
            columnTypes: ['name' => 'varchar', 'value' => 'numeric', 'acquired_on' => 'date'],
            classifications: array_map(static fn (): DataClass => DataClass::CustomerContent, $fields),
        );
    }

    private function principal(): AnalyticsPrincipal
    {
        $principal = $this->createStub(AnalyticsPrincipal::class);
        $principal->method('rowLimit')->willReturn(5000);
        $principal->method('timezone')->willReturn('Asia/Makassar');
        $principal->method('now')->willReturnCallback(static fn (): CarbonImmutable => CarbonImmutable::now('Asia/Makassar'));

        return $principal;
    }
}
