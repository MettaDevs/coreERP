<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateIntegrationClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OrganizationDirectoryController extends Controller
{
    /**
     * Operating unit tenant, beserta nomor dan tipenya.
     *
     * Tanpa `updated_since` jawabannya daftar unit aktif, sama seperti sebelumnya. Dengan
     * `updated_since` jawabannya **setiap** unit yang berubah sejak saat itu, termasuk yang
     * dinonaktifkan: pembaca yang menyinkronkan tabel penerjemahnya harus tahu unit yang berhenti
     * dipakai, dan daftar "yang aktif saja" menyembunyikan tepat perubahan itu.
     *
     * Perubahan nama tercatat di `organizations`, perubahan nomor dan tipe di `operating_units`;
     * keduanya dihitung.
     */
    public function operatingUnits(Request $request): JsonResponse
    {
        // Dua pemanggil yang sah: module human-resources, dan klien integrasi yang cakupan
        // `operating-units.read`-nya sudah diperiksa middleware sebelum sampai ke sini.
        abort_unless(
            $request->attributes->get('coreerp.app_id') === 'human-resources'
                || $request->attributes->has(AuthenticateIntegrationClient::ATRIBUT),
            403,
        );
        $filter = $request->validate(['updated_since' => ['nullable', 'date']]);

        $query = DB::table('organizations')
            ->leftJoin('operating_units as unit', 'unit.organization_id', '=', 'organizations.id')
            ->where('organizations.tenant_id', $request->attributes->get('coreerp.tenant_id'))
            ->where('organizations.classification', 'operating_unit');

        if (($filter['updated_since'] ?? null) !== null) {
            // Diubah ke zona waktu aplikasi lebih dulu. Kolomnya disimpan tanpa zona, dan Carbon
            // yang dibawa sebagai binding diformat menurut zonanya sendiri — `10:00+07:00` akan
            // dibandingkan sebagai `10:00` UTC, tujuh jam meleset.
            //
            // `>=`, bukan `>`: kolomnya berpresisi detik, jadi dua perubahan pada detik yang sama
            // dengan batas tarikan sebelumnya tidak boleh terlewat. Baris yang terkirim dua kali
            // tidak merugikan pembaca yang menyimpan dengan upsert.
            $sejak = Carbon::parse($filter['updated_since'])->setTimezone((string) config('app.timezone'));
            $query->where(fn ($inner) => $inner
                ->where('organizations.updated_at', '>=', $sejak)
                ->orWhere('unit.updated_at', '>=', $sejak));
        } else {
            $query->where('organizations.status', 'active');
        }

        $units = $query->orderBy('organizations.name')->get([
            'organizations.id', 'organizations.name', 'organizations.status',
            'organizations.updated_at as organization_updated_at',
            'unit.type', 'unit.number', 'unit.updated_at as unit_updated_at',
        ]);

        return response()->json(['data' => $units->map(static fn (object $unit): array => [
            'id' => (string) $unit->id,
            'name' => (string) $unit->name,
            'type' => $unit->type,
            'number' => $unit->number,
            'status' => (string) $unit->status,
            // Yang lebih akhir dari kedua catatan, supaya pembaca dapat memakai nilai terbesar
            // sebagai `updated_since` tarikan berikutnya tanpa melewatkan perubahan apa pun.
            'updated_at' => collect([$unit->organization_updated_at, $unit->unit_updated_at])
                ->filter()
                ->map(static fn (string $waktu): Carbon => Carbon::parse($waktu))
                ->max()
                ?->toIso8601String(),
        ])->values()]);
    }
}
