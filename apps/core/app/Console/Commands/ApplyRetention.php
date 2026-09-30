<?php

namespace App\Console\Commands;

use App\Support\Retention\RetentionPolicies;
use App\Support\Retention\RetentionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Menerapkan kebijakan retensi data log untuk semua tenant. Dijadwalkan harian; hasil per tenant dicatat di
 * `retention_policy_log_entries` dan tampil di Pengaturan → Retensi data.
 */
class ApplyRetention extends Command
{
    protected $signature = 'retention:apply {policy? : Kode satu kebijakan; kosong berarti semua}';

    protected $description = 'Hapus data log yang sudah lewat masa simpan sesuai setelan tiap tenant.';

    public function handle(RetentionService $retention): int
    {
        $policy = $this->argument('policy');

        try {
            $total = $retention->apply(is_string($policy) ? $policy : null);
        } catch (InvalidArgumentException) {
            $codes = implode(', ', array_map(fn ($policy): string => $policy->code, RetentionPolicies::all()));
            $this->error("Kebijakan tidak dikenal. Pilihan: {$codes}.");

            return self::FAILURE;
        }

        $this->info("{$total} baris data log dihapus.");

        return self::SUCCESS;
    }
}
