<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledMeasure;
use App\Platform\Analytics\Query\Formula\Formula;
use App\Platform\Analytics\Query\Formula\FormulaExpression;
use App\Platform\Analytics\Query\Formula\InvalidFormula;
use App\Platform\Analytics\Query\Formula\Lexer;
use App\Platform\Analytics\Query\Formula\Node\CallNode;
use App\Platform\Analytics\Query\Formula\Node\NumberNode;
use App\Platform\Analytics\Query\Formula\Parser;
use App\Platform\Analytics\Query\Formula\Token;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Grammar;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bahasa rumus (area 13, KA-19; `docs/todo/analitik/mesin-query.md` bagian *Bahasa rumus*) tanpa database:
 * pembaca menolak semua yang bukan bahasa rumus di posisinya, sebelum ada SQL, dan pemancar SQL hanya menulis
 * potongan dari daftar tetapnya, dengan angka sebagai binding. Bukti bahwa SQL-nya memulangkan angka yang benar
 * ada di `tests/Feature/Platform/Analytics/FormulaAndComparisonTest.php`.
 */
class FormulaTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: int, 2: string}> */
    public static function unreadable(): iterable
    {
        // Percobaan menyisipkan SQL berhenti di kurung tutup yang tidak berpasangan, sebelum kata apa pun dibaca.
        yield 'sisipan SQL' => ['[count]); drop table x; --', 8, '`)` tanpa pasangan'];
        yield 'sisipan SQL di dalam kurung siku' => ["[count'] ; drop table x", 1, 'nama nilai'];
        yield 'komentar SQL' => ['[count] -- x', 12, 'fungsi `x` tidak dikenal'];
        yield 'blok komentar' => ['[count] /* x */', 10, 'tidak dapat ditaruh di sini'];
        yield 'petik' => ["'1' + [count]", 1, 'tanda `\'` tidak dikenal'];
        yield 'fungsi tidak dikenal' => ['HAPUS([count])', 1, 'fungsi `HAPUS` tidak dikenal'];
        yield 'fungsi SQL' => ['pg_sleep(10)', 1, 'fungsi `pg_sleep` tidak dikenal'];
        yield 'nama tanpa kurung' => ['BAGI + 1', 6, 'sesudah BAGI harus ada `(`'];
        yield 'kurung siku tanpa pasangan' => ['[count', 1, '`[` tanpa pasangan'];
        yield 'kurung siku tutup sendirian' => ['count]', 6, '`]` tanpa pasangan'];
        yield 'kurung tutup siku sesudah angka' => ['1]', 2, '`]` tanpa pasangan'];
        yield 'kurung buka tanpa pasangan' => ['BAGI([count]; 2', 5, '`(` tanpa pasangan'];
        yield 'kurung bersarang tanpa pasangan' => ['(([count])', 1, '`(` tanpa pasangan'];
        yield 'berakhir di tengah' => ['[count] +', 10, 'berakhir sebelum selesai'];
        yield 'dua nilai tanpa tanda' => ['[count] [nilai]', 9, 'kurang tanda hitung sebelum `[nilai]`'];
        yield 'koma sebagai pemisah isian' => ['MIN([count], 2)', 12, 'pisahkan isian fungsi dengan titik koma'];
        yield 'titik koma di luar fungsi' => ['[count]; 1', 8, '`;` hanya dipakai'];
        yield 'perbandingan di luar JIKA' => ['[count] > 1', 9, 'hanya dapat dipakai di isian pertama JIKA'];
        yield 'JIKA tanpa perbandingan' => ['JIKA([count]; 1; 0)', 13, 'isian pertama JIKA harus perbandingan'];
        yield 'isian BAGI kurang' => ['BAGI([count])', 1, 'BAGI butuh 2 atau 3 isian'];
        yield 'isian BAGI lebih' => ['BAGI(1; 2; 3; 4)', 1, 'BAGI butuh 2 atau 3 isian'];
        yield 'isian ABS lebih' => ['ABS(1; 2)', 1, 'ABS butuh 1 isian'];
        yield 'isian MIN kurang' => ['MIN(1)', 1, 'MIN butuh sedikitnya 2 isian'];
        yield 'isian kosong' => ['BAGI(; 2)', 6, 'isian kosong sebelum `;`'];
        yield 'isian terakhir kosong' => ['BAGI(1; )', 9, 'isian kosong sebelum `)`'];
        yield 'kurung kosong' => ['()', 2, 'isian kosong sebelum `)`'];
        yield 'angka bertitik koma salah' => ['1,2,3', 1, 'angka `1,2,3` tidak dapat dibaca'];
        yield 'angka ribuan salah' => ['1.00.000', 1, 'angka `1.00.000` tidak dapat dibaca'];
        yield 'huruf asing' => ['[count] × 2', 9, 'tanda `×` tidak dikenal'];
    }

    #[DataProvider('unreadable')]
    public function test_anything_outside_the_language_is_rejected_at_its_position(string $formula, int $position, string $detail): void
    {
        try {
            Parser::parse($formula);
            $this->fail("Rumus `{$formula}` seharusnya ditolak.");
        } catch (InvalidFormula $e) {
            $this->assertSame($position, $e->position, $e->getMessage());
            $this->assertStringStartsWith("Rumus tidak dapat dibaca di karakter {$position}: ", $e->getMessage());
            $this->assertStringContainsString($detail, $e->getMessage());
        }
    }

    public function test_length_depth_and_emptiness_are_bounded(): void
    {
        Parser::parse(str_repeat('(', 19).'1'.str_repeat(')', 19));
        Parser::parse(str_repeat('-', 19).'1');
        Parser::parse('1'.str_repeat(' ', Parser::MAX_LENGTH - 1));

        foreach ([
            [str_repeat('(', 20).'1'.str_repeat(')', 20), 'paling banyak 20 tingkat'],
            [str_repeat('-', 20).'1', 'paling banyak 20 tingkat'],
            [str_repeat('ABS(', 20).'1'.str_repeat(')', 20), 'paling banyak 20 tingkat'],
            ['1'.str_repeat(' ', Parser::MAX_LENGTH), 'Maksimal 500 karakter'],
            ['   ', 'Isi rumusnya'],
            ['', 'Isi rumusnya'],
        ] as [$formula, $message]) {
            try {
                Parser::parse($formula);
                $this->fail('Rumus `'.mb_substr($formula, 0, 30).'…` seharusnya ditolak.');
            } catch (InvalidFormula $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_numbers_are_written_the_indonesian_way(): void
    {
        foreach ([['1.000,5', '1000.5'], ['1,5', '1.5'], ['1.5', '1.5'], ['1.000.000', '1000000'], ['1000', '1000'], ['0,25', '0.25']] as [$text, $value]) {
            $node = Parser::parse($text);
            $this->assertInstanceOf(NumberNode::class, $node);
            $this->assertSame($value, $node->value, "`{$text}`");
        }

        // Koma desimal di dalam fungsi tetap desimal, karena isian dipisah titik koma.
        $call = Parser::parse('MAKS(1.000,5; 2)');
        $this->assertInstanceOf(CallNode::class, $call);
        $this->assertCount(2, $call->arguments);
    }

    public function test_tokens_carry_their_character_positions(): void
    {
        $tokens = Lexer::tokenize('bagi([nilai] ;1,5)>=');

        $this->assertSame(
            [[Token::NAME, 'BAGI', 1], [Token::OPEN, '(', 5], [Token::MEASURE, 'nilai', 6], [Token::SEPARATOR, ';', 14], [Token::NUMBER, '1.5', 15], [Token::CLOSE, ')', 18], [Token::COMPARISON, '>=', 19], [Token::END, '', 21]],
            array_map(static fn (Token $token): array => [$token->type, $token->value, $token->position], $tokens),
        );
    }

    public function test_function_names_ignore_case_and_references_keep_their_order(): void
    {
        $formula = new Formula('r', 'jika([count] > 0; bagi([nilai]; [count]); [nilai])', Parser::parse('jika([count] > 0; bagi([nilai]; [count]); [nilai])'));

        $this->assertSame([['key' => 'count', 'position' => 6], ['key' => 'nilai', 'position' => 24], ['key' => 'count', 'position' => 33], ['key' => 'nilai', 'position' => 43]], $formula->references());
        $this->assertSame(['count', 'nilai'], $formula->measures());
        $this->assertSame('r', $formula->caption());
        $this->assertSame(MeasureFormat::Number, $formula->format());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: list<string|int|bool>}> */
    public static function sql(): iterable
    {
        $count = 'cast((count(*)) as numeric)';
        $sales = 'cast((coalesce(sum("t"."nilai"), 0)) as numeric)';
        $issued = 'cast((count(*) filter (where "t"."status" in (?))) as numeric)';

        yield 'angka menjadi binding' => ['[nilai] * 1.000,5', "({$sales} * cast(? as numeric))", ['1000.5']];
        yield 'urutan hitung' => ['1 + 2 * [count]', "(cast(? as numeric) + (cast(? as numeric) * {$count}))", ['1', '2']];
        yield 'kurung' => ['(1 + 2) * [count]', "((cast(? as numeric) + cast(? as numeric)) * {$count})", ['1', '2']];
        yield 'minus' => ['-[count] - -1', "((-{$count}) - (-cast(? as numeric)))", ['1']];
        yield 'bagi nol menjadi kosong' => ['[nilai] / [count]', "({$sales} / nullif({$count}, 0))", []];
        yield 'BAGI nol menjadi nol' => ['BAGI([issued]; [count]) * 100', "(case when {$count} = 0 then 0 else {$issued} / {$count} end * cast(? as numeric))", ['terbit', '100']];
        yield 'BAGI dengan cadangan hanya untuk pembagi nol' => ['BAGI([nilai]; [count]; -1)', "case when {$count} = 0 then (-cast(? as numeric)) else {$sales} / {$count} end", ['1']];
        yield 'BAGI mengulang binding sesuai urutan placeholder' => ['BAGI(2; 3; 4)', 'case when cast(? as numeric) = 0 then cast(? as numeric) else cast(? as numeric) / cast(? as numeric) end', ['3', '4', '2', '3']];
        yield 'JIKA' => ['JIKA([issued] >= 2; [nilai]; 0)', "case when ({$issued} >= cast(? as numeric)) then {$sales} else cast(? as numeric) end", ['terbit', '2', '0']];
        yield 'ABS dan BULAT' => ['BULAT(ABS([nilai]); 2)', "round(abs({$sales}), cast(cast(? as numeric) as integer))", ['2']];
        yield 'MIN dan MAKS' => ['MIN([count]; MAKS(1; 2; 3))', "least({$count}, greatest(cast(? as numeric), cast(? as numeric), cast(? as numeric)))", ['1', '2', '3']];
    }

    /**
     * @param  list<string|int|bool>  $bindings
     */
    #[DataProvider('sql')]
    public function test_sql_is_composed_from_the_tree_with_numbers_as_bindings(string $formula, string $sql, array $bindings): void
    {
        $expression = new FormulaExpression(Parser::parse($formula), $this->dataset());

        $this->assertSame($sql, $expression->getValue($this->grammar()));
        $this->assertSame($bindings, $expression->bindings());
        $this->assertSame(substr_count($sql, '?'), count($bindings), 'Jumlah placeholder dan binding harus sama.');
    }

    public function test_renaming_a_measure_rewrites_only_its_reference(): void
    {
        $this->assertSame('BAGI([terjual];  [count]) * [terjual_lama]', Formula::renameMeasures('BAGI([lama];  [count]) * [terjual_lama]', ['lama' => 'terjual']));
        $this->assertSame('[x] +', Formula::renameMeasures('[x] +', []));
        // Teks yang tidak terbaca dibiarkan; pembacaan ulang query yang menolaknya, dengan posisinya.
        $this->assertSame('[lama', Formula::renameMeasures('[lama', ['lama' => 'baru']));
    }

    private function dataset(): CompiledDataset
    {
        return new CompiledDataset(
            code: 'modul.dataset-contoh',
            caption: 'Dataset contoh',
            moduleId: 'modul',
            version: 1,
            model: Model::class,
            table: 't',
            permission: 'modul.contoh.read',
            policy: null,
            fields: [],
            measures: [
                'count' => new CompiledMeasure('count', 'Jumlah', Aggregate::Count, null, MeasureFormat::Number, null, null, []),
                'nilai' => new CompiledMeasure('nilai', 'Nilai', Aggregate::Sum, 'nilai', MeasureFormat::Money, 'currency_code', null, []),
                'issued' => new CompiledMeasure('issued', 'Terbit', Aggregate::Count, null, MeasureFormat::Number, null, null, ['status' => ['terbit']]),
            ],
            times: [],
            defaultTime: null,
        );
    }

    private function grammar(): Grammar
    {
        return DB::connection()->getQueryGrammar();
    }
}
