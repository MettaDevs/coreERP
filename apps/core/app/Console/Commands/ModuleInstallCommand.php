<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\InstallModule;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Memasang module untuk satu tenant.
 *
 * Aman dijalankan dua kali: memasang module yang sudah terpasang mengembalikan statusnya
 * tanpa menyentuh data dan tanpa mengisi ulang data awal. Sifat itu dibutuhkan admin
 * pelanggan yang menjalankan pembaruan on-prem dengan tangan.
 */
final class ModuleInstallCommand extends Command
{
    protected $signature = 'module:install {module : Id module} {tenant : Id tenant}';

    protected $description = 'Pasang sebuah module untuk satu tenant';

    public function handle(InstallModule $aksi): int
    {
        try {
            $pemasangan = $aksi->handle((string) $this->argument('module'), (string) $this->argument('tenant'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Module "%s" terpasang untuk tenant %s.', $pemasangan->module_id, $pemasangan->tenant_id));

        return self::SUCCESS;
    }
}
