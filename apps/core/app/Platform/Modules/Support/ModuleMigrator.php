<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use App\Platform\ChangeLog\Support\ChangeLogSwitch;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;

/**
 * Menjalankan migration sebuah module, dengan riwayat yang terpisah dari milik Core.
 *
 * Riwayatnya tidak boleh menumpang tabel `migrations` bawaan. Kalau menumpang, mencabut
 * sebuah module lalu memasangnya lagi akan melewati semua migration-nya — riwayatnya masih
 * tercatat — dan tabelnya tidak pernah dibuat ulang.
 *
 * Laravel tidak perlu ditambal untuk ini: kelas repositori migration-nya menerima nama tabel
 * pada konstruktornya.
 */
final class ModuleMigrator
{
    public const HISTORY_TABLE = 'core_module_migrations';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Filesystem $file,
    ) {}

    /**
     * Jalankan migration yang belum pernah jalan untuk module ini.
     *
     * @return list<string> nama migration yang baru saja dijalankan; kosong berarti sudah mutakhir
     */
    public function migrate(ModuleManifest $module, ?string $connection = null): array
    {
        $migrator = $this->migrator($module, $connection);

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $before = $migrator->getRepository()->getRan();
        // Migrator ini dibuat tanpa dispatcher, jadi event migrasi yang mematikan log di migrate Core tidak
        // menyala di sini; log perubahan dimatikan langsung.
        ChangeLogSwitch::pausedOn($connection, fn () => $migrator->run([$module->migrationFolder()]));
        $after = $migrator->getRepository()->getRan();

        return array_values(array_diff($after, $before));
    }

    /**
     * Nama migration yang sudah tercatat untuk module ini.
     *
     * @return list<string>
     */
    public function ran(ModuleManifest $module, ?string $connection = null): array
    {
        $migrator = $this->migrator($module, $connection);

        if (! $migrator->repositoryExists()) {
            return [];
        }

        /** @var list<string> $ran */
        $ran = $migrator->getRepository()->getRan();

        return $ran;
    }

    private function migrator(ModuleManifest $module, ?string $connection): Migrator
    {
        $migrator = new Migrator(
            new ModuleMigrationRepository($this->database, self::HISTORY_TABLE, $module->id),
            $this->database,
            $this->file,
        );

        if ($connection !== null) {
            $migrator->setConnection($connection);
        }

        return $migrator;
    }
}
