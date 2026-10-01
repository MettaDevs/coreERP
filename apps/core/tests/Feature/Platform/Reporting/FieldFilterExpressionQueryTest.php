<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Reporting;

use App\Support\Modules\Contracts\FieldFilterExpression;
use App\Support\Modules\Contracts\FieldType;
use App\Support\Modules\Contracts\FilterField;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Filter tambahan laporan (K-30) terhadap PostgreSQL sungguhan: baris mana yang lolos. SQL-nya sendiri
 * diperiksa di `Tests\Unit\Platform\Reporting\FieldFilterExpressionTest`; yang dibuktikan di sini perilaku yang
 * hanya terlihat di database — NULL, ILIKE, escape `%`/`_`/`\`, binding angka dan tanggal, serta hari
 * pengguna pada kolom UTC.
 *
 * Tabelnya tabel temporer di dalam transaksi test, jadi tidak ada migration yang perlu berjalan dan tidak
 * ada yang tertinggal sesudahnya.
 */
final class FieldFilterExpressionQueryTest extends TestCase
{
    use DatabaseTransactions;

    private const JAKARTA = 'Asia/Jakarta';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('create temporary table filter_contoh (
            id integer primary key,
            nama text null,
            nilai numeric(18, 2) null,
            tanggal date null,
            dibuat_pada timestamp(0) without time zone null,
            aktif boolean null,
            status text null
        )');

        // Waktu disimpan UTC. Komentar di kanan adalah jam dinding Jakarta (UTC+7).
        DB::table('filter_contoh')->insert([
            ['id' => 1, 'nama' => 'Laptop Dell', 'nilai' => '1500000.50', 'tanggal' => '2026-10-01', 'dibuat_pada' => '2026-09-30 17:00:00', 'aktif' => true, 'status' => 'aktif'], // 1 Okt 00.00
            ['id' => 2, 'nama' => 'laptop asus', 'nilai' => '200', 'tanggal' => '2026-09-30', 'dibuat_pada' => '2026-09-30 16:59:59', 'aktif' => false, 'status' => 'rusak'], // 30 Sep 23.59.59
            ['id' => 3, 'nama' => 'Diskon 50%', 'nilai' => '-5', 'tanggal' => null, 'dibuat_pada' => '2026-10-01 16:59:59', 'aktif' => null, 'status' => 'aktif'], // 1 Okt 23.59.59
            ['id' => 4, 'nama' => 'Diskon 50 persen', 'nilai' => null, 'tanggal' => '2026-10-02', 'dibuat_pada' => '2026-10-01 17:00:00', 'aktif' => true, 'status' => null], // 2 Okt 00.00
            ['id' => 5, 'nama' => '', 'nilai' => '0', 'tanggal' => '2026-01-01', 'dibuat_pada' => null, 'aktif' => false, 'status' => 'aktif'],
            ['id' => 6, 'nama' => null, 'nilai' => '1000', 'tanggal' => '2026-12-31', 'dibuat_pada' => '2026-06-15 12:00:00', 'aktif' => true, 'status' => 'hilang'],
            ['id' => 7, 'nama' => 'kode_a', 'nilai' => '10.25', 'tanggal' => '2026-10-01', 'dibuat_pada' => '2026-10-05 03:00:00', 'aktif' => true, 'status' => 'aktif'],
            ['id' => 8, 'nama' => 'kodeXa', 'nilai' => '10.5', 'tanggal' => '2026-10-01', 'dibuat_pada' => '2026-10-05 03:00:00', 'aktif' => true, 'status' => 'aktif'],
            ['id' => 9, 'nama' => 'a\\b', 'nilai' => '11', 'tanggal' => '2026-10-01', 'dibuat_pada' => '2026-10-05 03:00:00', 'aktif' => true, 'status' => 'aktif'],
            ['id' => 10, 'nama' => 'ab', 'nilai' => '12', 'tanggal' => '2026-10-01', 'dibuat_pada' => '2026-10-05 03:00:00', 'aktif' => true, 'status' => 'aktif'],
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return iterable<string, array{FieldType, string, string|array<array-key, mixed>, list<int>}> */
    public static function filters(): iterable
    {
        yield 'teks peka huruf besar' => [FieldType::Text, 'nama', 'Laptop*', [1]];
        yield 'teks dengan at tidak peka huruf besar' => [FieldType::Text, 'nama', '@laptop*', [1, 2]];
        yield 'at tanpa wildcard sama persis' => [FieldType::Text, 'nama', '@LAPTOP ASUS', [2]];
        yield 'persen pengguna literal' => [FieldType::Text, 'nama', '*50%*', [3]];
        yield 'garis bawah pengguna literal' => [FieldType::Text, 'nama', 'kode_*', [7]];
        yield 'tanda tanya wildcard satu karakter' => [FieldType::Text, 'nama', 'kode?a', [7, 8]];
        yield 'backslash pengguna literal' => [FieldType::Text, 'nama', 'a\\*', [9]];
        yield 'backslash sama persis' => [FieldType::Text, 'nama', 'a\\b', [9]];
        yield 'kosong mencakup NULL' => [FieldType::Text, 'nama', "''", [5, 6]];
        yield 'tidak kosong' => [FieldType::Text, 'nama', "<>''", [1, 2, 3, 4, 7, 8, 9, 10]];
        yield 'tidak sama meloloskan NULL' => [FieldType::Text, 'nama', '<>Laptop Dell', [2, 3, 4, 5, 6, 7, 8, 9, 10]];
        yield 'tidak cocok pola meloloskan NULL' => [FieldType::Text, 'nama', '<>@laptop*&<>Diskon*&<>kode*', [5, 6, 9, 10]];
        yield 'atau' => [FieldType::Text, 'nama', 'Laptop Dell|ab', [1, 10]];
        yield 'petik literal' => [FieldType::Text, 'nama', "'Diskon 50%'", [3]];

        yield 'angka koma desimal' => [FieldType::Number, 'nilai', '1500000,50', [1]];
        yield 'angka dengan pemisah ribuan' => [FieldType::Number, 'nilai', '1.500.000,50', [1]];
        yield 'rentang angka negatif' => [FieldType::Number, 'nilai', '-5..10,25', [3, 5, 7]];
        yield 'angka kosong' => [FieldType::Number, 'nilai', "''", [4]];
        yield 'angka tidak sama meloloskan NULL' => [FieldType::Number, 'nilai', '<>200&<>1000', [1, 3, 4, 5, 7, 8, 9, 10]];

        yield 'tanggal' => [FieldType::Date, 'tanggal', '01/10/2026', [1, 7, 8, 9, 10]];
        yield 'tanggal rentang' => [FieldType::Date, 'tanggal', '2026-09-30..02-10-2026', [1, 2, 4, 7, 8, 9, 10]];
        yield 'tanggal kosong' => [FieldType::Date, 'tanggal', "''", [3]];
        yield 'tanggal tidak sama meloloskan NULL' => [FieldType::Date, 'tanggal', '<>01/10/2026&<>2026-12-31', [2, 3, 4, 5]];

        yield 'hari penuh di Jakarta' => [FieldType::DateTime, 'dibuat_pada', '01/10/2026', [1, 3]];
        yield 'sesudah hari itu' => [FieldType::DateTime, 'dibuat_pada', '>01/10/2026', [4, 7, 8, 9, 10]];
        yield 'sampai akhir hari itu' => [FieldType::DateTime, 'dibuat_pada', '<=30/09/2026', [2, 6]];
        yield 'di luar hari itu meloloskan NULL' => [FieldType::DateTime, 'dibuat_pada', '<>01/10/2026', [2, 4, 5, 6, 7, 8, 9, 10]];
        yield 'rentang hari' => [FieldType::DateTime, 'dibuat_pada', '30/09/2026..01/10/2026', [1, 2, 3]];

        yield 'tidak' => [FieldType::Boolean, 'aktif', ['0'], [2, 5]];
        yield 'ya atau tidak tanpa NULL' => [FieldType::Boolean, 'aktif', ['1', '0'], [1, 2, 4, 5, 6, 7, 8, 9, 10]];
        yield 'pilihan' => [FieldType::Option, 'status', ['rusak', 'hilang'], [2, 6]];
        yield 'rujukan' => [FieldType::Reference, 'id', ['3', '9', '99'], [3, 9]];
    }

    /**
     * @param  string|array<array-key, mixed>  $value
     * @param  list<int>  $expected
     */
    #[DataProvider('filters')]
    public function test_filter_returns_the_matching_rows(FieldType $type, string $column, string|array $value, array $expected): void
    {
        $this->assertSame($expected, $this->ids($type, $column, $value));
    }

    public function test_today_on_a_utc_column_is_the_users_day(): void
    {
        // 30 September 18.00 UTC sudah 1 Oktober 01.00 di Jakarta.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 18:00:00', 'UTC'));

        $this->assertSame([1, 3], $this->ids(FieldType::DateTime, 'dibuat_pada', 't'));
        $this->assertSame([1, 2], $this->ids(FieldType::DateTime, 'dibuat_pada', 't', 'UTC'));
    }

    public function test_or_inside_the_filter_stays_within_other_conditions(): void
    {
        $query = DB::table('filter_contoh')->where('id', '>', 1);

        FieldFilterExpression::apply($query, $this->field(FieldType::Text, 'nama'), '@laptop*|Diskon*', self::JAKARTA);

        $this->assertSame([2, 3, 4], $this->pluck($query));
    }

    /**
     * @param  string|array<array-key, mixed>  $value
     * @return list<int>
     */
    private function ids(FieldType $type, string $column, string|array $value, string $timezone = self::JAKARTA): array
    {
        $query = DB::table('filter_contoh');

        FieldFilterExpression::apply($query, $this->field($type, $column), $value, $timezone);

        return $this->pluck($query);
    }

    /** @return list<int> */
    private function pluck(Builder $query): array
    {
        return array_values(array_map('intval', $query->orderBy('id')->pluck('id')->all()));
    }

    private function field(FieldType $type, string $column): FilterField
    {
        return new FilterField($column, 'Kolom', $type, 'filter_contoh.'.$column, [
            'aktif' => 'Aktif', 'rusak' => 'Rusak', 'hilang' => 'Hilang',
        ]);
    }
}
