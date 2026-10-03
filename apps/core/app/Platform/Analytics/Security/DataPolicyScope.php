<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Modules\Contracts\DataPolicyFilter;
use App\Platform\Modules\Contracts\FieldFilterExpression;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Langkah 6 dan sebagian langkah 7 urutan otorisasi (`docs/todo/analitik/keamanan.md`): jangkauan
 * principal dipasang pada query **sebelum** saringan pengguna, sehingga saringan pengguna hanya dapat
 * menyempitkan.
 *
 * 1. Kebijakan data dataset lewat `DataPolicyFilter`, aturan yang sama dengan layar module, pada kolom
 *    yang dinyatakan module — tidak pernah kolom tebakan Core. Tanpa hibah, nol baris.
 * 2. Saringan terkunci principal (publikasi, embed) lewat `FieldFilterExpression`, sama dengan saringan
 *    pengguna, tetapi tidak dapat dilepas: ia dipasang di sini, bukan dibaca dari query.
 *
 * Gagal tertutup untuk saringan terkunci: nilai kosong dan field yang tidak (lagi) dikenal dataset berarti
 * **nol baris**. `FieldFilterExpression` sendiri membaca nilai kosong sebagai "tanpa saringan", jadi tanpa
 * pemeriksaan ini daftar nilai `[]` diam-diam melepas saringannya. Ekspresi yang tidak terbaca dibiarkan
 * naik sebagai `InvalidFilterExpression`: saringan terkunci disimpan pembuat publikasi, bukan diketik
 * pemanggil, jadi ia cacat konfigurasi yang harus terlihat, bukan galat isian.
 */
final class DataPolicyScope
{
    public function apply(Builder $builder, CompiledDataset $dataset, AnalyticsPrincipal $principal): void
    {
        if ($dataset->policy !== null) {
            DataPolicyFilter::apply(
                $builder,
                $principal->policyScope($dataset->policy['code']),
                $dataset->qualified($dataset->policy['legal_entity']),
                $dataset->policy['operating_unit'] === null ? null : $dataset->qualified($dataset->policy['operating_unit']),
            );
        }

        foreach ($principal->lockedFilters($dataset->code) as $key => $value) {
            if (! $dataset->hasField($key) || self::isEmpty($value)) {
                $builder->whereRaw('1 = 0');

                continue;
            }

            FieldFilterExpression::apply($builder, $dataset->filterField($key), $value, $principal->timezone());
        }
    }

    /** @param string|list<string> $value */
    private static function isEmpty(string|array $value): bool
    {
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (trim($item) !== '') {
                return false;
            }
        }

        return true;
    }
}
