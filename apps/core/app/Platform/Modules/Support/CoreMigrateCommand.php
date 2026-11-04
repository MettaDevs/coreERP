<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use App\Platform\Database\Support\DatabasePrivileges;
use App\Platform\Environment\Support\EnvironmentConnection;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CoreMigrateCommand extends MigrateCommand
{
    public function handle(): int
    {
        $connections = $this->laravel->make(EnvironmentConnection::class);
        $runtime = (string) ($this->option('database') ?: $connections->business());
        try {
            $migration = $connections->migrationFor($runtime);
        } catch (RuntimeException $failure) {
            $this->components->error($failure->getMessage());

            return self::FAILURE;
        }

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

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }
        $this->input->setOption('force', true);
        $this->input->setOption('database', $migration);
        try {
            if (! $this->option('pretend') && $migration !== $runtime) {
                $privileges = $this->laravel->make(DatabasePrivileges::class);
                $privileges->prepare($migration, $runtime);
                $repository = $this->migrator->getRepository();
                $repository->setSource($migration);
                DB::connection($migration)->transaction(function () use ($repository, $privileges, $migration, $runtime): void {
                    if (! $repository->repositoryExists()) {
                        $repository->createRepository();
                    }
                    $privileges->protectHistory($migration, $runtime, 'migrations');
                });
            }

            return parent::handle();
        } finally {
            $connections->releaseMigrationFor($runtime);
        }
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
