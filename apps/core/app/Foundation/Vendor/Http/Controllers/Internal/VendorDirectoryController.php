<?php

namespace App\Foundation\Vendor\Http\Controllers\Internal;

use App\Foundation\Vendor\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Platform\Organization\Models\LegalEntity;
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
    private const CHANGED = 'greatest(vendors.updated_at, parties.updated_at)';

    public function index(Request $request): JsonResponse
    {
        $tenant = (string) $request->attributes->get('coreerp.tenant_id');
        $filter = $request->validate([
            'updated_since' => ['nullable', 'date'],
            'legal_entity' => ['nullable', 'string', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $limit = (int) ($filter['limit'] ?? 500);

        $query = Vendor::query()
            ->join('parties', 'parties.id', '=', 'vendors.party_id')
            ->where('vendors.tenant_id', $tenant)
            ->select('vendors.*', 'parties.name as party_name', 'parties.type as party_type')
            ->selectRaw(self::CHANGED.' as changed_at');

        if (($filter['legal_entity'] ?? null) !== null) {
            $legalEntity = LegalEntity::query()
                ->where('tenant_id', $tenant)
                ->where(fn ($inner) => $inner->where('company_code', $filter['legal_entity'])->orWhere('organization_id', $filter['legal_entity']))
                ->value('organization_id');
            $query->where('vendors.legal_entity_id', $legalEntity ?? '');
        }

        if (($filter['updated_since'] ?? null) !== null) {
            // Lihat OrganizationDirectoryController: diubah ke zona waktu aplikasi, dan batasnya
            // inklusif. Nama vendor milik party, jadi perubahan nama di buku alamat ikut dihitung.
            $since = Carbon::parse($filter['updated_since'])->setTimezone((string) config('app.timezone'));
            $query->whereRaw(self::CHANGED.' >= ?', [$since->format('Y-m-d H:i:s')]);
        }

        if (($filter['cursor'] ?? null) !== null) {
            [$time, $id] = $this->decodeCursor($filter['cursor']);
            $query->whereRaw('('.self::CHANGED.', vendors.id) > (?::timestamp, ?)', [$time, $id]);
        }

        $row = $query->orderByRaw(self::CHANGED)->orderBy('vendors.id')->limit($limit + 1)->get();
        $stillExists = $row->count() > $limit;
        $row = $row->take($limit)->values();
        $code = LegalEntity::query()
            ->where('tenant_id', $tenant)
            ->whereIn('organization_id', $row->pluck('legal_entity_id')->unique()->values())
            ->pluck('company_code', 'organization_id');
        $last = $row->last();

        return response()->json([
            'data' => $row->map(fn (Vendor $vendor): array => [
                'id' => $vendor->id,
                'number' => $vendor->number,
                'name' => (string) $vendor->getAttribute('party_name'),
                'party_type' => (string) $vendor->getAttribute('party_type'),
                'tax_number' => $vendor->tax_number,
                'status' => $vendor->status,
                'legal_entity' => ['id' => $vendor->legal_entity_id, 'code' => $code[$vendor->legal_entity_id] ?? null],
                'updated_at' => Carbon::parse((string) $vendor->getAttribute('changed_at'), (string) config('app.timezone'))->toIso8601String(),
            ])->values(),
            'meta' => [
                'next_cursor' => $stillExists && $last instanceof Vendor
                    ? $this->encodeCursor((string) $last->getAttribute('changed_at'), $last->id)
                    : null,
            ],
        ]);
    }

    private function encodeCursor(string $time, string $id): string
    {
        return rtrim(strtr(base64_encode($time.'|'.$id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string} */
    private function decodeCursor(string $cursor): array
    {
        $content = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (is_string($content) && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([0-9A-HJKMNP-TV-Z]{26})$/i', $content, $parts) === 1) {
            return [$parts[1], $parts[2]];
        }

        throw ValidationException::withMessages(['cursor' => 'Kursor tidak dikenal. Mulai lagi dari halaman pertama.']);
    }
}
