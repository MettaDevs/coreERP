<?php

declare(strict_types=1);

namespace App\Platform\Reporting\ModuleServices;

use App\Platform\Reporting\Support\ValueFormats;
use App\Support\Modules\Contracts\ReportFormatter;

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
