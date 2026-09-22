<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menemukan business unit induk sebuah operating unit lewat hierarki manajemen.
 *
 * Padanannya *derived dimensions* di Dynamics 365 F&O: nilai dimensi `BusinessUnit` diturunkan dari
 * `Department` lewat struktur organisasi, tanpa tabel aturan yang harus dirawat tangan. Di CoreERP
 * strukturnya adalah hierarki bertujuan `management`, dibaca pada versi yang berlaku di tanggal
 * posting — bukan hari ini — supaya jurnal bulan lalu tetap masuk ke klinik yang benar walaupun
 * poli itu sudah dipindah minggu ini.
 *
 * Dipakai dua pemanggil yang harus sepakat: kontrak `DirektoriOrganisasi` untuk module, dan penerbit
 * posting finance di Core. Satu kelas supaya pratinjau di layar modul dan jurnal yang benar-benar
 * terbit tidak pernah menurunkan business unit dengan cara berbeda.
 */
final class BusinessUnitResolver
{
    /**
     * @param  list<string>  $orgUnitIds
     * @return array<string, array{id: string, name: string, number: ?string}|null> Berkunci id yang ditanyakan.
     */
    public function resolve(string $tenantId, array $orgUnitIds, string $date): array
    {
        $ids = array_values(array_unique(array_filter($orgUnitIds, static fn (string $id): bool => $id !== '')));
        /** @var array<string, array{id: string, name: string, number: ?string}|null> $hasil */
        $hasil = array_fill_keys($ids, null);
        if ($ids === []) {
            return $hasil;
        }

        $versi = $this->versiManajemenYangBerlaku($tenantId, $date);
        if ($versi === []) {
            return $hasil;
        }

        // Leluhur bertipe business unit, termasuk unit itu sendiri (jarak 0). Legal entity tidak
        // punya baris di `operating_units`, jadi join dalam ini sudah menyaringnya.
        $baris = DB::table('organization_hierarchy_closures as closure')
            ->join('operating_units as unit', 'unit.organization_id', '=', 'closure.ancestor_organization_id')
            ->join('organizations as ancestor', 'ancestor.id', '=', 'closure.ancestor_organization_id')
            ->whereIn('closure.version_id', $versi)
            ->whereIn('closure.descendant_organization_id', $ids)
            ->where('unit.type', 'business_unit')
            ->where('ancestor.tenant_id', $tenantId)
            ->orderBy('closure.distance')
            ->get([
                'closure.version_id',
                'closure.descendant_organization_id',
                'closure.ancestor_organization_id',
                'ancestor.name',
                'unit.number',
            ]);

        // Satu jawaban per hierarki: business unit terdekat. Baris sudah urut jarak, jadi yang
        // pertama untuk tiap pasangan (unit, versi) adalah yang terdekat.
        $terdekat = [];
        foreach ($baris as $row) {
            $terdekat[$row->descendant_organization_id.'|'.$row->version_id] ??= $row;
        }

        $perUnit = [];
        foreach ($terdekat as $row) {
            $perUnit[(string) $row->descendant_organization_id][(string) $row->ancestor_organization_id] = $row;
        }

        foreach ($perUnit as $unitId => $calon) {
            // Dua hierarki manajemen yang tidak sepakat bukan pilihan yang boleh ditebak.
            if (count($calon) !== 1) {
                continue;
            }
            $bu = reset($calon);
            $hasil[$unitId] = [
                'id' => (string) $bu->ancestor_organization_id,
                'name' => (string) $bu->name,
                'number' => $bu->number === null ? null : (string) $bu->number,
            ];
        }

        return $hasil;
    }

    /**
     * Id versi yang berlaku pada tanggal itu, satu per hierarki manajemen aktif.
     *
     * `effective_from` bertipe timestamp sedangkan tanggal posting hanya tanggal, jadi versi yang
     * mulai berlaku kapan pun pada hari itu dihitung berlaku untuk seluruh hari itu.
     *
     * @return list<string>
     */
    private function versiManajemenYangBerlaku(string $tenantId, string $date): array
    {
        $calon = DB::table('organization_hierarchy_versions as version')
            ->join('organization_hierarchies as hierarchy', 'hierarchy.id', '=', 'version.hierarchy_id')
            ->join('organization_hierarchy_purposes as link', 'link.hierarchy_id', '=', 'hierarchy.id')
            ->join('hierarchy_purposes as purpose', 'purpose.id', '=', 'link.purpose_id')
            ->where('hierarchy.tenant_id', $tenantId)
            ->where('hierarchy.status', 'active')
            ->where('purpose.code', 'management')
            ->where('version.status', 'published')
            ->where('version.effective_from', '<', Carbon::parse($date)->addDay()->startOfDay())
            ->orderByDesc('version.effective_from')
            ->orderByDesc('version.version_number')
            ->get(['version.id', 'version.hierarchy_id']);

        $perHierarki = [];
        foreach ($calon as $row) {
            $perHierarki[(string) $row->hierarchy_id] ??= (string) $row->id;
        }

        return array_values($perHierarki);
    }
}
