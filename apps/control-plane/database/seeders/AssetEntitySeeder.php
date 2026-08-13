<?php

namespace Database\Seeders;

use App\Models\AssetEntity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AssetEntitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AssetEntity::query()->firstOrCreate(
            ['code' => 'E001'],
            [
                'id' => (string) Str::ulid(),
                'tenant_id' => 'default-tenant',
                'name' => 'PT. Sanata System',
                'description' => 'Entitas Induk',
                'status' => true,
            ]
        );
    }
}
