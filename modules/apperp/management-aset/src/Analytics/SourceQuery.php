<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Titik awal query sumber dataset analitik: query Eloquent dari model module, dengan scope tenant dan
 * penanda arsip model terpasang, sama seperti `Model::query()`.
 *
 * Kontrak `DatasetDefinition::fromQuery()` meminta `Builder<Model>`, sedangkan `Model::query()` bertipe
 * `Builder<ModelKonkret>` dan analisa tipe menolaknya (parameter template `Builder` tidak kovarian).
 * Menyusunnya dari `Model` yang bertipe dasar menghasilkan tipe yang diminta tanpa menekan analisa.
 */
final class SourceQuery
{
    /**
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    public static function from(string $model): Builder
    {
        return (new $model)->newQuery();
    }
}
