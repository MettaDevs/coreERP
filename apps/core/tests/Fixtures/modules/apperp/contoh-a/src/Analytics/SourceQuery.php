<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Analytics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Titik awal query sumber fixture dengan scope model yang tetap terpasang. */
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
