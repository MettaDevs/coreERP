<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use Illuminate\Database\Eloquent\Model;

/**
 * Sumber label sebuah field rujukan ke master module yang sama. Kolomnya sudah berkualifikasi dengan
 * alias join label (`r0`, `r1`, …), yang dipilih engine dan tidak pernah dipakai join dataset.
 *
 * Join label dipasang compiler (area 3) sebagai `LEFT JOIN <table> AS <alias> ON <foreignColumn> =
 * <localColumn> AND <alias>.tenant_id = <tabel dasar>.tenant_id`, **tanpa** saringan arsip: master yang
 * sudah diarsipkan tetap bernama di data lama.
 */
final readonly class CompiledReference
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $key,
        public string $model,
        public string $table,
        public string $alias,
        public string $localColumn,
        public string $foreignColumn,
        public string $labelColumn,
        public ?string $codeColumn,
    ) {}
}
