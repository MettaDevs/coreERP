<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\DisableModule;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Menonaktifkan module untuk satu tenant.
 *
 * Data tidak disentuh sama sekali. Menu module hilang karena shell hanya membaca module
 * berstatus terpasang, bukan karena datanya hilang.
 */
final class ModuleDisableCommand extends Command
{
    protected $signature = 'module:disable {module : Id module} {tenant : Id tenant}';

    protected $description = 'Nonaktifkan module untuk satu tenant tanpa menyentuh datanya';

    public function handle(DisableModule $aksi): int
    {
        try {
            $pemasangan = $aksi->handle((string) $this->argument('module'), (string) $this->argument('tenant'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Module "%s" dinonaktifkan untuk tenant %s. Datanya tidak disentuh.', $pemasangan->module_id, $pemasangan->tenant_id));

        return self::SUCCESS;
    }
}
