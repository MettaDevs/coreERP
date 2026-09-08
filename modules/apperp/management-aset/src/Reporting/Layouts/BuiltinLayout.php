<?php

namespace Modules\Apperp\ManagementAset\Reporting\Layouts;

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
        // Jalur dihitung dari folder module, bukan dari `resource_path()`.
        //
        // `resource_path()` menunjuk `resources/` milik **Core**, dan sejak F3-03 layout
        // bawaan module berada di `resources/laporan/` milik module. Selama masih memakai
        // `resource_path()`, unduhan layout menjawab 404 dengan pesan "berkas tidak ada pada
        // release ini" — pesan yang menyalahkan release, bukan jalurnya.
        return dirname(__DIR__, 3)."/resources/laporan/{$reportCode}/{$this->key}.{$this->format}";
    }
}
