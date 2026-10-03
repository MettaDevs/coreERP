<?php

declare(strict_types=1);

namespace App\Foundation\Currency\Support;

use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\Analytics\SharedDimensionResolver;
use App\Platform\Modules\Contracts\DataClass;

/**
 * Label mata uang untuk dimensi bersama analitik: kode ISO 4217 itu sendiri ("IDR", "USD"), karena
 * master mata uang dengan nama dan simbol belum dibangun (FIN-20). Nilai yang bukan tiga huruf besar
 * tidak berlabel, sehingga data rusak tampil apa adanya, bukan menyamar sebagai mata uang.
 */
final class CurrencyLabels implements SharedDimensionResolver
{
    public function dimension(): SharedDimension
    {
        return SharedDimension::Currency;
    }

    public function labelClassification(): DataClass
    {
        return DataClass::CustomerContent;
    }

    public function labels(string $tenantId, array $ids): array
    {
        $labels = [];
        foreach ($ids as $code) {
            if (preg_match('/^[A-Z]{3}$/', $code) === 1) {
                $labels[$code] = $code;
            }
        }

        return $labels;
    }
}
