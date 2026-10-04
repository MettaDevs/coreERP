<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Query\IsNullExpression;
use App\Platform\Analytics\Query\MeasureExpression;
use App\Platform\Analytics\Query\TimeBucketExpression;
use App\Platform\Analytics\Query\TimeGranularity;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use Illuminate\Database\Grammar;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SQL ekspresi compiler (area 3) tanpa menjalankan query: ember waktu per jenis kolom waktu dan zona
 * sebagai literal yang hanya boleh nama zona yang dikenal, measure dengan saringan tetap di dalam
 * `coalesce`, dan kunci kosong-di-akhir. Bukti bahwa SQL ini memulangkan angka yang benar ada di
 * `tests/Feature/Platform/Analytics/QueryCompilerTest.php`.
 */
class QueryExpressionsTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function timeColumns(): iterable
    {
        // Kolom date tidak dikonversi; timestamp berisi UTC dibaca sebagai UTC dulu; timestamptz langsung.
        yield 'date' => ['date', 'date_trunc(\'month\', "t"."tanggal"::timestamp)::date'];
        yield 'timestamp' => ['timestamp', 'date_trunc(\'month\', ("t"."tanggal" at time zone \'UTC\') at time zone \'Asia/Makassar\')::date'];
        yield 'timestamptz' => ['timestamptz', 'date_trunc(\'month\', "t"."tanggal" at time zone \'Asia/Makassar\')::date'];
    }

    #[DataProvider('timeColumns')]
    public function test_time_bucket_sql_depends_on_the_kind_of_time_column(string $type, string $sql): void
    {
        /** @var 'date'|'timestamp'|'timestamptz' $type */
        $this->assertSame($sql, (new TimeBucketExpression(TimeGranularity::Month, 't.tanggal', $type, 'Asia/Makassar'))->getValue($this->grammar()));
    }

    public function test_time_bucket_zone_is_a_known_zone_name_never_free_text(): void
    {
        foreach (['Asia/Jakarta\'; drop table x; --', 'WITA', '+08:00', ''] as $zone) {
            try {
                new TimeBucketExpression(TimeGranularity::Day, 't.tanggal', 'timestamptz', $zone);
                $this->fail("Zona `{$zone}` harus ditolak.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('bukan nama zona', $e->getMessage());
            }
        }
    }

    public function test_measure_filter_sits_inside_coalesce_with_its_values_as_bindings(): void
    {
        $sum = new MeasureExpression(Aggregate::Sum, 't.nilai', [['t.status', ['terbit', 'draf']], ['t.bawaan', [true]]]);

        // `coalesce(sum(…), 0) filter (…)` tidak sah: FILTER melekat pada panggilan agregatnya.
        $this->assertSame('coalesce(sum("t"."nilai") filter (where "t"."status" in (?, ?) and "t"."bawaan" in (?)), 0)', $sum->getValue($this->grammar()));
        $this->assertSame(['terbit', 'draf', true], $sum->bindings());
        $this->assertFalse($sum->nullable());

        $count = new MeasureExpression(Aggregate::Count, null, [['t.unit_id', [null]], ['t.status', ['terbit', null]]]);
        $this->assertSame('count(*) filter (where "t"."unit_id" is null and ("t"."status" in (?) or "t"."status" is null))', $count->getValue($this->grammar()));
        $this->assertSame(['terbit'], $count->bindings());

        foreach ([[Aggregate::CountDistinct, 'count(distinct "t"."x")', false], [Aggregate::Average, 'avg("t"."x")', true], [Aggregate::Minimum, 'min("t"."x")', true], [Aggregate::Maximum, 'max("t"."x")', true]] as [$aggregate, $sql, $nullable]) {
            $measure = new MeasureExpression($aggregate, 't.x');
            $this->assertSame($sql, $measure->getValue($this->grammar()));
            $this->assertSame($nullable, $measure->nullable());
            $this->assertSame([], $measure->bindings());
        }
    }

    public function test_empty_last_key_repeats_the_expression_it_sorts(): void
    {
        $this->assertSame('("t"."unit_id") is null', (new IsNullExpression('t.unit_id'))->getValue($this->grammar()));
        $this->assertSame('(avg("t"."x")) is null', (new IsNullExpression(new MeasureExpression(Aggregate::Average, 't.x')))->getValue($this->grammar()));
    }

    private function grammar(): Grammar
    {
        return DB::connection()->getQueryGrammar();
    }
}
