<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Schema\Blueprint;

/**
 * Riwayat migration yang hanya melihat migration milik satu module.
 *
 * Penyaringan `module_id` bukan kerapian. Tanpanya, migration module A akan terlihat sudah
 * jalan saat module B dipasang bila nama berkasnya kebetulan sama — dan nama seperti
 * `2026_09_08_000100_create_m_barang_table` memang mudah bertabrakan antar module yang
 * ditulis orang berbeda.
 */
final class ModuleMigrationRepository extends DatabaseMigrationRepository
{
    public function __construct(
        DatabaseManager $resolver,
        string $table,
        private readonly string $moduleId,
    ) {
        parent::__construct($resolver, $table);
    }

    /** @return list<string> */
    public function getRan(): array
    {
        /** @var list<string> $hasil */
        $hasil = $this->table()
            ->where('module_id', $this->moduleId)
            ->orderBy('batch')
            ->orderBy('migration')
            ->pluck('migration')
            ->all();

        return $hasil;
    }

    /**
     * @param  string  $file
     * @param  int  $batch
     */
    public function log($file, $batch): void
    {
        $this->table()->insert([
            'module_id' => $this->moduleId,
            'migration' => $file,
            'batch' => $batch,
        ]);
    }

    /** @param  object{migration: string}  $migration */
    public function delete($migration): void
    {
        $this->table()
            ->where('module_id', $this->moduleId)
            ->where('migration', $migration->migration)
            ->delete();
    }

    public function getLastBatchNumber(): int
    {
        return (int) $this->table()->where('module_id', $this->moduleId)->max('batch');
    }

    /**
     * @param  int  $steps
     * @return array<int, object>
     */
    public function getMigrations($steps): array
    {
        return $this->table()
            ->where('module_id', $this->moduleId)
            ->where('batch', '>=', 1)
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->take($steps)
            ->get()
            ->all();
    }

    public function createRepository(): void
    {
        $this->getConnection()->getSchemaBuilder()->create($this->table, function (Blueprint $table): void {
            $table->increments('id');
            $table->string('module_id', 100);
            $table->string('migration');
            $table->integer('batch');

            // Sepasang module dan nama migration hanya boleh tercatat sekali. Ini yang
            // membuat menjalankan perintah dua kali tidak mengulang apa pun.
            $table->unique(['module_id', 'migration']);
        });
    }
}
