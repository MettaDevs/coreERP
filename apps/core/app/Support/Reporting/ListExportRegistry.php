<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Support\Modules\Contracts\ListExportSource;
use App\Support\Modules\Contracts\ListExportSources;

/** Daftar layar yang boleh diekspor, diisi penyedia layanan tiap module saat boot. */
final class ListExportRegistry implements ListExportSources
{
    /** @var array<string, ListExportSource> */
    private array $sources = [];

    public function register(ListExportSource $source): void
    {
        $this->sources[self::code($source->moduleId(), $source->listCode())] = $source;
    }

    public function find(string $moduleId, string $listCode): ?ListExportSource
    {
        return $this->sources[self::code($moduleId, $listCode)] ?? null;
    }

    /** Kode daftar pada riwayat ekspor: `<id module>.<kode daftar>`, bentuk yang sama dengan kode laporan. */
    public static function code(string $moduleId, string $listCode): string
    {
        return $moduleId.'.'.$listCode;
    }
}
