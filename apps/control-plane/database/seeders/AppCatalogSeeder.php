<?php

namespace Database\Seeders;

use App\Actions\Provider\RegisterAppCatalog;
use Illuminate\Database\Seeder;

/**
 * Mendaftarkan katalog app dari definisi `coreerp.app_catalog`.
 *
 * Seeder ini memakai jalur pendaftaran yang sama dengan API provider
 * (RegisterAppCatalog) supaya tidak ada dua implementasi ingestion yang bisa
 * saling menyimpang.
 */
class AppCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(RegisterAppCatalog::class);

        foreach (config('coreerp.app_catalog', []) as $definition) {
            $registrar->handle(
                [
                    'id' => $definition['id'],
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'version' => $definition['version'],
                    'database_name' => $definition['database'],
                    'has_ui' => (bool) ($definition['has_ui'] ?? false),
                    'navigation' => $definition['navigation'] ?? null,
                    'repository_url' => $definition['repository_url'] ?? null,
                    'contract_url' => $definition['contract_url'] ?? null,
                    'status' => $definition['status'],
                ],
                [
                    'entry_points' => $definition['entry_points'],
                    'permissions' => $definition['permissions'],
                    'privileges' => $definition['privileges'],
                    'duties' => $definition['duties'],
                ],
                $definition['number_sequences']['references'] ?? [],
                [],
                [],
                $definition['dependsOn'] ?? [],
            );
        }
    }
}
