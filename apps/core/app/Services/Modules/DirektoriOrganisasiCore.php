<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Support\BusinessUnitResolver;
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
    public function __construct(private readonly BusinessUnitResolver $unitBisnis) {}

    public function anggota(string $tenantId): array
    {
        return array_values(DB::table('tenant_memberships as memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.status', 'active')
            ->orderBy('users.name')
            ->get(['memberships.id', 'memberships.user_id', 'users.name', 'users.email'])
            ->map(static fn (object $baris): array => [
                'id' => (string) $baris->id,
                'user_id' => (string) $baris->user_id,
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
            ->first(['memberships.id', 'memberships.user_id', 'users.name', 'users.email']);

        if ($baris === null) {
            return null;
        }

        return [
            'id' => (string) $baris->id,
            'user_id' => (string) $baris->user_id,
            'nama' => (string) $baris->name,
            'email' => (string) $baris->email,
        ];
    }

    public function unitOperasi(string $tenantId): array
    {
        return array_values(DB::table('organizations')
            ->leftJoin('operating_units as unit', 'unit.organization_id', '=', 'organizations.id')
            ->where('organizations.tenant_id', $tenantId)
            ->where('organizations.classification', 'operating_unit')
            ->orderBy('organizations.name')
            ->get(['organizations.id', 'organizations.name', 'organizations.classification', 'unit.type', 'unit.number'])
            ->map(static fn (object $baris): array => [
                'id' => (string) $baris->id,
                'nama' => (string) $baris->name,
                'klasifikasi' => (string) $baris->classification,
                'tipe' => $baris->type === null ? null : (string) $baris->type,
                'nomor' => $baris->number === null ? null : (string) $baris->number,
            ])
            ->all());
    }

    public function unitBisnisInduk(string $tenantId, array $orgUnitIds, string $tanggal): array
    {
        return array_map(
            static fn (?array $bu): ?array => $bu === null ? null : [
                'id' => $bu['id'],
                'nama' => $bu['name'],
                'nomor' => $bu['number'],
            ],
            $this->unitBisnis->resolve($tenantId, $orgUnitIds, $tanggal),
        );
    }
}
