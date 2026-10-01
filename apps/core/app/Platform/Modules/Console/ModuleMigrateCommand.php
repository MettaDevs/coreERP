<?php

declare(strict_types=1);

namespace App\Platform\Modules\Console;

use App\Platform\Modules\Support\ModuleMigrator;
use App\Platform\Modules\Support\ModuleRegistry;
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

        if (! is_dir($module->migrationFolder())) {
            $this->warn(sprintf('Module "%s" tidak punya folder migration. Tidak ada yang dijalankan.', $id));

            return self::SUCCESS;
        }

        $connection = $this->option('connection');
        $newMigrations = $migrator->naik($module, is_string($connection) && $connection !== '' ? $connection : null);

        if ($newMigrations === []) {
            $this->info(sprintf('Module "%s" sudah mutakhir; tidak ada migration yang dijalankan.', $id));

            return self::SUCCESS;
        }

        foreach ($newMigrations as $migration) {
            $this->line('  dijalankan: '.$migration);
        }

        $this->info(sprintf('%d migration module "%s" dijalankan.', count($newMigrations), $id));

        return self::SUCCESS;
    }
}
