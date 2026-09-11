<?php

namespace App\Console\Commands;

use App\Support\Reporting\ExportQueue;
use Illuminate\Console\Command;

/**
 * Menghapus hasil ekspor yang lewat masa simpan. Dijadwalkan tiap jam; daftar ekspor
 * juga membersihkan milik tenantnya sendiri saat dibaca, jadi tanpa scheduler pun
 * penyimpanan tidak tumbuh tanpa batas — hanya lebih lambat susutnya.
 */
class PurgeReportExports extends Command
{
    protected $signature = 'reporting:purge-exports';

    protected $description = 'Hapus hasil ekspor laporan yang sudah lewat masa simpan.';

    public function handle(ExportQueue $exports): int
    {
        $total = 0;
        do {
            $removed = $exports->purgeExpired();
            $total += $removed;
        } while ($removed > 0);
        $this->info("{$total} ekspor kedaluwarsa dihapus.");

        return self::SUCCESS;
    }
}
