<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohB\Database\Seeders;

use App\Support\Modules\TenantScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Apperp\ContohB\Models\Rak;

/**
 * Master bawaan module contoh B.
 *
 * Ia ada supaya test bisa membuktikan hal yang paling mudah salah: memasang satu module
 * tidak boleh mengisi data awal module lain.
 */
final class RakBawaanSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = TenantScope::tenantAktif();

        Rak::query()->create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'kode' => 'RAK-BWN-01',
            'nama' => 'Rak bawaan',
            'bawaan' => true,
        ]);
    }
}
