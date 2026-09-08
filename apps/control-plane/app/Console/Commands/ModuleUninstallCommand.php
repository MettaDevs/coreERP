<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\UninstallModule;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Mencabut module dari satu tenant.
 *
 * Perintah ini sengaja TIDAK memiliki opsi penghapusan data, dan tidak boleh diberi satu
 * pun. Menambahkannya berarti melanggar keputusan tertulis pada bagian 5.7 PRD, bukan
 * menambah fitur.
 */
final class ModuleUninstallCommand extends Command
{
    protected $signature = 'module:uninstall {module : Id module} {tenant : Id tenant}';

    protected $description = 'Cabut module dari satu tenant; datanya tetap tersimpan';

    public function handle(UninstallModule $aksi): int
    {
        try {
            $pemasangan = $aksi->handle((string) $this->argument('module'), (string) $this->argument('tenant'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Module "%s" dicabut dari tenant %s. Datanya tetap tersimpan.', $pemasangan->module_id, $pemasangan->tenant_id));

        return self::SUCCESS;
    }
}
