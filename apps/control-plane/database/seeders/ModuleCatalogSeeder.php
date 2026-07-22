<?php

namespace Database\Seeders;

use App\Models\CoreModule;
use App\Models\Permission;
use App\Models\SecurityDuty;
use App\Models\SecurityPrivilege;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModuleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('coreerp.module_catalog') as $definition) {
            CoreModule::query()->updateOrCreate(
                ['id' => $definition['id']],
                [
                    'name' => $definition['name'],
                    'version' => $definition['version'],
                    'status' => $definition['status'],
                    'database_name' => $definition['database'],
                    'description' => $definition['description'],
                ],
            );

            foreach ($definition['permissions'] as $code => $name) {
                DB::table('module_entry_points')->updateOrInsert(
                    ['code' => $code],
                    [
                        'module_id' => $definition['id'],
                        'name' => $name,
                        'type' => str_ends_with($code, '.read') ? 'form' : 'action',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                Permission::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'module_id' => $definition['id'],
                        'entry_point_code' => $code,
                        'access_level' => str($code)->afterLast('.')->toString(),
                        'name' => $name,
                    ],
                );
                $privilege = SecurityPrivilege::query()->updateOrCreate(
                    ['code' => $code],
                    ['module_id' => $definition['id'], 'name' => $name],
                );
                $privilege->permissions()->sync([$code]);
            }

            foreach ($definition['duties'] as $code => $dutyDefinition) {
                $duty = SecurityDuty::query()->updateOrCreate(
                    ['code' => $code],
                    ['module_id' => $definition['id'], 'name' => $dutyDefinition['name']],
                );
                $duty->privileges()->sync($dutyDefinition['permissions']);
            }
        }
    }
}
