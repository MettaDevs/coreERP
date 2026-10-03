<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use Modules\Apperp\ContohA\Models\Barang;

/**
 * Dataset tanpa kebijakan data bahan uji engine analitik: permission baca barang tidak dilindungi
 * kebijakan apa pun di manifest, jadi dataset ini memang tidak menyatakan `dataPolicy()`.
 */
final class BarangDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'contoh-a';
    }

    public function definition(): DatasetDefinition
    {
        return DatasetDefinition::make('contoh-a.barang', 'Barang')
            ->model(Barang::class)
            ->permission('contoh-a.barang.read')
            ->fieldsFromModel()
            ->measure('count', 'Jumlah barang', Aggregate::Count)
            ->measure('bawaan', 'Barang bawaan', Aggregate::Count, where: ['bawaan' => true]);
    }
}
