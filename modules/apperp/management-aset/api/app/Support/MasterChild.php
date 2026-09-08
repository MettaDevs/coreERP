<?php

namespace App\Support;

/**
 * Master yang menunjuk resource ini. Dipakai agar arsip tidak memutus referensi yang masih aktif.
 */
final readonly class MasterChild
{
    public function __construct(
        public string $table,
        public string $column,
        public string $label,
    ) {}
}
