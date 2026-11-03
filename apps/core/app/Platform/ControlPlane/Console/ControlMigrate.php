<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Console;

use App\Platform\Database\Support\DatabasePrivileges;
use App\Platform\Environment\Support\EnvironmentConnection;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

final class ControlMigrate extends Command
{
    use ConfirmableTrait;

    public const HISTORY = 'control_plane_migrations';

    protected $signature = 'control:migrate {--force} {--pretend} {--step}';

    protected $description = 'Jalankan schema control dengan history dan role migration tersendiri';

    public function handle(EnvironmentConnection $connections, DatabasePrivileges $privileges): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }
        $migration = $connections->controlMigration();
        $runtime = $connections->controlPlane();
        /** @var list<string> $paths */
        $paths = require database_path('control-plane/manifest.php');
        $repository = new DatabaseMigrationRepository($this->laravel->make('db'), self::HISTORY);
        $migrator = new Migrator($repository, $this->laravel->make('db'), $this->laravel->make('files'), $this->laravel->make('events'));
        $repository->setSource($migration);
        $migrator->setOutput($this->output);
        $database = DB::connection($migration);
        $database->select('select pg_advisory_lock(hashtextextended(?, 0))', ['control-plane-migrations']);

        try {
            if ($this->option('pretend') && ! $repository->repositoryExists()) {
                $this->components->error('History control belum tersedia. Periksa baseline SQL sebelum bootstrap pertama.');

                return self::FAILURE;
            }
            if (! $this->option('pretend')) {
                $connections->createSchemas($migration);
                $privileges->prepare($migration, $runtime);
                $database->transaction(function () use ($repository, $privileges, $migration, $runtime): void {
                    if (! $repository->repositoryExists()) {
                        $repository->createRepository();
                    }
                    $privileges->protectHistory($migration, $runtime, self::HISTORY);
                });
            }

            $migrator->usingConnection($migration, fn () => $migrator->run($paths, [
                'pretend' => (bool) $this->option('pretend'),
                'step' => (bool) $this->option('step'),
            ]));

            return self::SUCCESS;
        } finally {
            $database->select('select pg_advisory_unlock(hashtextextended(?, 0))', ['control-plane-migrations']);
        }
    }
}
