<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Modules\ModuleMigrator;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Menjalankan migration satu module.
 *
 * Opsi tenant punya arti berbeda pada dua bentuk penempatan, dan itu perlu dinyatakan
 * supaya tidak ada yang menyangka ia membuat tabel per tenant:
 *
 * - Penempatan gabungan, yaitu bawaan: tabelnya sudah ada untuk semua tenant, karena semua
 *   tenant memakai tabel yang sama dan dipisahkan `tenant_id`. Opsi tenant di sini hanya
 *   menandai untuk siapa pemasangan itu dicatat.
 * - Penempatan terpisah: tiap tenant punya database sendiri, jadi migration benar-benar
 *   dijalankan di database tenant itu.
 */
final class ModuleMigrateCommand extends Command
{
    protected $signature = 'module:migrate {module : Id module pada app.yaml} {--tenant= : Tenant yang dicatat, atau database tenant pada penempatan terpisah} {--connection= : Koneksi database yang dipakai}';

    protected $description = 'Jalankan migration sebuah module dengan riwayat terpisah dari milik Core';

    public function handle(ModuleRegistry $registry, ModuleMigrator $migrator): int
    {
        $id = (string) $this->argument('module');
        $module = $registry->cari($id);

        if ($module === null) {
            $this->error(sprintf('Module "%s" tidak ditemukan. Jalankan `module:list` untuk melihat yang terbaca runtime.', $id));

            return self::FAILURE;
        }

        if (! is_dir($module->folderMigrasi())) {
            $this->warn(sprintf('Module "%s" tidak punya folder migration. Tidak ada yang dijalankan.', $id));

            return self::SUCCESS;
        }

        $koneksi = $this->option('connection');
        $baru = $migrator->naik($module, is_string($koneksi) && $koneksi !== '' ? $koneksi : null);

        if ($baru === []) {
            $this->info(sprintf('Module "%s" sudah mutakhir; tidak ada migration yang dijalankan.', $id));

            return self::SUCCESS;
        }

        foreach ($baru as $migration) {
            $this->line('  dijalankan: '.$migration);
        }

        $this->info(sprintf('%d migration module "%s" dijalankan.', count($baru), $id));

        return self::SUCCESS;
    }
}
