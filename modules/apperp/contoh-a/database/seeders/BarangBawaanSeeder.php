<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Database\Seeders;

use App\Support\Modules\Contracts\SeederModule;
use Illuminate\Support\Str;
use Modules\Apperp\ContohA\Models\Barang;

/**
 * Master bawaan module contoh A.
 *
 * Seeder module hanya dipanggil pemasangan module, tidak pernah oleh `db:seed` global.
 * Kalau ia ikut `db:seed`, memasang satu module akan mengisi data module lain yang belum
 * dibeli siapa pun.
 *
 * Setiap baris ditandai `bawaan`, supaya bisa dibedakan dari baris yang diketik pengguna.
 */
final class BarangBawaanSeeder extends SeederModule
{
    public function run(): void
    {
        $tenantId = $this->tenantId();

        foreach ([['BRG-BWN-01', 'Barang bawaan satu'], ['BRG-BWN-02', 'Barang bawaan dua']] as [$kode, $nama]) {
            Barang::query()->create([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'kode' => $kode,
                'nama' => $nama,
                'bawaan' => true,
            ]);
        }
    }
}
