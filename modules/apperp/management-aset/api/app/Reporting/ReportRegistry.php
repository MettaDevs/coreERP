<?php

namespace App\Reporting;

use InvalidArgumentException;

/**
 * Daftar laporan yang dikenal app ini. Diisi saat boot dari service provider; kode
 * laporan adalah kontrak yang dipakai URL, layout tersimpan, dan riwayat ekspor, jadi
 * mengganti kode berarti memutus layout yang sudah diunggah tenant.
 */
final class ReportRegistry
{
    /** @var array<string, ReportDefinition> */
    private array $definitions = [];

    public function register(ReportDefinition $definition): void
    {
        $this->definitions[$definition->code()] = $definition;
    }

    /** @return list<ReportDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function has(string $code): bool
    {
        return isset($this->definitions[$code]);
    }

    public function get(string $code): ReportDefinition
    {
        return $this->definitions[$code]
            ?? throw new InvalidArgumentException("Laporan `{$code}` tidak dikenal.");
    }
}
