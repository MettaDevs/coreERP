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
            AssetEntitySeeder::class,
        ]);

        $manifestPath = '/workspace/manifests/management-aset.app.yaml';
        if (! \App\Models\CoreApp::where('id', 'management-aset')->exists() && file_exists($manifestPath)) {
            \Illuminate\Support\Facades\Artisan::call('app:register-manifest', ['path' => $manifestPath]);
        }

        Tenant::query()->pluck('id')->each(fn (string $tenantId) => app(ProvisionDefaultUnitsOfMeasure::class)->forTenant($tenantId));
    }
}
