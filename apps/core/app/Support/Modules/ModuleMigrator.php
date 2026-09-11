<?php

declare(strict_types=1);

namespace App\Support\Modules;

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
    public const TABEL_RIWAYAT = 'core_module_migrations';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Filesystem $berkas,
    ) {}

    /**
     * Jalankan migration yang belum pernah jalan untuk module ini.
     *
     * @return list<string> nama migration yang baru saja dijalankan; kosong berarti sudah mutakhir
     */
    public function naik(ModuleManifest $module, ?string $koneksi = null): array
    {
        $migrator = $this->migrator($module, $koneksi);

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $sebelum = $migrator->getRepository()->getRan();
        $migrator->run([$module->folderMigrasi()]);
        $sesudah = $migrator->getRepository()->getRan();

        return array_values(array_diff($sesudah, $sebelum));
    }

    /**
     * Nama migration yang sudah tercatat untuk module ini.
     *
     * @return list<string>
     */
    public function sudahJalan(ModuleManifest $module, ?string $koneksi = null): array
    {
        $migrator = $this->migrator($module, $koneksi);

        if (! $migrator->repositoryExists()) {
            return [];
        }

        /** @var list<string> $jalan */
        $jalan = $migrator->getRepository()->getRan();

        return $jalan;
    }

    private function migrator(ModuleManifest $module, ?string $koneksi): Migrator
    {
        $migrator = new Migrator(
            new ModuleMigrationRepository($this->database, self::TABEL_RIWAYAT, $module->id),
            $this->database,
            $this->berkas,
        );

        if ($koneksi !== null) {
            $migrator->setConnection($koneksi);
        }

        return $migrator;
    }
}
