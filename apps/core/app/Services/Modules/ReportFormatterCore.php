<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Support\Modules\Contracts\ReportFormatter;
use App\Support\Reporting\ValueFormats;

/**
 * Meneruskan pemformatan pratinjau laporan module ke aturan yang sama dengan renderer Core.
 *
 * Pembungkusnya ada supaya module bergantung pada antarmuka, bukan pada kelas Core yang bebas
 * berubah bentuk; aturannya tetap satu, di `ValueFormat`.
 */
final class ReportFormatterCore implements ReportFormatter
{
    public function __construct(private readonly ValueFormats $formats) {}

    public function display(string $tenantId, array $fields, array $dataset, string $timezone): array
    {
        return $this->formats->display($tenantId, $fields, $dataset, $timezone);
    }
}
