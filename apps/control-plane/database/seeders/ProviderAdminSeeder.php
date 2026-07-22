<?php

namespace Database\Seeders;

use App\Models\ProviderAccess;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProviderAdminSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('coreerp.provider.password');
        if (! is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('COREERP_PROVIDER_PASSWORD must be set to at least 16 characters before seeding.');
        }

        $user = User::query()->updateOrCreate(
            ['email' => config('coreerp.provider.email')],
            ['name' => 'CoreERP Provider Admin', 'password' => $password],
        );

        ProviderAccess::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['role' => 'provider_admin'],
        );
    }
}
