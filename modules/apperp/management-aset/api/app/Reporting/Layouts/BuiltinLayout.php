<?php

namespace App\Reporting\Layouts;

/**
 * Layout yang ikut release app, setara "extension layout" Business Central: dapat
 * dipakai dan disalin, tetapi tidak dapat diubah atau dihapus tenant. Berkasnya ada di
 * `resources/laporan/<kode laporan>/<kunci>.<format>`.
 */
final class BuiltinLayout
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly string $format,
    ) {}

    public function ref(): string
    {
        return LayoutRef::BUILTIN_PREFIX.$this->key;
    }

    public function path(string $reportCode): string
    {
        return resource_path("laporan/{$reportCode}/{$this->key}.{$this->format}");
    }
}
