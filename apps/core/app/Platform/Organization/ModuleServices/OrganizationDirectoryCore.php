<?php

declare(strict_types=1);

namespace App\Platform\Organization\ModuleServices;

use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\OrganizationDirectory;
use App\Platform\Organization\Support\BusinessUnitResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
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
final class OrganizationDirectoryCore implements OrganizationDirectory
{
    public function lookup(string $tenantId, string $kind, array $input): array
    {
        if ($kind === 'members') {
            $query = DB::table('tenant_memberships as memberships')
                ->join('users', 'users.id', '=', 'memberships.user_id')
                ->where('memberships.tenant_id', $tenantId)->where('memberships.status', 'active')
                ->select(['users.id', 'users.name as kode', 'users.name as nama', 'users.name as display_label', 'users.email']);
        } else {
            abort_unless($kind === 'operating_units', 422);
            $query = DB::table('organizations')->leftJoin('operating_units as unit', 'unit.organization_id', '=', 'organizations.id')
                ->where('organizations.tenant_id', $tenantId)->where('organizations.classification', 'operating_unit')
                ->select(['organizations.id', 'organizations.name as nama', 'unit.number as kode'])
                ->selectRaw("CASE WHEN unit.number IS NULL THEN organizations.name ELSE unit.number || ' — ' || organizations.name END AS display_label");
        }

        return IncrementalList::query(Request::create('/lookup', 'GET', $input), $query, ['id' => 'id', 'kode' => 'kode', 'nama' => 'nama'], ['kode', 'nama'], 'nama');
    }

    public function __construct(private readonly BusinessUnitResolver $businessUnits) {}

    public function addNames(Builder $query, string $tenantId, array $units = [], array $members = []): void
    {
        foreach ($units as $alias => $column) {
            $query->selectSub(DB::table('organizations as directory_org')->where('directory_org.tenant_id', $tenantId)
                ->whereColumn('directory_org.id', $column)->select('directory_org.name')->limit(1), $alias);
        }
        foreach ($members as $alias => $column) {
            $query->selectSub(DB::table('tenant_memberships as directory_member')->join('users as directory_user', 'directory_user.id', '=', 'directory_member.user_id')
                ->where('directory_member.tenant_id', $tenantId)->where('directory_member.status', 'active')
                ->whereColumn(DB::raw('CAST(directory_user.id AS TEXT)'), $column)->select('directory_user.name')->limit(1), $alias);
        }
    }

    public function members(string $tenantId): array
    {
        return array_values(DB::table('tenant_memberships as memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.status', 'active')
            ->orderBy('users.name')
            ->get(['memberships.id', 'memberships.user_id', 'users.name', 'users.email'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'user_id' => (string) $row->user_id,
                'nama' => (string) $row->name,
                'email' => (string) $row->email,
            ])
            ->all());
    }

    public function member(string $tenantId, string $membershipId): ?array
    {
        $row = DB::table('tenant_memberships as memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.id', $membershipId)
            ->first(['memberships.id', 'memberships.user_id', 'users.name', 'users.email']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'user_id' => (string) $row->user_id,
            'nama' => (string) $row->name,
            'email' => (string) $row->email,
        ];
    }

    public function operatingUnits(string $tenantId): array
    {
        return array_values(DB::table('organizations')
            ->leftJoin('operating_units as unit', 'unit.organization_id', '=', 'organizations.id')
            ->where('organizations.tenant_id', $tenantId)
            ->where('organizations.classification', 'operating_unit')
            ->orderBy('organizations.name')
            ->get(['organizations.id', 'organizations.name', 'organizations.classification', 'unit.type', 'unit.number'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'nama' => (string) $row->name,
                'klasifikasi' => (string) $row->classification,
                'tipe' => $row->type === null ? null : (string) $row->type,
                'nomor' => $row->number === null ? null : (string) $row->number,
            ])
            ->all());
    }

    public function parentBusinessUnits(string $tenantId, array $orgUnitIds, string $date): array
    {
        return array_map(
            static fn (?array $bu): ?array => $bu === null ? null : [
                'id' => $bu['id'],
                'nama' => $bu['name'],
                'nomor' => $bu['number'],
            ],
            $this->businessUnits->resolve($tenantId, $orgUnitIds, $date),
        );
    }
}
