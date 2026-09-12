<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\UninstallModule;
use App\Models\Environment;
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
    protected $signature = 'module:uninstall {module : Id module} {tenant : Id tenant}'
        .' {--lingkungan= : Id lingkungan tujuan; kosong berarti lingkungan produksi tenant itu}';

    protected $description = 'Cabut module dari satu tenant; datanya tetap tersimpan';

    public function handle(UninstallModule $aksi): int
    {
        try {
            $pemasangan = $aksi->handle(
                (string) $this->argument('module'),
                (string) $this->argument('tenant'),
                $this->lingkungan(),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Module "%s" dicabut dari tenant %s. Datanya tetap tersimpan.', $pemasangan->module_id, $pemasangan->tenant_id));

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
    private function lingkungan(): ?Environment
    {
        $id = $this->option('lingkungan');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $lingkungan = Environment::query()->whereKey($id)->whereNull('deleted_at')->first();

        if (! $lingkungan instanceof Environment) {
            throw new RuntimeException(sprintf('Lingkungan "%s" tidak ada di registry.', $id));
        }

        return $lingkungan;
    }
}
