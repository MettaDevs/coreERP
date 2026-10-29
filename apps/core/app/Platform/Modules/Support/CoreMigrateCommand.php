<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use Illuminate\Database\Console\Migrations\MigrateCommand;

final class CoreMigrateCommand extends MigrateCommand
{
    public function handle(): int
    {
        if ($this->option('path')) {
            $paths = $this->getMigrationPaths();
            $paths = array_merge($paths, $this->migrator->getMigrationFiles($paths));

            foreach ($this->laravel->make(ModuleRegistry::class)->allIncludingMoved() as $module) {
                $folder = $this->resolvedPath($module->migrationFolder());
                if ($folder === null) {
                    continue;
                }

                foreach ($paths as $path) {
                    $resolved = $this->resolvedPath($path);
                    if ($resolved !== null && ($resolved === $folder || str_starts_with($resolved, $folder.'/'))) {
                        $this->components->error(sprintf(
                            'Migration module "%s" tidak boleh dijalankan lewat migrate --path. Gunakan php artisan module:migrate %s agar riwayatnya tercatat pada module.',
                            $module->id,
                            $module->id,
                        ));

                        return self::FAILURE;
                    }
                }
            }
        }

        return parent::handle();
    }

    private function resolvedPath(string $path): ?string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            return null;
        }

        return str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved);
    }
}
