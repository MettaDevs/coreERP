<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Support\Modules\Contracts\DirektoriOrganisasi;
use Illuminate\Support\Facades\DB;

/**
 * Membaca anggota tenant dan unit organisasinya.
 *
 * Layanan ini belum pernah ada: ketiga pertanyaannya dijawab langsung di controller internal,
 * jadi module hanya bisa menanyakannya lewat HTTP. Setelah module berada di proses yang sama,
 * pertanyaannya perlu rumah yang bukan controller.
 *
 * Yang dikembalikan baris sederhana, bukan model Core. Mengembalikan model berarti module
 * memegang objek Core dan bisa memanggil apa pun padanya, dan batasnya kembali kabur.
 */
final class DirektoriOrganisasiCore implements DirektoriOrganisasi
{
    public function anggota(string $tenantId): array
    {
        return array_values(DB::table('tenant_memberships as memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.status', 'active')
            ->orderBy('users.name')
            ->get(['memberships.id', 'users.name', 'users.email'])
            ->map(static fn (object $baris): array => [
                'id' => (string) $baris->id,
                'nama' => (string) $baris->name,
                'email' => (string) $baris->email,
            ])
            ->all());
    }

    public function anggotaSatu(string $tenantId, string $membershipId): ?array
    {
        $baris = DB::table('tenant_memberships as memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.id', $membershipId)
            ->first(['memberships.id', 'users.name', 'users.email']);

        if ($baris === null) {
            return null;
        }

        return [
            'id' => (string) $baris->id,
            'nama' => (string) $baris->name,
            'email' => (string) $baris->email,
        ];
    }

    public function unitOperasi(string $tenantId): array
    {
        return array_values(DB::table('organizations')
            ->where('tenant_id', $tenantId)
            ->where('classification', 'operating_unit')
            ->orderBy('name')
            ->get(['id', 'name', 'classification'])
            ->map(static fn (object $baris): array => [
                'id' => (string) $baris->id,
                'nama' => (string) $baris->name,
                'klasifikasi' => (string) $baris->classification,
            ])
            ->all());
    }
}
