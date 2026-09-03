<?php

namespace Database\Seeders;

use App\Actions\ReferenceData\ProvisionDefaultUnitsOfMeasure;
use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ProviderAdminSeeder::class,
            NumberSequenceProfileSeeder::class,
            WorldCountriesSeeder::class,
            IndonesianAddressHierarchySeeder::class,
            WorldProvincesSeeder::class,
            WorldCitiesSeeder::class,
            TimeZonesSeeder::class,
        ]);
        Tenant::query()->pluck('id')->each(fn (string $tenantId) => app(ProvisionDefaultUnitsOfMeasure::class)->forTenant($tenantId));
    }
}
