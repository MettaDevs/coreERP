<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;

/**
 * Satu measure dataset sesudah dibaca registry. `field`, `currency`, dan `unit` adalah kunci field, nama
 * kolom tabel dasar, atau `alias.kolom` join — semuanya sudah diperiksa ada di tabelnya, dan diubah
 * menjadi kolom berkualifikasi lewat {@see CompiledDataset::qualified()}. `where` saringan tetapnya,
 * berkunci field pilihan, ya/tidak, atau rujukan, apa adanya dari definisi.
 */
final readonly class CompiledMeasure
{
    /**
     * @param  array<string, list<string|int|bool|null>|string|int|bool|null>  $where
     */
    public function __construct(
        public string $key,
        public string $caption,
        public Aggregate $aggregate,
        public ?string $field,
        public MeasureFormat $format,
        public ?string $currency,
        public ?string $unit,
        public array $where,
    ) {}
}
