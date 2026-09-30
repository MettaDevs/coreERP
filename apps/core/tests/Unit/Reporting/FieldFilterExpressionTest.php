<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Support\Modules\Contracts\FieldFilterExpression;
use App\Support\Modules\Contracts\FieldType;
use App\Support\Modules\Contracts\FilterField;
use App\Support\Modules\Contracts\InvalidFilterExpression;
use Carbon\CarbonImmutable;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pengurai filter tambahan laporan (K-30), tanpa database: yang diperiksa SQL dan binding yang
 * dihasilkan. Koneksi PostgreSQL-nya tidak pernah tersambung; ia hanya membawa grammar. Hasil baris
 * sungguhan, termasuk NULL, ILIKE, escape wildcard, dan zona waktu, diuji di
 * `Tests\Feature\Reporting\FieldFilterExpressionQueryTest`.
 */
class FieldFilterExpressionTest extends TestCase
{
    private const JAKARTA = 'Asia/Jakarta';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return iterable<string, array{FilterField, string|array<array-key, mixed>, string, list<mixed>}> */
    public static function compiled(): iterable
    {
        $text = self::field(FieldType::Text, 'aset.nama', 'Nama');
        $number = self::field(FieldType::Number, 'aset.nilai', 'Nilai perolehan');
        $date = self::field(FieldType::Date, 'aset.tanggal', 'Tanggal perolehan');
        $dateTime = self::field(FieldType::DateTime, 'aset.dibuat_pada', 'Dibuat pada');

        yield 'teks polos sama persis' => [$text, 'Laptop', '"aset"."nama" = ?', ['Laptop']];
        yield 'teks dengan = eksplisit' => [$text, '=Laptop', '"aset"."nama" = ?', ['Laptop']];
        yield 'spasi di tengah tetap nilai' => [$text, '  PT Maju  ', '"aset"."nama" = ?', ['PT Maju']];
        yield 'atau' => [$text, 'A | B', '("aset"."nama" = ?) or ("aset"."nama" = ?)', ['A', 'B']];
        yield 'dan dengan wildcard' => [$text, 'A*&*B', '"aset"."nama"::text like ? and "aset"."nama"::text like ?', ['A%', '%B']];
        yield 'dan mengikat lebih kuat dari atau' => [
            $text, 'A*&*B|C',
            '("aset"."nama"::text like ? and "aset"."nama"::text like ?) or ("aset"."nama" = ?)',
            ['A%', '%B', 'C'],
        ];
        yield 'tanda tanya satu karakter' => [$text, 'A?C', '"aset"."nama"::text like ?', ['A_C']];
        yield 'persen, garis bawah, dan backslash pengguna di-escape' => [$text, '50%_a\\b*', '"aset"."nama"::text like ?', ['50\\%\\_a\\\\b%']];
        yield 'at berarti tidak peka huruf besar' => [$text, '@lap*', '"aset"."nama"::text ilike ?', ['lap%']];
        yield 'at tanpa wildcard tetap sama persis' => [$text, '@Laptop_1', '"aset"."nama"::text ilike ?', ['Laptop\\_1']];
        yield 'tidak sama meloloskan NULL' => [$text, '<>Laptop', '("aset"."nama" <> ? or "aset"."nama" is null)', ['Laptop']];
        yield 'tidak cocok pola meloloskan NULL' => [$text, '<>A*', '("aset"."nama"::text not like ? or "aset"."nama" is null)', ['A%']];
        yield 'tidak cocok pola tanpa huruf besar' => [$text, '<>@a*', '("aset"."nama"::text not ilike ? or "aset"."nama" is null)', ['a%']];
        yield 'kosong mencakup NULL' => [$text, "''", '("aset"."nama" = ? or "aset"."nama" is null)', ['']];
        yield 'tidak kosong' => [$text, "<>''", '"aset"."nama" <> ?', ['']];
        yield 'petik dibaca apa adanya' => [$text, "'A|B*&..C'", '"aset"."nama" = ?', ['A|B*&..C']];
        yield 'dua petik di dalam petik adalah satu petik' => [$text, "'O''Brien'", '"aset"."nama" = ?', ["O'Brien"]];
        yield 'petik dan wildcard bercampur' => [$text, "'*'*", '"aset"."nama"::text like ?', ['*%']];
        yield 'rentang teks' => [$text, 'A..M', '"aset"."nama" >= ? and "aset"."nama" <= ?', ['A', 'M']];
        yield 'rentang teks terbuka di awal' => [$text, '..M', '"aset"."nama" <= ?', ['M']];
        yield 'rentang teks terbuka di akhir' => [$text, 'A..', '"aset"."nama" >= ?', ['A']];
        yield 'lebih besar sama dengan' => [$text, '>=B', '"aset"."nama" >= ?', ['B']];

        yield 'angka' => [$number, '1000', '"aset"."nilai" = ?', ['1000']];
        yield 'angka koma desimal negatif' => [$number, '-12,5', '"aset"."nilai" = ?', ['-12.5']];
        yield 'angka titik desimal' => [$number, '12.75', '"aset"."nilai" = ?', ['12.75']];
        yield 'rentang angka' => [$number, '100..200', '"aset"."nilai" >= ? and "aset"."nilai" <= ?', ['100', '200']];
        yield 'rentang angka negatif' => [$number, '-10..-1', '"aset"."nilai" >= ? and "aset"."nilai" <= ?', ['-10', '-1']];
        yield 'angka dan' => [$number, '>0&<10', '"aset"."nilai" > ? and "aset"."nilai" < ?', ['0', '10']];
        yield 'angka tidak sama meloloskan NULL' => [$number, '<>5', '("aset"."nilai" <> ? or "aset"."nilai" is null)', ['5']];
        yield 'angka kosong' => [$number, "''", '"aset"."nilai" is null', []];
        yield 'angka tidak kosong' => [$number, "<>''", '"aset"."nilai" is not null', []];

        yield 'tanggal ISO' => [$date, '2026-10-01', '"aset"."tanggal" = ?', ['2026-10-01']];
        yield 'tanggal garis miring' => [$date, '01/10/2026', '"aset"."tanggal" = ?', ['2026-10-01']];
        yield 'tanggal tanda hubung satu digit' => [$date, '1-9-2026', '"aset"."tanggal" = ?', ['2026-09-01']];
        yield 'rentang tanggal' => [$date, '01/01/2026..31/12/2026', '"aset"."tanggal" >= ? and "aset"."tanggal" <= ?', ['2026-01-01', '2026-12-31']];
        yield 'tanggal tidak sama meloloskan NULL' => [$date, '<>2026-10-01', '("aset"."tanggal" <> ? or "aset"."tanggal" is null)', ['2026-10-01']];
        yield 'tanggal kosong' => [$date, "''", '"aset"."tanggal" is null', []];

        // 1 Oktober di Jakarta (UTC+7) adalah 30 September 17.00 UTC sampai 1 Oktober 17.00 UTC.
        yield 'tanggal-jam satu hari penuh' => [$dateTime, '01/10/2026', '"aset"."dibuat_pada" >= ? and "aset"."dibuat_pada" < ?', ['2026-09-30 17:00:00', '2026-10-01 17:00:00']];
        yield 'tanggal-jam lebih besar' => [$dateTime, '>01/10/2026', '"aset"."dibuat_pada" >= ?', ['2026-10-01 17:00:00']];
        yield 'tanggal-jam lebih besar sama dengan' => [$dateTime, '>=01/10/2026', '"aset"."dibuat_pada" >= ?', ['2026-09-30 17:00:00']];
        yield 'tanggal-jam lebih kecil' => [$dateTime, '<01/10/2026', '"aset"."dibuat_pada" < ?', ['2026-09-30 17:00:00']];
        yield 'tanggal-jam lebih kecil sama dengan' => [$dateTime, '<=01/10/2026', '"aset"."dibuat_pada" < ?', ['2026-10-01 17:00:00']];
        yield 'tanggal-jam rentang' => [$dateTime, '01/10/2026..02/10/2026', '"aset"."dibuat_pada" >= ? and "aset"."dibuat_pada" < ?', ['2026-09-30 17:00:00', '2026-10-02 17:00:00']];
        yield 'tanggal-jam tidak sama' => [
            $dateTime, '<>01/10/2026',
            '("aset"."dibuat_pada" < ? or "aset"."dibuat_pada" >= ? or "aset"."dibuat_pada" is null)',
            ['2026-09-30 17:00:00', '2026-10-01 17:00:00'],
        ];
        yield 'tanggal-jam kosong' => [$dateTime, "''", '"aset"."dibuat_pada" is null', []];

        $boolean = self::field(FieldType::Boolean, 'aset.aktif', 'Aktif');
        $option = self::field(FieldType::Option, 'aset.status', 'Status', ['aktif' => 'Aktif', 'rusak' => 'Rusak']);
        $reference = self::field(FieldType::Reference, 'aset.pic_id', 'Penanggung jawab');

        yield 'ya' => [$boolean, ['1'], '"aset"."aktif" = ?', [true]];
        yield 'tidak dari bool' => [$boolean, [false], '"aset"."aktif" = ?', [false]];
        yield 'ya atau tidak' => [$boolean, ['true', '0'], '"aset"."aktif" in (?, ?)', [true, false]];
        yield 'pilihan' => [$option, ['aktif', 'rusak', 'aktif'], '"aset"."status" in (?, ?)', ['aktif', 'rusak']];
        yield 'pilihan dari string' => [$option, 'rusak', '"aset"."status" in (?)', ['rusak']];
        yield 'rujukan' => [$reference, ['01J9ZX3K4M5N6P7Q8R9S0T1V2W', '42'], '"aset"."pic_id" in (?, ?)', ['01J9ZX3K4M5N6P7Q8R9S0T1V2W', '42']];
    }

    /**
     * @param  string|array<array-key, mixed>  $value
     * @param  list<mixed>  $bindings
     */
    #[DataProvider('compiled')]
    public function test_filter_compiles_to_one_wrapped_clause(FilterField $field, string|array $value, string $where, array $bindings): void
    {
        $query = self::query();

        FieldFilterExpression::apply($query, $field, $value, self::JAKARTA);

        $this->assertSame('select * from "aset" where ('.$where.')', $query->toSql());
        $this->assertSame($bindings, $query->getBindings());
        $this->assertCount(1, $query->wheres);
    }

    public function test_or_inside_the_filter_does_not_leak_into_other_conditions(): void
    {
        $query = self::query()->where('aset.tenant_id', '=', 't1');

        FieldFilterExpression::apply($query, self::field(FieldType::Text, 'aset.nama', 'Nama'), 'A|B', self::JAKARTA);

        $this->assertSame(
            'select * from "aset" where "aset"."tenant_id" = ? and (("aset"."nama" = ?) or ("aset"."nama" = ?))',
            $query->toSql(),
        );
        $this->assertSame(['t1', 'A', 'B'], $query->getBindings());
    }

    /** @return iterable<string, array{FieldType, string|array<array-key, mixed>}> */
    public static function emptyValues(): iterable
    {
        yield 'teks kosong' => [FieldType::Text, ''];
        yield 'teks spasi' => [FieldType::Number, '   '];
        yield 'daftar kosong' => [FieldType::Option, []];
        yield 'daftar berisi kosong' => [FieldType::Reference, ['', '  ']];
        yield 'string kosong pada pemilih' => [FieldType::Boolean, ''];
    }

    /** @param  string|array<array-key, mixed>  $value */
    #[DataProvider('emptyValues')]
    public function test_empty_value_adds_nothing(FieldType $type, string|array $value): void
    {
        $query = self::query();

        FieldFilterExpression::apply($query, self::field($type, 'aset.kolom', 'Kolom'), $value, self::JAKARTA);

        $this->assertSame('select * from "aset"', $query->toSql());
    }

    public function test_today_follows_the_user_timezone(): void
    {
        // 30 September 18.00 UTC sudah 1 Oktober 01.00 di Jakarta.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 18:00:00', 'UTC'));

        $date = self::query();
        FieldFilterExpression::apply($date, self::field(FieldType::Date, 'aset.tanggal', 'Tanggal'), 't', self::JAKARTA);
        $this->assertSame(['2026-10-01'], $date->getBindings());

        $utc = self::query();
        FieldFilterExpression::apply($utc, self::field(FieldType::Date, 'aset.tanggal', 'Tanggal'), 'T', 'UTC');
        $this->assertSame(['2026-09-30'], $utc->getBindings());

        $dateTime = self::query();
        FieldFilterExpression::apply($dateTime, self::field(FieldType::DateTime, 'aset.dibuat_pada', 'Dibuat'), '..t', self::JAKARTA);
        $this->assertSame(['2026-10-01 17:00:00'], $dateTime->getBindings());
    }

    /** @return iterable<string, array{FieldType, string|array<array-key, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'bukan angka' => [FieldType::Number, 'abc', 'Filter "Kolom": "abc" bukan angka. Contoh: 1000000, 1.000.000, atau 1.000.000,50.'];
        yield 'kelompok ribuan salah' => [FieldType::Number, '1.00.000', 'Filter "Kolom": "1.00.000" bukan angka. Contoh: 1000000, 1.000.000, atau 1.000.000,50.'];
        yield 'batas rentang bukan angka' => [FieldType::Number, '1..x', 'Filter "Kolom": "x" bukan angka. Contoh: 1000000, 1.000.000, atau 1.000.000,50.'];
        yield 'wildcard pada angka' => [FieldType::Number, '5*', 'Filter "Kolom": "5*": tanda * dan ? hanya bisa dipakai pada kolom teks.'];
        yield 'rentang tanpa batas' => [FieldType::Text, '..', 'Filter "Kolom": ".." belum diberi batas awal atau akhir.'];
        yield 'dua rentang' => [FieldType::Number, '1..2..3', 'Filter "Kolom": "1..2..3" hanya boleh berisi satu "..".'];
        yield 'operator tanpa nilai' => [FieldType::Text, '>', 'Filter "Kolom": ">" belum diberi nilai.'];
        yield 'operator di dalam rentang' => [FieldType::Number, '>1..5', 'Filter "Kolom": ">1..5" tidak bisa memakai <, >, atau = bersama "..".'];
        yield 'petik tidak ditutup' => [FieldType::Text, "A|'abc", 'Filter "Kolom": tanda petik di "\'abc" belum ditutup.'];
        yield 'bagian kosong' => [FieldType::Text, 'A||B', 'Filter "Kolom": "A||B" punya bagian kosong di sekitar tanda | atau &.'];
        yield 'bagian kosong di akhir' => [FieldType::Text, 'A&', 'Filter "Kolom": "A&" punya bagian kosong di sekitar tanda | atau &.'];
        yield 'wildcard dengan lebih besar' => [FieldType::Text, '>A*', 'Filter "Kolom": ">A*": tanda *, ?, dan @ hanya bisa dipakai tanpa operator atau dengan <>.'];
        yield 'at dengan lebih besar' => [FieldType::Text, '>@a', 'Filter "Kolom": ">@a": tanda *, ?, dan @ hanya bisa dipakai tanpa operator atau dengan <>.'];
        yield 'wildcard di rentang' => [FieldType::Text, 'A*..B', 'Filter "Kolom": "A*..B": tanda *, ?, dan @ tidak bisa dipakai bersama "..".'];
        yield 'at tanpa nilai' => [FieldType::Text, '@', 'Filter "Kolom": "@" belum diberi nilai.'];
        yield 'kosong dengan lebih besar' => [FieldType::Number, ">''", 'Filter "Kolom": ">\'\'": kosong (\'\') hanya bisa dipakai sendiri atau dengan <>.'];
        yield 'kosong di rentang' => [FieldType::Date, "''..t", 'Filter "Kolom": "\'\'..t": kosong (\'\') hanya bisa dipakai sendiri atau dengan <>.'];
        yield 'tanggal mustahil' => [FieldType::Date, '31/02/2026', 'Filter "Kolom": "31/02/2026" bukan tanggal yang ada di kalender.'];
        yield 'bentuk tanggal asing' => [FieldType::DateTime, '2026/10/01', 'Filter "Kolom": "2026/10/01" bukan tanggal. Tulis seperti 31/12/2026, 2026-12-31, atau t untuk hari ini.'];
        yield 'pemisah tanggal bercampur' => [FieldType::Date, '01/10-2026', 'Filter "Kolom": "01/10-2026" bukan tanggal. Tulis seperti 31/12/2026, 2026-12-31, atau t untuk hari ini.'];
        yield 'terlalu panjang' => [FieldType::Text, str_repeat('a', 251), 'Filter "Kolom": isiannya terlalu panjang, maksimal 250 karakter.'];
        yield 'terlalu banyak syarat' => [FieldType::Number, implode('|', range(1, 51)), 'Filter "Kolom": syaratnya terlalu banyak, maksimal 50.'];
        yield 'ekspresi diberi daftar' => [FieldType::Text, ['A'], 'Filter "Kolom": isiannya harus berupa teks filter, bukan daftar pilihan.'];
        yield 'ya tidak asing' => [FieldType::Boolean, ['ya'], 'Filter "Kolom": "ya" bukan pilihan ya atau tidak.'];
        yield 'pilihan asing' => [FieldType::Option, ['hilang'], 'Filter "Kolom": "hilang" tidak ada di daftar pilihan.'];
        yield 'rujukan terlalu panjang' => [FieldType::Reference, [str_repeat('x', 65)], 'Filter "Kolom": "xxxxxxxxxxxxxxxxxxxx…" terlalu panjang untuk sebuah pilihan.'];
        yield 'rujukan terlalu banyak' => [FieldType::Reference, array_map('strval', range(1, 101)), 'Filter "Kolom": pilihannya terlalu banyak, maksimal 100.'];
        yield 'daftar bersarang' => [FieldType::Reference, [['1']], 'Filter "Kolom": isiannya harus berupa daftar pilihan.'];
    }

    /** @param  string|array<array-key, mixed>  $value */
    #[DataProvider('invalid')]
    public function test_invalid_filter_is_rejected_without_touching_the_query(FieldType $type, string|array $value, string $message): void
    {
        $query = self::query();
        $field = self::field($type, 'aset.kolom', 'Kolom', ['aktif' => 'Aktif']);

        try {
            FieldFilterExpression::apply($query, $field, $value, self::JAKARTA);
            $this->fail('Filter seharusnya ditolak.');
        } catch (InvalidFilterExpression $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame([], $query->wheres);
    }

    public function test_limits_are_inclusive(): void
    {
        $text = self::query();
        FieldFilterExpression::apply($text, self::field(FieldType::Text, 'aset.nama', 'Nama'), str_repeat('a', 250), self::JAKARTA);
        $this->assertCount(1, $text->getBindings());

        $number = self::query();
        FieldFilterExpression::apply($number, self::field(FieldType::Number, 'aset.nilai', 'Nilai'), implode('|', range(1, 50)), self::JAKARTA);
        $this->assertCount(50, $number->getBindings());
    }

    /** @param  array<string, string>  $options */
    private static function field(FieldType $type, string $column, string $caption, array $options = []): FilterField
    {
        return new FilterField('kolom', $caption, $type, $column, $options);
    }

    private static function query(): Builder
    {
        return (new PostgresConnection(static fn (): PDO => throw new LogicException('Test ini tidak tersambung ke database.')))->table('aset');
    }
}
