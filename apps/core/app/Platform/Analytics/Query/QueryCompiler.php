<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DataPolicyScope;
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
 *    ({@see RelativeRange}).
 * 5. Dimensi dengan alias posisi `d0, d1, …`, dikelompokkan **menurut alias**: ekspresi berparameter yang
 *    diulang di `GROUP BY` menjadi parameter lain bagi PostgreSQL, dan pengelompokannya ditolak. Ember
 *    waktu lewat {@see TimeBucketExpression}; label rujukan module ikut dipilih dan dikelompokkan
 *    (`d0_label`).
 * 6. Kolom mata uang dan satuan measure sebagai dimensi tersirat `c0, …`, supaya uang tidak pernah
 *    dijumlah lintas mata uang (KA-22).
 * 7. Measure dengan alias `m0, m1, …`, lewat {@see MeasureExpression}, termasuk saringan tetapnya.
 * 8. Urutan dan batas: urutan pilihan pengguna, atau bawaan — periode naik bila ada ember waktu, selain
 *    itu measure pertama turun — lalu pengelompok sebagai pemutus seri. Kolom yang dapat kosong jatuh di
 *    akhir pada urutan turun ({@see IsNullExpression}). `LIMIT n + 1`, baris terakhir hanya penanda
 *    terpotong.
 * 9. Total: query kedua dengan langkah 1–4 dan 7 yang sama — termasuk jangkauan principal — dikelompokkan hanya menurut kolom mata uang
 *    dan satuan measure — satu baris per mata uang, tidak satu jumlah campuran — tanpa batas baris.
 *
 * Tidak ada nama kolom yang disambung ke SQL mentah: kolom dipilih lewat `addSelect()`/`groupBy()`/
 * `orderBy()`, yang membungkusnya dengan grammar, dan ekspresi lewat objek `Expression` yang menyusun
 * SQL-nya dengan grammar.
 */
final class QueryCompiler
{
    public function __construct(
        private readonly JoinPlanner $joins,
        private readonly DataPolicyScope $scope,
    ) {}

    /** @throws AnalyticsQueryException */
    public function compile(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): CompiledQuery
    {
        $references = [];
        foreach ($query->dimensions as $dimension) {
            if ($dimension->granularity === null && $dataset->reference($dimension->field) !== null) {
                $references[] = $dimension->field;
            }
        }

        $builder = $this->filtered($dataset, $query, $principal, [...$this->groupColumns($dataset, $query), ...$this->filterColumns($dataset, $query, $principal)], $references);

        $columns = [];
        /** @var array<string, Expression|string> $sortSubjects ekspresi tiap alias, untuk kunci kosong-di-akhir */
        $sortSubjects = [];
        /** @var array<string, string> $grouped kolom berkualifikasi dimensi biasa => alias */
        $grouped = [];

        foreach ($query->dimensions as $i => $dimension) {
            $alias = "d{$i}";
            if ($dimension->granularity === null) {
                $column = $dataset->qualified($dimension->field);
                $builder->addSelect($column.' as '.$alias)->groupBy($alias);
                $sortSubjects[$alias] = $column;
                $grouped[$column] ??= $alias;
            } else {
                $bucket = new TimeBucketExpression($dimension->granularity, $dataset->qualified($dimension->field), $dataset->timeType($dimension->field), $principal->timezone());
                $builder->selectExpression($bucket, $alias)->groupBy($alias);
                $sortSubjects[$alias] = $bucket;
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
        $implicit = 0;
        foreach ($this->currencyColumns($dataset, $query) as $column => [$key, $caption]) {
            // Sudah dipilih sebagai dimensi: tidak dikelompokkan dua kali.
            if (isset($grouped[$column])) {
                $currencies[$column] = $grouped[$column];

                continue;
            }
            $alias = 'c'.$implicit++;
            $builder->addSelect($column.' as '.$alias)->groupBy($alias);
            $currencies[$column] = $alias;
            $columns[] = ResultColumn::implicit($alias, $key, $caption, $dataset);
        }

        foreach ($query->measures as $i => $key) {
            $alias = "m{$i}";
            $expression = MeasureExpression::for($dataset, $dataset->measure($key));
            $builder->selectExpression($expression, $alias)->addBinding($expression->bindings(), 'select');
            $sortSubjects[$alias] = $expression;
            $columns[] = ResultColumn::measure($alias, $key, $dataset);
        }

        $this->order($builder, $query, $columns, $sortSubjects);

        $limit = $query->limit ?? $principal->rowLimit();
        $builder->limit($limit + 1);

        return new CompiledQuery($builder, $query->totals ? $this->totals($dataset, $query, $principal, $currencies) : null, $columns, $limit);
    }

    /**
     * Langkah 1–4: query dasar, join yang dibutuhkan, jangkauan principal (kebijakan data dan saringan
     * terkunci), lalu saringan pengguna dan rentang waktu. Dipakai query hasil dan query total, supaya
     * keduanya menyaring persis sama.
     *
     * @param  list<string>  $columns  kolom berkualifikasi yang dipakai query, bahan {@see JoinPlanner}
     * @param  list<string>  $references  dimensi rujukan yang labelnya ikut dipilih
     * @return Builder<Model>
     *
     * @throws AnalyticsQueryException
     */
    private function filtered(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, array $columns, array $references): Builder
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

        if ($query->timeRange !== null) {
            // Kolomnya sudah dipastikan kolom waktu dataset oleh validator.
            $timeKey = $this->timeRangeField($dataset, $query->timeRange);
            try {
                FieldFilterExpression::apply($builder, $dataset->filterField($timeKey), RelativeRange::expression($query->timeRange->range, $principal->now()), $principal->timezone());
            } catch (InvalidFilterExpression $e) {
                throw AnalyticsQueryException::invalidFilter('time_range.range', $e->getMessage(), $e);
            }
        }

        return $builder;
    }

    /**
     * Langkah 9: total keseluruhan, dikelompokkan hanya menurut kolom mata uang dan satuan measure, dengan
     * alias yang sama dengan di hasil — dimensi bila kolom itu dipilih, kolom tersirat bila tidak — supaya
     * baris total memakai kunci yang sama. Tidak dibatasi top-N: total menghitung seluruh kelompok.
     *
     * @param  array<string, string>  $currencies  kolom mata uang dan satuan => alias di hasil
     * @return Builder<Model>
     *
     * @throws AnalyticsQueryException
     */
    private function totals(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, array $currencies): Builder
    {
        $totals = $this->filtered($dataset, $query, $principal, [...$this->measureColumns($dataset, $query), ...$this->filterColumns($dataset, $query, $principal)], []);

        foreach ($currencies as $column => $alias) {
            $totals->addSelect($column.' as '.$alias)->groupBy($alias)->orderBy($alias);
        }
        foreach ($query->measures as $i => $key) {
            $expression = MeasureExpression::for($dataset, $dataset->measure($key));
            $totals->selectExpression($expression, "m{$i}")->addBinding($expression->bindings(), 'select');
        }

        return $totals;
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
            // PostgreSQL menaruh kosong di depan pada urutan turun. Jumlah dan hitungan tidak pernah kosong.
            $nullable = $column->kind === ResultColumn::DIMENSION || ($subject instanceof MeasureExpression && $subject->nullable());
            if ($sort['direction'] === 'desc' && $nullable) {
                $builder->orderBy(new IsNullExpression($subject));
                if ($subject instanceof MeasureExpression) {
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
     * Kolom measure yang dipilih: kolom yang dihitung, kolom saringan tetapnya, dan kolom mata uang serta
     * satuannya.
     *
     * @return list<string>
     */
    private function measureColumns(CompiledDataset $dataset, AnalyticsQuery $query): array
    {
        $columns = array_keys($this->currencyColumns($dataset, $query));
        foreach ($query->measures as $key) {
            $measure = $dataset->measure($key);
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
     * Kolom mata uang dan satuan dari measure yang dipilih, tanpa ganda, dalam urutan measure.
     *
     * @return array<string, array{0: string, 1: string}> kolom berkualifikasi => kunci di hasil (sama dengan
     *                                                    `currency_key`/`unit_key` measure) dan nama tampilan cadangannya
     */
    private function currencyColumns(CompiledDataset $dataset, AnalyticsQuery $query): array
    {
        $columns = [];
        foreach ($query->measures as $key) {
            $measure = $dataset->measure($key);
            foreach ([[$measure->currency, 'Mata uang'], [$measure->unit, 'Satuan']] as [$column, $caption]) {
                if ($column !== null) {
                    $columns[$dataset->qualified($column)] ??= [$column, $caption];
                }
            }
        }

        return $columns;
    }
}
