<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledMeasure;
use App\Platform\Analytics\Query\Formula\FormulaExpression;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DataPolicyScope;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\FieldFilterExpression;
use App\Platform\Modules\Contracts\InvalidFilterExpression;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Menyusun query builder Laravel dari query analitik. Tidak menjalankan apa pun; eksekusinya milik
 * {@see QueryExecutor}, supaya test dan alat operator dapat memeriksa SQL tanpa membaca data.
 *
 * Urutannya bukan selera; setiap langkah bergantung pada langkah sebelumnya:
 *
 * 1. Query dasar dari model dataset — `TenantScope` dan `SoftDeletes` ikut dari model. Tabel dasar tidak
 *    pernah diberi alias, karena scope tenant disisipkan dengan nama tabel sebenarnya.
 * 2. Join yang dibutuhkan saja ({@see JoinPlanner}): join data yang kolomnya disebut query, lalu join label
 *    dimensi rujukan. Setiap join membawa `tenant_id` tabel dasar.
 * 3. Jangkauan principal lewat {@see DataPolicyScope} — kebijakan data pada kolom yang dinyatakan dataset,
 *    lalu saringan terkunci principal — **sebelum** saringan pengguna. Saringan pengguna hanya dapat
 *    menyempitkan.
 * 4. Saringan pengguna dan rentang waktu lewat `FieldFilterExpression` (sintaks BC filter tambahan K-30),
 *    nilai lewat binding. Token rentang relatif menjadi `Y-m-d..Y-m-d` menurut zona principal
 *    ({@see RelativeRange}); tahun fiskal sudah dihitung rentangnya oleh {@see FiscalYearRange}.
 * 5. Dimensi dengan alias posisi `d0, d1, …`, dikelompokkan **menurut alias**: ekspresi berparameter yang
 *    diulang di `GROUP BY` menjadi parameter lain bagi PostgreSQL, dan pengelompokannya ditolak. Ember
 *    waktu lewat {@see TimeBucketExpression}; label rujukan module ikut dipilih dan dikelompokkan
 *    (`d0_label`).
 * 6. Kolom mata uang dan satuan measure sebagai dimensi tersirat `c0, …`, supaya uang tidak pernah
 *    dijumlah lintas mata uang (KA-22). Measure yang dirujuk rumus ikut menyumbang kolomnya: rumus mewarisi
 *    pengelompokan mata uang measure di dalamnya.
 * 7. Measure dengan alias `m0, m1, …`, lewat {@see MeasureExpression}, termasuk saringan tetapnya; rumus lewat
 *    {@see FormulaExpression} di atas ekspresi agregat yang sama, sehingga urutan dan top-N dapat memakainya.
 * 8. Urutan dan batas: urutan pilihan pengguna, atau bawaan — periode naik bila ada ember waktu, selain
 *    itu measure pertama turun — lalu pengelompok sebagai pemutus seri. Kolom yang dapat kosong jatuh di
 *    akhir pada urutan turun ({@see IsNullExpression}). `LIMIT n + 1`, baris terakhir hanya penanda
 *    terpotong.
 * 9. Total: query kedua dengan langkah 1–4 dan 7 yang sama — termasuk jangkauan principal — dikelompokkan hanya menurut kolom mata uang
 *    dan satuan measure — satu baris per mata uang, tidak satu jumlah campuran — tanpa batas baris.
 *
 * Area 13 menambah dua bentuk di atas langkah 1–7:
 *
 * - **Persen terhadap total** (`percent_of_total`): `nilai / sum(nilai) over (partition by <mata uang>) * 100`,
 *   dihitung sebelum `LIMIT`, jadi totalnya seluruh kelompok, bukan hanya yang lolos top-N. Partisinya kolom
 *   mata uang dan satuan measure itu sendiri: persen nilai uang dihitung per mata uang.
 * - **Perbandingan periode** (`compare`): langkah 1–7 dua kali — rentang yang diminta (`cur`) dan rentang
 *   pembanding dari {@see Comparison} dengan ember waktu digeser maju sebanyak pergeserannya (`prev`) — lalu
 *   digabung menurut semua dimensi lewat `UNION ALL` dan `GROUP BY`, supaya kosong bertemu kosong. Urutan,
 *   batas, dan persen terhadap total berlaku pada hasil gabungan. Kelompok yang hanya ada di periode lalu tetap
 *   muncul dengan nilai sekarang nol, dan periode yang tidak ada di keduanya diisi {@see GapFiller}. Selisih
 *   dan persen perubahan dihitung di SQL, dalam `numeric`; persen dari nol kosong.
 *
 * Tidak ada nama kolom yang disambung ke SQL mentah: kolom dipilih lewat `addSelect()`/`groupBy()`/
 * `orderBy()`, yang membungkusnya dengan grammar, dan ekspresi lewat objek `Expression` yang menyusun
 * SQL-nya dengan grammar.
 */
final class QueryCompiler
{
    /** Alias subquery periode yang diminta dan periode pembanding pada perbandingan periode. */
    private const CURRENT = 'cur';

    private const PREVIOUS = 'prev';

    /** Alias gabungan kedua periode pada perbandingan periode. */
    private const PERIODS = 'periods';

    public function __construct(
        private readonly JoinPlanner $joins,
        private readonly DataPolicyScope $scope,
    ) {}

    /** @throws AnalyticsQueryException */
    public function compile(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): CompiledQuery
    {
        $now = $principal->now();
        $range = $query->timeRange === null ? null : RelativeRange::expressionOf($query->timeRange, $now);
        $comparison = $query->compare === null ? null : Comparison::of(
            $query->compare,
            $query->timeRange ?? throw new LogicException('Perbandingan tanpa rentang waktu; validator seharusnya sudah menolaknya.'),
            $now,
        );

        $current = $this->grouped($dataset, $query, $principal, $range, null);

        if ($comparison === null) {
            $builder = $current['builder'];
            $subjects = $current['subjects'];
            $columns = $this->shares($builder, $query, $current['columns'], $subjects, $current['partitions']);
        } else {
            $previous = $this->grouped($dataset, $query, $principal, $comparison->previousRange(), $comparison->interval());
            [$builder, $columns, $subjects] = $this->merged($dataset, $query, $current['columns'], $current['builder'], $previous['builder'], $current['partitions'], $comparison->mode);
        }

        $this->order($builder, $query, $columns, $subjects);

        $limit = $query->limit ?? $principal->rowLimit();
        $builder->limit($limit + 1);

        return new CompiledQuery(
            $builder,
            $query->totals ? $this->totals($dataset, $query, $principal, $current['columns'], $current['currencies'], $range, $comparison) : null,
            $columns,
            $limit,
        );
    }

    /**
     * Langkah 1–7 untuk satu rentang waktu: query berkelompok tanpa urutan dan batas. `$shift` menggeser ember
     * waktu maju (query pembanding perbandingan periode); null untuk rentang yang diminta.
     *
     * Yang dipulangkan selain builder dan kolom hasilnya: `subjects` ekspresi tiap alias (untuk kunci
     * kosong-di-akhir dan persen terhadap total), `partitions` alias kolom mata uang dan satuan tiap measure, dan
     * `currencies` kolom mata uang dan satuan berkualifikasi => alias di hasil.
     *
     * @return array{builder: Builder<Model>, columns: list<ResultColumn>, subjects: array<string, Expression|string>, partitions: array<string, list<string>>, currencies: array<string, string>}
     *
     * @throws AnalyticsQueryException
     */
    private function grouped(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, ?string $range, ?string $shift): array
    {
        $references = [];
        foreach ($query->dimensions as $dimension) {
            if ($dimension->granularity === null && $dataset->reference($dimension->field) !== null) {
                $references[] = $dimension->field;
            }
        }

        $builder = $this->filtered($dataset, $query, $principal, [...$this->groupColumns($dataset, $query), ...$this->filterColumns($dataset, $query, $principal)], $references, $range);

        $columns = [];
        /** @var array<string, Expression|string> $subjects */
        $subjects = [];
        /** @var array<string, string> $grouped kolom berkualifikasi dimensi biasa => alias */
        $grouped = [];

        foreach ($query->dimensions as $i => $dimension) {
            $alias = "d{$i}";
            if ($dimension->granularity === null) {
                $column = $dataset->qualified($dimension->field);
                $builder->addSelect($column.' as '.$alias)->groupBy($alias);
                $subjects[$alias] = $column;
                $grouped[$column] ??= $alias;
            } else {
                $bucket = new TimeBucketExpression($dimension->granularity, $dataset->qualified($dimension->field), $dataset->timeType($dimension->field), $principal->timezone(), $shift);
                $builder->selectExpression($bucket, $alias)->groupBy($alias);
                $subjects[$alias] = $bucket;
            }
            $result = ResultColumn::dimension($alias, $dimension, $dataset);
            if ($result->labelAlias !== null) {
                $label = $dataset->labelColumnsFor($dimension->field)['label'] ?? throw new LogicException("Rujukan `{$dimension->field}` tanpa kolom label.");
                $builder->addSelect($label.' as '.$result->labelAlias)->groupBy($result->labelAlias);
            }
            $columns[] = $result;
        }

        /** @var array<string, string> $currencies kolom mata uang dan satuan => alias di hasil */
        $currencies = [];
        $currencyKeys = [];
        $implicit = 0;
        foreach ($this->currencyColumns($dataset, $query) as $column => [$key, $caption]) {
            $currencyKeys[$column] = $key;
            // Sudah dipilih sebagai dimensi: tidak dikelompokkan dua kali.
            if (isset($grouped[$column])) {
                $currencies[$column] = $grouped[$column];

                continue;
            }
            $alias = 'c'.$implicit++;
            $builder->addSelect($column.' as '.$alias)->groupBy($alias);
            $currencies[$column] = $alias;
            $subjects[$alias] = $column;
            $columns[] = ResultColumn::implicit($alias, $key, $caption, $dataset);
        }

        /** @var array<string, list<string>> $partitions */
        $partitions = [];
        foreach ($query->measures as $i => $key) {
            $alias = "m{$i}";
            $formula = $query->formula($key);
            $expression = $formula === null ? MeasureExpression::for($dataset, $dataset->measure($key)) : new FormulaExpression($formula->node, $dataset);
            $builder->selectExpression($expression, $alias)->addBinding($expression->bindings(), 'select');
            $subjects[$alias] = $expression;

            [$currency, $unit] = $this->ownCurrency($dataset, $this->measuresOf($dataset, $query, $key));
            $partitions[$alias] = array_values(array_map(static fn (string $column): string => $currencies[$column], array_filter([$currency, $unit])));
            $columns[] = $formula === null
                ? ResultColumn::measure($alias, $key, $dataset)
                : ResultColumn::formula($alias, $formula, $currency === null ? null : $currencyKeys[$currency], $unit === null ? null : $currencyKeys[$unit]);
        }

        return ['builder' => $builder, 'columns' => $columns, 'subjects' => $subjects, 'partitions' => $partitions, 'currencies' => $currencies];
    }

    /**
     * Langkah 1–4: query dasar, join yang dibutuhkan, jangkauan principal (kebijakan data dan saringan
     * terkunci), lalu saringan pengguna dan rentang waktu. Dipakai query hasil, query pembanding, dan query
     * total, supaya semuanya menyaring persis sama; hanya rentang waktunya yang dapat berbeda.
     *
     * @param  list<string>  $columns  kolom berkualifikasi yang dipakai query, bahan {@see JoinPlanner}
     * @param  list<string>  $references  dimensi rujukan yang labelnya ikut dipilih
     * @param  ?string  $range  ekspresi rentang waktu untuk `FieldFilterExpression`, atau null tanpa rentang
     * @return Builder<Model>
     *
     * @throws AnalyticsQueryException
     */
    private function filtered(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, array $columns, array $references, ?string $range): Builder
    {
        $builder = $dataset->baseQuery();
        $this->joins->apply($builder, $dataset, $columns, $references);

        $this->scope->apply($builder, $dataset, $principal);

        foreach ($query->filters as $key => $value) {
            try {
                FieldFilterExpression::apply($builder, $dataset->filterField($key), $value, $principal->timezone());
            } catch (InvalidFilterExpression $e) {
                throw AnalyticsQueryException::invalidFilter("filters.{$key}", $e->getMessage(), $e);
            }
        }

        if ($query->timeRange !== null && $range !== null) {
            // Kolomnya sudah dipastikan kolom waktu dataset oleh validator.
            $timeKey = $this->timeRangeField($dataset, $query->timeRange);
            try {
                FieldFilterExpression::apply($builder, $dataset->filterField($timeKey), $range, $principal->timezone());
            } catch (InvalidFilterExpression $e) {
                throw AnalyticsQueryException::invalidFilter('time_range.range', $e->getMessage(), $e);
            }
        }

        return $builder;
    }

    /**
     * Langkah 9: total keseluruhan, dikelompokkan hanya menurut kolom mata uang dan satuan measure, dengan
     * alias yang sama dengan di hasil — dimensi bila kolom itu dipilih, kolom tersirat bila tidak — supaya
     * baris total memakai kunci yang sama. Tidak dibatasi top-N: total menghitung seluruh kelompok. Dengan
     * perbandingan periode, total kedua periode digabung menurut kolom-kolom itu, beserta selisih dan persen
     * perubahannya; persen terhadap total tidak dihitung untuk baris total, karena selalu 100.
     *
     * @param  list<ResultColumn>  $columns  kolom query hasil sebelum kolom turunan
     * @param  array<string, string>  $currencies  kolom mata uang dan satuan => alias di hasil
     * @return Builder<Model>
     *
     * @throws AnalyticsQueryException
     */
    private function totals(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, array $columns, array $currencies, ?string $range, ?Comparison $comparison): Builder
    {
        $totals = $this->totalsFor($dataset, $query, $principal, $currencies, $range);
        if ($comparison === null) {
            foreach ($currencies as $alias) {
                $totals->orderBy($alias);
            }

            return $totals;
        }

        $previous = $this->totalsFor($dataset, $query, $principal, $currencies, $comparison->previousRange());
        $kept = array_values(array_filter($columns, static fn (ResultColumn $column): bool => $column->kind === ResultColumn::MEASURE || in_array($column->alias, $currencies, true)));
        [$merged] = $this->merged($dataset, $query, $kept, $totals, $previous, null, $comparison->mode);
        foreach ($currencies as $alias) {
            $merged->orderBy($alias);
        }

        return $merged;
    }

    /**
     * Query total satu rentang waktu: langkah 1–4 dan 7, dikelompokkan menurut kolom mata uang dan satuan.
     *
     * @param  array<string, string>  $currencies
     * @return Builder<Model>
     *
     * @throws AnalyticsQueryException
     */
    private function totalsFor(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, array $currencies, ?string $range): Builder
    {
        $totals = $this->filtered($dataset, $query, $principal, [...$this->measureColumns($dataset, $query), ...$this->filterColumns($dataset, $query, $principal)], [], $range);

        foreach ($currencies as $column => $alias) {
            $totals->addSelect($column.' as '.$alias)->groupBy($alias);
        }
        foreach ($query->measures as $i => $key) {
            $formula = $query->formula($key);
            $expression = $formula === null ? MeasureExpression::for($dataset, $dataset->measure($key)) : new FormulaExpression($formula->node, $dataset);
            $totals->selectExpression($expression, "m{$i}")->addBinding($expression->bindings(), 'select');
        }

        return $totals;
    }

    /**
     * Persen terhadap total pada query tanpa perbandingan: satu kolom `<alias>_share` sesudah setiap measure yang
     * diminta, dihitung dengan fungsi jendela di atas ekspresi agregatnya.
     *
     * @param  Builder<Model>  $builder
     * @param  list<ResultColumn>  $columns
     * @param  array<string, Expression|string>  $subjects
     * @param  array<string, list<string>>  $partitions
     * @return list<ResultColumn> kolom hasil beserta kolom persen, dalam urutan tampilnya
     */
    private function shares(Builder $builder, AnalyticsQuery $query, array $columns, array $subjects, array $partitions): array
    {
        if ($query->percentOfTotal === []) {
            return $columns;
        }

        $out = [];
        foreach ($columns as $column) {
            $out[] = $column;
            if ($column->kind === ResultColumn::MEASURE && in_array($column->key, $query->percentOfTotal, true)) {
                $out[] = $this->share($builder, $column, $subjects[$column->alias], array_map(static fn (string $alias): Expression|string => $subjects[$alias], $partitions[$column->alias]));
            }
        }

        return $out;
    }

    /**
     * Satu kolom persen terhadap total: `nilai / sum(nilai) over (partition by <mata uang>) * 100`. Total nol
     * menjadi kosong.
     *
     * @param  Builder<Model>  $builder
     * @param  list<Expression|string>  $partition
     */
    private function share(Builder $builder, ResultColumn $column, Expression|string $value, array $partition): ResultColumn
    {
        $over = $partition === [] ? '' : 'partition by '.implode(', ', array_map(static fn (int $i): string => '{'.($i + 1).'}', array_keys($partition)));
        $expression = new SqlTemplate('cast({0} as numeric) / nullif(sum({0}) over ('.$over.'), 0) * 100', [$value, ...$partition]);
        $alias = $column->alias.'_share';
        $builder->selectExpression($expression, $alias)->addBinding($expression->bindings(), 'select');

        return ResultColumn::derived($alias, $column, ResultColumn::PERCENT_OF_TOTAL);
    }

    /**
     * Perbandingan periode: baris query periode yang diminta (`cur`) dan query pembanding (`prev`) disatukan dengan
     * `UNION ALL` — tiap sisi mengisi kolom measure-nya sendiri dan membiarkan kolom sisi lain kosong — lalu
     * dikelompokkan menurut semua kolom dimensi. Hasilnya sama dengan gabungan luar penuh menurut dimensi, dengan
     * kosong bertemu kosong seperti di `GROUP BY`; `FULL JOIN … IS NOT DISTINCT FROM` ditolak PostgreSQL karena
     * syaratnya tidak dapat di-hash. Tiap sisi sudah berkelompok, jadi setiap kelompok paling banyak satu baris per
     * sisi dan `max()` hanya memilih nilai sisi itu. Tanpa dimensi sama sekali, hasilnya tepat satu baris.
     *
     * Setiap measure lalu mendapat nilai pembanding, selisih, dan persen perubahan. Jumlah dan hitungan yang kosong
     * di satu sisi berarti nol — kelompok itu tidak punya baris di periode itu — sedangkan rata-rata, terkecil,
     * terbesar, dan rumus tetap kosong. Persen perubahan memakai nilai mutlak pembanding sebagai penyebut, supaya
     * naik dari minus tetap terbaca naik, dan kosong bila pembandingnya nol.
     *
     * @param  list<ResultColumn>  $columns  kolom dimensi dan measure; aliasnya sama di kedua subquery
     * @param  Builder<Model>  $current
     * @param  Builder<Model>  $previous
     * @param  array<string, list<string>>|null  $partitions  alias kolom mata uang tiap measure untuk persen terhadap
     *                                                         total, atau null tanpa persen terhadap total (baris total)
     * @return array{0: Builder<Model>, 1: list<ResultColumn>, 2: array<string, Expression|string>}
     */
    private function merged(CompiledDataset $dataset, AnalyticsQuery $query, array $columns, Builder $current, Builder $previous, ?array $partitions, CompareMode $mode): array
    {
        // Query luar tanpa scope model: scope tenant dan arsip sudah ada di dalam kedua subquery, dan nama tabel dasar
        // tidak ada di FROM query luar.
        $sides = [];
        foreach ([self::CURRENT => $current, self::PREVIOUS => $previous] as $side => $grouped) {
            $part = (new $dataset->model)->newQueryWithoutScopes()->fromSub($grouped, $side);
            foreach ($columns as $column) {
                if ($column->kind === ResultColumn::DIMENSION) {
                    $part->addSelect($side.'.'.$column->alias.' as '.$column->alias);
                    if ($column->labelAlias !== null) {
                        $part->addSelect($side.'.'.$column->labelAlias.' as '.$column->labelAlias);
                    }

                    continue;
                }
                foreach ([self::CURRENT, self::PREVIOUS] as $slot) {
                    if ($slot === $side) {
                        $part->addSelect($side.'.'.$column->alias.' as '.$column->alias.'_'.$slot);
                    } else {
                        $part->selectExpression(new SqlTemplate('null', []), $column->alias.'_'.$slot);
                    }
                }
            }
            $sides[] = $part;
        }
        $outer = (new $dataset->model)->newQueryWithoutScopes()->fromSub($sides[0]->unionAll($sides[1]), self::PERIODS);

        $out = [];
        /** @var array<string, Expression|string> $subjects */
        $subjects = [];
        foreach ($columns as $column) {
            if ($column->kind === ResultColumn::DIMENSION) {
                $outer->addSelect(self::PERIODS.'.'.$column->alias.' as '.$column->alias)->groupBy($column->alias);
                $subjects[$column->alias] = self::PERIODS.'.'.$column->alias;
                if ($column->labelAlias !== null) {
                    $outer->addSelect(self::PERIODS.'.'.$column->labelAlias.' as '.$column->labelAlias)->groupBy($column->labelAlias);
                }
                $out[] = $column;

                continue;
            }

            $now = self::side($column, self::CURRENT);
            $before = self::side($column, self::PREVIOUS);
            $outer->selectExpression($now, $column->alias);
            $subjects[$column->alias] = $now;
            $out[] = $column;

            $outer->selectExpression($before, $column->alias.'_previous');
            $out[] = ResultColumn::derived($column->alias.'_previous', $column, ResultColumn::PREVIOUS, $mode->caption());

            $outer->selectExpression(new SqlTemplate('({0} - {1})', [$now, $before]), $column->alias.'_change');
            $out[] = ResultColumn::derived($column->alias.'_change', $column, ResultColumn::CHANGE, $mode->caption());

            $outer->selectExpression(new SqlTemplate('cast(({0} - {1}) as numeric) / nullif(abs({1}), 0) * 100', [$now, $before]), $column->alias.'_change_pct');
            $out[] = ResultColumn::derived($column->alias.'_change_pct', $column, ResultColumn::CHANGE_PERCENT, $mode->caption());

            if ($partitions !== null && in_array($column->key, $query->percentOfTotal, true)) {
                $out[] = $this->share($outer, $column, $now, array_map(static fn (string $alias): Expression|string => $subjects[$alias], $partitions[$column->alias]));
            }
        }

        return [$outer, $out, $subjects];
    }

    /** Nilai satu measure dari satu periode di gabungan; jumlah dan hitungan yang kosong berarti nol. */
    private static function side(ResultColumn $column, string $side): SqlTemplate
    {
        $value = self::PERIODS.'.'.$column->alias.'_'.$side;

        return in_array($column->aggregate, [Aggregate::Count, Aggregate::CountDistinct, Aggregate::Sum], true)
            ? new SqlTemplate('coalesce(max({0}), 0)', [$value])
            : new SqlTemplate('max({0})', [$value]);
    }

    /**
     * Langkah 8. Urutan pilihan pengguna menggantikan bawaan; pengelompok yang belum ikut diurutkan menjadi
     * pemutus seri, supaya hasil — dan baris mana yang terpotong — sama dari satu putaran ke putaran
     * berikutnya. Bawaan: setiap periode naik bila ada ember waktu (deret waktu dibaca dari kiri ke
     * kanan), selain itu measure pertama turun.
     *
     * @param  Builder<Model>  $builder
     * @param  list<ResultColumn>  $columns
     * @param  array<string, Expression|string>  $subjects
     */
    private function order(Builder $builder, AnalyticsQuery $query, array $columns, array $subjects): void
    {
        $byKey = [];
        foreach ($columns as $column) {
            $byKey[$column->key] = $column;
        }

        $sorts = $query->sort;
        if ($sorts === []) {
            foreach ($columns as $column) {
                if ($column->granularity !== null) {
                    $sorts[] = ['key' => $column->key, 'direction' => 'asc'];
                }
            }
        }
        if ($sorts === []) {
            $sorts = [['key' => $query->measures[0], 'direction' => 'desc']];
        }

        $ordered = [];
        foreach ($sorts as $sort) {
            $column = $byKey[$sort['key']] ?? throw new LogicException("Urutan memakai `{$sort['key']}` yang tidak dipilih; validator seharusnya sudah menolaknya.");
            $subject = $subjects[$column->alias];
            // PostgreSQL menaruh kosong di depan pada urutan turun. Jumlah dan hitungan tidak pernah kosong;
            // rata-rata, terkecil, terbesar, dan rumus dapat kosong.
            $nullable = $column->kind === ResultColumn::DIMENSION
                || ! in_array($column->aggregate, [Aggregate::Count, Aggregate::CountDistinct, Aggregate::Sum], true);
            if ($sort['direction'] === 'desc' && $nullable) {
                $builder->orderBy(new IsNullExpression($subject));
                if ($subject instanceof BoundExpression) {
                    $builder->addBinding($subject->bindings(), 'order');
                }
            }
            $builder->orderBy($column->alias, $sort['direction']);
            $ordered[$column->alias] = true;
        }
        foreach ($columns as $column) {
            if ($column->kind === ResultColumn::DIMENSION && ! isset($ordered[$column->alias])) {
                $builder->orderBy($column->alias);
            }
        }
    }

    /**
     * Kolom yang dipakai pengelompokan dan measure, bahan {@see JoinPlanner}.
     *
     * @return list<string>
     */
    private function groupColumns(CompiledDataset $dataset, AnalyticsQuery $query): array
    {
        $columns = $this->measureColumns($dataset, $query);
        foreach ($query->dimensions as $dimension) {
            $columns[] = $dataset->qualified($dimension->field);
        }

        return $columns;
    }

    /**
     * Kolom measure yang dipakai — measure terpilih dan measure di dalam rumus terpilih: kolom yang dihitung,
     * kolom saringan tetapnya, dan kolom mata uang serta satuannya.
     *
     * @return list<string>
     */
    private function measureColumns(CompiledDataset $dataset, AnalyticsQuery $query): array
    {
        $columns = array_keys($this->currencyColumns($dataset, $query));
        foreach ($this->usedMeasures($dataset, $query) as $measure) {
            if ($measure->field !== null) {
                $columns[] = $dataset->qualified($measure->field);
            }
            foreach (array_keys($measure->where) as $field) {
                $columns[] = $dataset->qualified($field);
            }
        }

        return $columns;
    }

    /**
     * Kolom yang dipakai penyaringan: kebijakan data, saringan terkunci principal, saringan pengguna, dan
     * rentang waktu — supaya join yang dibaca {@see DataPolicyScope} ikut terpasang. Saringan terkunci pada
     * field yang tidak dikenal tidak punya kolom; `DataPolicyScope` menjadikannya nol baris.
     *
     * @return list<string>
     */
    private function filterColumns(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): array
    {
        $columns = [];
        if ($dataset->policy !== null) {
            $columns[] = $dataset->qualified($dataset->policy['legal_entity']);
            if ($dataset->policy['operating_unit'] !== null) {
                $columns[] = $dataset->qualified($dataset->policy['operating_unit']);
            }
        }
        foreach (array_keys($principal->lockedFilters($dataset->code)) as $key) {
            if ($dataset->hasField($key)) {
                $columns[] = $dataset->filterField($key)->column;
            }
        }
        foreach (array_keys($query->filters) as $key) {
            $columns[] = $dataset->filterField($key)->column;
        }
        if ($query->timeRange !== null) {
            $columns[] = $dataset->filterField($this->timeRangeField($dataset, $query->timeRange))->column;
        }

        return $columns;
    }

    private function timeRangeField(CompiledDataset $dataset, TimeRange $range): string
    {
        return $range->field ?? $dataset->defaultTime() ?? throw new LogicException('Rentang waktu tanpa kolom waktu; validator seharusnya sudah menolaknya.');
    }

    /**
     * Kolom mata uang dan satuan dari measure yang dipakai, tanpa ganda, dalam urutan measure.
     *
     * @return array<string, array{0: string, 1: string}> kolom berkualifikasi => kunci di hasil (sama dengan
     *                                                    `currency_key`/`unit_key` measure) dan nama tampilan cadangannya
     */
    private function currencyColumns(CompiledDataset $dataset, AnalyticsQuery $query): array
    {
        $columns = [];
        foreach ($this->usedMeasures($dataset, $query) as $measure) {
            foreach ([[$measure->currency, 'Mata uang'], [$measure->unit, 'Satuan']] as [$column, $caption]) {
                if ($column !== null) {
                    $columns[$dataset->qualified($column)] ??= [$column, $caption];
                }
            }
        }

        return $columns;
    }

    /**
     * Measure dataset yang dihitung query: yang dipilih langsung, lalu yang dirujuk rumus terpilih, tanpa ganda.
     *
     * @return list<CompiledMeasure>
     */
    private function usedMeasures(CompiledDataset $dataset, AnalyticsQuery $query): array
    {
        $used = [];
        foreach ($query->measures as $key) {
            foreach ($this->measuresOf($dataset, $query, $key) as $measure) {
                $used[$measure->key] ??= $measure;
            }
        }

        return array_values($used);
    }

    /**
     * Measure dataset di balik satu kunci `measures`: measure itu sendiri, atau measure yang dirujuk rumusnya.
     *
     * @return list<CompiledMeasure>
     */
    private function measuresOf(CompiledDataset $dataset, AnalyticsQuery $query, string $key): array
    {
        $formula = $query->formula($key);

        return $formula === null
            ? [$dataset->measure($key)]
            : array_map(static fn (string $measure): CompiledMeasure => $dataset->measure($measure), $formula->measures());
    }

    /**
     * Kolom mata uang dan satuan berkualifikasi yang diwarisi satu measure atau rumus, atau null. Validator sudah
     * memastikan rumus tidak mencampur dua kolom mata uang atau dua kolom satuan.
     *
     * @param  list<CompiledMeasure>  $measures
     * @return array{0: ?string, 1: ?string}
     */
    private function ownCurrency(CompiledDataset $dataset, array $measures): array
    {
        $currency = null;
        $unit = null;
        foreach ($measures as $measure) {
            $currency ??= $measure->currency === null ? null : $dataset->qualified($measure->currency);
            $unit ??= $measure->unit === null ? null : $dataset->qualified($measure->unit);
        }

        return [$currency, $unit];
    }
}
