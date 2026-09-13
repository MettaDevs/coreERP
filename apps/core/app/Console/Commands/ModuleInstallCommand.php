<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\InstallModule;
use App\Models\Environment;
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
    protected $signature = 'module:install {module : Id module} {tenant : Id tenant}'
        .' {--environment= : Id lingkungan tujuan; kosong berarti lingkungan produksi tenant itu}';

    protected $description = 'Pasang sebuah module untuk satu tenant';

    public function handle(InstallModule $action): int
    {
        try {
            $installation = $action->handle(
                (string) $this->argument('module'),
                (string) $this->argument('tenant'),
                $this->environment(),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Module "%s" terpasang untuk tenant %s.', $installation->module_id, $installation->tenant_id));

        return self::SUCCESS;
    }

    /**
     * Lingkungan tujuan, atau null bila operator tidak menyebutnya.
     *
     * Tidak menyebutnya berarti lingkungan produksi tenant itu, dan aksinya yang memutuskan itu —
     * bukan perintah ini. Sebuah id yang disebut tetapi tidak ada dijawab galat, bukan diam-diam
     * jatuh ke produksi: operator yang salah ketik id demo tidak boleh berakhir memasang module di
     * tempat kerja pelanggan yang sebenarnya.
     */
    private function environment(): ?Environment
    {
        $id = $this->option('environment');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $environment = Environment::query()->whereKey($id)->whereNull('deleted_at')->first();

        if (! $environment instanceof Environment) {
            throw new RuntimeException(sprintf('Lingkungan "%s" tidak ada di registry.', $id));
        }

        return $environment;
    }
}
