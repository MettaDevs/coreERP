<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Vendor untuk sistem di luar CoreERP yang menyinkronkan tabel penerjemahnya (K-06, TODO 2.6).
 *
 * Diurutkan menurut waktu perubahan terakhir — vendor atau party-nya, mana yang lebih akhir — lalu
 * id, dan dibaca per halaman lewat kursor, bukan nomor halaman. Dengan nomor halaman, vendor di
 * halaman yang sudah dibaca yang berubah di tengah sinkron pindah ke akhir urutan dan menggeser
 * semua baris sesudahnya satu posisi ke depan: baris pertama halaman berikutnya jatuh ke halaman
 * yang sudah lewat dan tidak pernah terbaca. Dengan kursor, vendor yang berubah hanya dapat pindah
 * ke belakang kursor, jadi paling buruk terbaca dua kali.
 */
final class VendorDirectoryController extends Controller
{
    private const BERUBAH = 'greatest(vendors.updated_at, parties.updated_at)';

    public function index(Request $request): JsonResponse
    {
        $tenant = (string) $request->attributes->get('coreerp.tenant_id');
        $filter = $request->validate([
            'updated_since' => ['nullable', 'date'],
            'legal_entity' => ['nullable', 'string', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $batas = (int) ($filter['limit'] ?? 500);

        $query = Vendor::query()
            ->join('parties', 'parties.id', '=', 'vendors.party_id')
            ->where('vendors.tenant_id', $tenant)
            ->select('vendors.*', 'parties.name as party_name', 'parties.type as party_type')
            ->selectRaw(self::BERUBAH.' as changed_at');

        if (($filter['legal_entity'] ?? null) !== null) {
            $entitas = LegalEntity::query()
                ->where('tenant_id', $tenant)
                ->where(fn ($inner) => $inner->where('company_code', $filter['legal_entity'])->orWhere('organization_id', $filter['legal_entity']))
                ->value('organization_id');
            $query->where('vendors.legal_entity_id', $entitas ?? '');
        }

        if (($filter['updated_since'] ?? null) !== null) {
            // Lihat OrganizationDirectoryController: diubah ke zona waktu aplikasi, dan batasnya
            // inklusif. Nama vendor milik party, jadi perubahan nama di buku alamat ikut dihitung.
            $sejak = Carbon::parse($filter['updated_since'])->setTimezone((string) config('app.timezone'));
            $query->whereRaw(self::BERUBAH.' >= ?', [$sejak->format('Y-m-d H:i:s')]);
        }

        if (($filter['cursor'] ?? null) !== null) {
            [$waktu, $id] = $this->bacaKursor($filter['cursor']);
            $query->whereRaw('('.self::BERUBAH.', vendors.id) > (?::timestamp, ?)', [$waktu, $id]);
        }

        $baris = $query->orderByRaw(self::BERUBAH)->orderBy('vendors.id')->limit($batas + 1)->get();
        $masihAda = $baris->count() > $batas;
        $baris = $baris->take($batas)->values();
        $kode = LegalEntity::query()
            ->where('tenant_id', $tenant)
            ->whereIn('organization_id', $baris->pluck('legal_entity_id')->unique()->values())
            ->pluck('company_code', 'organization_id');
        $terakhir = $baris->last();

        return response()->json([
            'data' => $baris->map(fn (Vendor $vendor): array => [
                'id' => $vendor->id,
                'number' => $vendor->number,
                'name' => (string) $vendor->getAttribute('party_name'),
                'party_type' => (string) $vendor->getAttribute('party_type'),
                'tax_number' => $vendor->tax_number,
                'status' => $vendor->status,
                'legal_entity' => ['id' => $vendor->legal_entity_id, 'code' => $kode[$vendor->legal_entity_id] ?? null],
                'updated_at' => Carbon::parse((string) $vendor->getAttribute('changed_at'), (string) config('app.timezone'))->toIso8601String(),
            ])->values(),
            'meta' => [
                'next_cursor' => $masihAda && $terakhir instanceof Vendor
                    ? $this->tulisKursor((string) $terakhir->getAttribute('changed_at'), $terakhir->id)
                    : null,
            ],
        ]);
    }

    private function tulisKursor(string $waktu, string $id): string
    {
        return rtrim(strtr(base64_encode($waktu.'|'.$id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string} */
    private function bacaKursor(string $kursor): array
    {
        $isi = base64_decode(strtr($kursor, '-_', '+/'), true);
        if (is_string($isi) && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([0-9A-HJKMNP-TV-Z]{26})$/i', $isi, $bagian) === 1) {
            return [$bagian[1], $bagian[2]];
        }

        throw ValidationException::withMessages(['cursor' => 'Kursor tidak dikenal. Mulai lagi dari halaman pertama.']);
    }
}
