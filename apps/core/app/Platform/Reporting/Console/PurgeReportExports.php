<?php

namespace App\Platform\Reporting\Console;

use App\Platform\Retention\Support\RetentionService;
use Illuminate\Console\Command;

/**
 * Menghapus hasil ekspor yang lewat masa simpan lewat layanan retensi, khusus kebijakan `report_exports`.
 * Dijadwalkan tiap jam, lebih sering daripada `retention:apply`; daftar ekspor juga membersihkan milik
 * tenantnya sendiri saat dibaca, jadi tanpa scheduler pun penyimpanan tidak tumbuh tanpa batas — hanya
 * lebih lambat susutnya.
 */
class PurgeReportExports extends Command
{
    protected $signature = 'reporting:purge-exports';

    protected $description = 'Hapus hasil ekspor laporan yang sudah lewat masa simpan.';

    public function handle(RetentionService $retention): int
    {
        $total = $retention->apply('report_exports');
        $this->info("{$total} ekspor kedaluwarsa dihapus.");

        return self::SUCCESS;
    }
}
