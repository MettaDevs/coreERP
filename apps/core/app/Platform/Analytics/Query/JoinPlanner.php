<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledJoin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use LogicException;

/**
 * Memasang join yang dibutuhkan satu query, dan hanya itu: join data yang kolomnya disebut query
 * (beserta join yang menjadi jalannya), lalu join label rujukan untuk dimensi rujukan yang dipilih.
 *
 * Semua join adalah `LEFT JOIN`, dan syaratnya ditulis di `ON`, bukan di `WHERE`:
 *
 * - **Join data** membawa `alias.tenant_id = <tabel dasar>.tenant_id`, dan `alias.deleted_at IS NULL` bila
 *   tabelnya berarsip dan dataset tidak meminta baris terarsip. Tabel yang di-join tidak membawa scope
 *   tenant model-nya, jadi syarat tenant itulah satu-satunya penjaganya.
 * - **Join label** membawa syarat tenant yang sama, **tanpa** saringan arsip: master yang sudah diarsipkan
 *   tetap bernama di data lama.
 *
 * Kenapa `LEFT`: join hanya dipasang bila kolomnya disebut, jadi join yang membuang baris akan membuat
 * jumlah baris berubah hanya karena pengguna menambah satu pengelompok. Dengan `LEFT JOIN`, baris yang
 * induknya terarsip atau (karena data rusak) milik tenant lain tetap dihitung, dengan kolom join kosong.
 * Kebijakan data pada kolom join tetap gagal tertutup: kolom kosong tidak cocok dengan hibah apa pun.
 * Join data menunjuk satu baris induk (`foreignColumn` bawaannya `id`), sehingga tidak menggandakan baris.
 *
 * Tabel dasar tidak pernah diberi alias: syarat tenant join menunjuk nama tabel sebenarnya, sama dengan
 * `TenantScope`.
 */
final class JoinPlanner
{
    /**
     * @param  Builder<Model>  $builder
     * @param  list<string>  $columns  kolom berkualifikasi yang dipakai query: dimensi, measure, saringan,
     *                                 rentang waktu, dan kolom kebijakan
     * @param  list<string>  $references  kunci field rujukan yang labelnya ikut dipilih
     */
    public function apply(Builder $builder, CompiledDataset $dataset, array $columns, array $references): void
    {
        $joins = $dataset->joins();
        $base = $dataset->table;

        $labels = [];
        foreach ($references as $key) {
            $labels[$key] = $dataset->reference($key) ?? throw new LogicException("Field `{$key}` bukan rujukan dataset `{$dataset->code}`.");
            $columns[] = $labels[$key]->localColumn;
        }

        $needed = [];
        foreach ($columns as $column) {
            $this->require($this->aliasOf($column), $joins, $needed);
        }

        // Urutan pernyataan dataset: join hanya boleh bersandar pada join yang dinyatakan sebelumnya.
        foreach ($joins as $alias => $join) {
            if (! isset($needed[$alias])) {
                continue;
            }
            $builder->leftJoin($join->table.' as '.$alias, static function (JoinClause $clause) use ($join, $alias, $base): void {
                $clause->on($join->foreignColumn, '=', $join->localColumn)
                    ->on($alias.'.tenant_id', '=', $base.'.tenant_id');
                if ($join->archivable && ! $join->includeArchived) {
                    $clause->whereNull($alias.'.deleted_at');
                }
            });
        }

        foreach ($labels as $reference) {
            $builder->leftJoin($reference->table.' as '.$reference->alias, static function (JoinClause $clause) use ($reference, $base): void {
                $clause->on($reference->foreignColumn, '=', $reference->localColumn)
                    ->on($reference->alias.'.tenant_id', '=', $base.'.tenant_id');
            });
        }
    }

    /**
     * Tandai alias join beserta join yang menjadi jalannya (kolom lokalnya di join lain).
     *
     * @param  array<string, CompiledJoin>  $joins
     * @param  array<string, true>  $needed
     */
    private function require(?string $alias, array $joins, array &$needed): void
    {
        if ($alias === null || ! isset($joins[$alias]) || isset($needed[$alias])) {
            return;
        }

        $needed[$alias] = true;
        $this->require($this->aliasOf($joins[$alias]->localColumn), $joins, $needed);
    }

    /** Awalan kolom berkualifikasi: nama tabel dasar atau alias join. */
    private function aliasOf(string $column): ?string
    {
        return str_contains($column, '.') ? explode('.', $column, 2)[0] : null;
    }
}
