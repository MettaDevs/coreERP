<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DataPolicyScope;
use App\Platform\Modules\Contracts\FieldFilterExpression;
use App\Platform\Modules\Contracts\InvalidFilterExpression;
use LogicException;

/**
 * Menyusun query builder Laravel dari query analitik. Tidak menjalankan apa pun; eksekusinya milik
 * {@see QueryExecutor}, supaya test dan alat operator dapat memeriksa SQL tanpa membaca data.
 *
 * Urutannya bukan selera; setiap langkah bergantung pada langkah sebelumnya:
 *
 * 1. Query dasar dari model dataset — `TenantScope` dan `SoftDeletes` ikut dari model. Tabel dasar tidak
 *    pernah diberi alias, karena scope tenant disisipkan dengan nama tabel sebenarnya.
 * 2. Jangkauan principal lewat {@see DataPolicyScope} — kebijakan data pada kolom yang dinyatakan dataset,
 *    lalu saringan terkunci principal — **sebelum** saringan pengguna. Saringan pengguna hanya dapat
 *    menyempitkan.
 * 3. Saringan pengguna dan rentang waktu lewat `FieldFilterExpression` (sintaks BC filter tambahan K-30),
 *    nilai lewat binding. Token rentang relatif menjadi `Y-m-d..Y-m-d` menurut zona principal
 *    ({@see RelativeRange}).
 * 4. Dimensi dengan alias posisi `d0, d1, …`, dikelompokkan **menurut alias**: ekspresi berparameter yang
 *    diulang di `GROUP BY` menjadi parameter lain bagi PostgreSQL, dan pengelompokannya ditolak.
 * 5. Kolom mata uang dan satuan measure sebagai dimensi tersirat `c0, …`, supaya uang tidak pernah
 *    dijumlah lintas mata uang (KA-22).
 * 6. Measure dengan alias `m0, m1, …`, lewat {@see MeasureExpression}.
 * 7. Urutan dan batas: urutan pilihan pengguna, lalu pengelompok sebagai pemutus seri; `LIMIT n + 1`, baris
 *    terakhir hanya penanda terpotong.
 *
 * Tidak ada nama kolom yang disambung ke SQL mentah: kolom dipilih lewat `addSelect()`/`groupBy()`/
 * `orderBy()`, yang membungkusnya dengan grammar, dan measure lewat `selectExpression()`.
 *
 * Isi kerangka berjalan (area 0) ditambah rentang waktu dan urutan pilihan pengguna (area 2), supaya
 * kunci yang sudah dibaca parser tidak pernah diabaikan diam-diam, dan langkah 2 dari area 4. Yang belum
 * dikompilasi — ember waktu dan total — ditolak 422, bukan diabaikan. Area 3 menambah join, ember waktu,
 * saringan tetap measure, total, dan `NULLS LAST` untuk measure yang dapat kosong.
 */
final class QueryCompiler
{
    public function __construct(private readonly DataPolicyScope $scope) {}

    /** @throws AnalyticsQueryException */
    public function compile(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): CompiledQuery
    {
        $builder = $dataset->baseQuery();

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
            $timeKey = $query->timeRange->field ?? $dataset->defaultTime() ?? throw new LogicException('Rentang waktu tanpa kolom waktu; validator seharusnya sudah menolaknya.');
            try {
                FieldFilterExpression::apply($builder, $dataset->filterField($timeKey), RelativeRange::expression($query->timeRange->range, $principal->now()), $principal->timezone());
            } catch (InvalidFilterExpression $e) {
                throw AnalyticsQueryException::invalidFilter('time_range.range', $e->getMessage(), $e);
            }
        }

        // Pengelompokan menurut waktu dan baris total belum dikompilasi (area 3). Menolaknya lebih jujur
        // daripada mengabaikannya dan memulangkan hasil yang bukan yang diminta.
        if ($query->totals) {
            throw AnalyticsQueryException::invalidQuery('totals', 'Baris total belum tersedia.');
        }

        $columns = [];

        foreach ($query->dimensions as $i => $dimension) {
            if ($dimension->granularity !== null) {
                throw AnalyticsQueryException::invalidQuery("dimensions.{$i}.granularity", 'Pengelompokan menurut waktu belum tersedia.');
            }
            $alias = "d{$i}";
            $builder->addSelect($dataset->qualified($dimension->field).' as '.$alias)->groupBy($alias);
            $columns[] = ResultColumn::dimension($alias, $dimension, $dataset);
        }

        foreach ($this->implicitDimensions($dataset, $query) as $j => [$column, $caption]) {
            $alias = "c{$j}";
            $builder->addSelect($dataset->qualified($column).' as '.$alias)->groupBy($alias);
            $columns[] = ResultColumn::implicit($alias, $column, $caption, $dataset);
        }

        foreach ($query->measures as $i => $key) {
            $measure = $dataset->measure($key);
            // Saringan tetap measure (`FILTER (WHERE …)`) milik area 3. Menolaknya lebih jujur daripada
            // menghitungnya tanpa saringan.
            if ($measure->where !== []) {
                throw new LogicException("Measure `{$measure->key}` memakai saringan tetap, yang belum dikompilasi.");
            }
            $alias = "m{$i}";
            $builder->selectExpression(new MeasureExpression($measure->aggregate, $measure->field === null ? null : $dataset->qualified($measure->field)), $alias);
            $columns[] = ResultColumn::measure($alias, $key, $dataset);
        }

        // Urutan pilihan pengguna; tanpa itu, measure pertama turun. Pengelompok menjadi pemutus seri, supaya
        // hasil — dan baris mana yang terpotong — sama dari satu putaran ke putaran berikutnya.
        $aliases = [];
        foreach ($columns as $column) {
            $aliases[$column->key] = $column->alias;
        }
        $sorts = $query->sort !== [] ? $query->sort : [['key' => $query->measures[0], 'direction' => 'desc']];
        $ordered = [];
        foreach ($sorts as $sort) {
            $alias = $aliases[$sort['key']] ?? throw new LogicException("Urutan memakai `{$sort['key']}` yang tidak dipilih; validator seharusnya sudah menolaknya.");
            $builder->orderBy($alias, $sort['direction']);
            $ordered[$alias] = true;
        }
        foreach ($columns as $column) {
            if ($column->kind === ResultColumn::DIMENSION && ! isset($ordered[$column->alias])) {
                $builder->orderBy($column->alias);
            }
        }

        $limit = $query->limit ?? $principal->rowLimit();
        $builder->limit($limit + 1);

        return new CompiledQuery($builder, null, $columns, $limit);
    }

    /**
     * Kolom mata uang dan satuan dari measure yang dipilih, yang belum dipilih sebagai dimensi.
     *
     * @return list<array{0: string, 1: string}> kolom dan nama tampilan cadangannya
     */
    private function implicitDimensions(CompiledDataset $dataset, AnalyticsQuery $query): array
    {
        $chosen = array_map(static fn (Dimension $dimension): string => $dimension->field, $query->dimensions);
        $implicit = [];

        foreach ($query->measures as $key) {
            $measure = $dataset->measure($key);
            foreach ([[$measure->currency, 'Mata uang'], [$measure->unit, 'Satuan']] as [$column, $caption]) {
                if ($column !== null && ! in_array($column, $chosen, true) && ! isset($implicit[$column])) {
                    $implicit[$column] = [$column, $caption];
                }
            }
        }

        return array_values($implicit);
    }
}
