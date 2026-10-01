<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/**
 * Satu kolom yang boleh difilter pengguna (K-30), sebagaimana dikenal katalog laporan.
 *
 * `column` adalah kolom SQL berkualifikasi (mis. `aset.nilai_perolehan`) dan selalu datang dari katalog,
 * tidak pernah dari masukan pengguna. `options` (nilai => label) hanya dipakai jenis {@see FieldType::Option};
 * `lookup` menamai resource pemilih untuk jenis {@see FieldType::Reference}.
 */
final readonly class FilterField
{
    /**
     * @param  array<string, string>  $options
     */
    public function __construct(
        public string $key,
        public string $caption,
        public FieldType $type,
        public string $column,
        public array $options = [],
        public ?string $lookup = null,
    ) {}
}
