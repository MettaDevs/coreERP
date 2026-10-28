<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use App\Platform\Modules\Contracts\AddressDirectory;
use App\Platform\Modules\Contracts\FundingSourceDirectory;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\OrganizationDirectory;
use App\Platform\Modules\Contracts\UnitOfMeasureDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use RuntimeException;

final class ReferenceDataController extends Controller
{
    /**
     * Daftar satuan tidak berdiri sebagai resource tersendiri; ia dibaca dari dalam layar
     * lain, jadi izinnya menumpang izin layar-layar itu. Menambahkan satu izin baru khusus
     * satuan hanya akan menambah baris yang harus ditugaskan admin tenant tanpa memberi
     * kendali yang berbeda: siapa pun yang boleh membuka salah satu layar di bawah memang
     * sudah harus dapat melihat pilihan satuannya.
     */
    private const UNIT_READERS = [
        'management-aset.perencanaan-aset.read',
        'management-aset.tipe-atribut.read',
        'management-aset.maintenance-checklist-templates.read',
    ];

    public function unitsOfMeasure(Request $request): JsonResponse
    {
        $held = $request->attributes->get('coreerp.permissions', []);
        abort_if(array_intersect(self::UNIT_READERS, $held) === [], 403);
        try {
            $batch = app(UnitOfMeasureDirectory::class)->lookup((string) $request->attributes->get('coreerp.tenant_id'), $request->query());
            $batch['data'] = collect($batch['data'])->map(fn ($unit): array => ['id' => $unit->id, 'kode' => $unit->code, 'nama' => $unit->name]);

            return response()->json($batch);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'units_of_measure_unavailable', 'message' => $exception->getMessage()]], 503);
        }
    }

    /**
     * Unit kerja dan orang milik Core, untuk dropdown yang menampilkan **nama**.
     *
     * Sebelum ini layar mutasi meminta pengguna mengetik ULID unit kerja dan ULID pengguna
     * ke dalam kotak teks. Itu bukan sekadar tidak nyaman: tidak ada orang yang hafal ULID,
     * jadi satu-satunya cara mengisinya benar adalah menyalinnya dari tempat lain, dan
     * salah satu digit yang tertukar tersimpan tanpa keluhan.
     *
     * Izinnya menumpang izin layar yang memakainya, sama seperti satuan: siapa pun yang
     * boleh membuka salah satu layar itu memang sudah harus dapat melihat pilihannya.
     */
    private const DIREKTORI_READERS = [
        'management-aset.perencanaan-aset.read',
        'management-aset.perencanaan-aset.create',
        'management-aset.perencanaan-aset.update',
        'management-aset.mutasi-aset.read',
        'management-aset.aset.read',
        'management-aset.pemeliharaan-aset.read',
        'management-aset.monitoring-aset.read',
        // Unit kerja bawaan pada lokasi aset.
        'management-aset.lokasi-aset.read',
    ];

    public function operatingUnits(Request $request): JsonResponse
    {
        $this->guardDirektori($request);

        return response()->json(app(OrganizationDirectory::class)->lookup(
            (string) $request->attributes->get('coreerp.tenant_id'), 'operating_units', $request->query(),
        ));
    }

    public function members(Request $request): JsonResponse
    {
        $this->guardDirektori($request);

        $batch = app(OrganizationDirectory::class)->lookup(
            (string) $request->attributes->get('coreerp.tenant_id'), 'members', $request->query(),
        );
        $batch['data'] = collect($batch['data'])->map(function ($member) {
            $member->id = (string) $member->id;

            return $member;
        });

        return response()->json($batch);
    }

    public function fundingSources(Request $request): JsonResponse
    {
        $held = $request->attributes->get('coreerp.permissions', []);
        abort_if(array_intersect(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.create', 'management-aset.perencanaan-aset.update'], $held) === [], 403);

        return response()->json(app(FundingSourceDirectory::class)->lookup((string) $request->attributes->get('coreerp.tenant_id'), $request->query()));
    }

    /**
     * Alamat dari buku alamat Core untuk dipilih pada lokasi aset, dengan nama tempat dan alamatnya.
     *
     * Dengan `unit_kerja_id`, yang dipulangkan hanya alamat utama unit kerja itu (nol atau satu baris):
     * saran alamat saat lokasi diberi unit kerja bawaan. Saran saja — lokasi tetap menyimpan alamatnya
     * sendiri, dan mengubah alamat unit kerja kemudian tidak menggeser alamat lokasi.
     */
    public function addresses(Request $request, AddressDirectory $addresses): JsonResponse
    {
        abort_unless(in_array('management-aset.lokasi-aset.read', $request->attributes->get('coreerp.permissions', []), true), 403);
        $validated = $request->validate(['unit_kerja_id' => ['nullable', 'ulid']]);
        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');

        if (($validated['unit_kerja_id'] ?? null) === null) {
            $batch = $addresses->lookup($tenantId, $request->query());
            $rows = collect($batch['data'])->map(fn ($row) => (array) $row)->all();
            $nextCursor = $batch['next_cursor'];
        } else {
            $primary = $addresses->primaryOfOrganization($tenantId, $validated['unit_kerja_id']);
            $rows = $primary === null ? [] : [$primary];
            $nextCursor = null;
        }

        return response()->json(['data' => array_map(
            // Bentuk pilihan dropdown modul ini: `kode` disamakan dengan nama tempat, dan labelnya
            // menyebut alamat satu baris supaya dua tempat bernama mirip tetap dapat dibedakan.
            static fn (array $row): array => [
                'id' => $row['id'],
                'kode' => $row['nama'],
                'nama' => $row['nama'],
                'alamat' => $row['alamat'],
                'display_label' => $row['alamat'] === '' ? $row['nama'] : $row['nama'].' — '.str_replace("\n", ', ', $row['alamat']),
            ],
            $rows,
        ), 'next_cursor' => $nextCursor]);
    }

    private function guardDirektori(Request $request): void
    {
        $held = $request->attributes->get('coreerp.permissions', []);
        abort_if(array_intersect(self::DIREKTORI_READERS, $held) === [], 403);
    }

    public function fiscalClassifications(Request $request): JsonResponse
    {
        abort_unless(in_array('management-aset.group-aset.read', $request->attributes->get('coreerp.permissions', []), true), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'aktif' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = KelompokHartaFiskal::query()->where('tenant_id', (string) $request->attributes->get('coreerp.tenant_id'));
        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn (Builder $builder) => $builder
                ->whereRaw('LOWER(template_key) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(label) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(COALESCE(regulation_reference, \'\')) LIKE ?', ['%'.mb_strtolower($search).'%']));
        }
        if (($validated['aktif'] ?? null) !== null) {
            $query->where('aktif', filter_var($validated['aktif'], FILTER_VALIDATE_BOOL));
        }

        $batch = IncrementalList::query($request, $query->toBase(), ['id' => 'id', 'kode' => 'template_key', 'nama' => 'label'], [], 'nama');
        $records = KelompokHartaFiskal::hydrate($batch['data']->map(fn ($row) => (array) $row)->all());

        return response()->json([
            ...$batch,
            'data' => $records->map(fn (KelompokHartaFiskal $record): array => [
                'id' => $record->id,
                'kode' => $record->template_key,
                'nama' => $record->label,
                'display_label' => $record->label,
                'template_key' => $record->template_key,
                'jurisdiction' => $record->jurisdiction,
                'label' => $record->label,
                'regulation_reference' => $record->regulation_reference,
                'effective_from' => $record->effective_from->toDateString(),
                'effective_to' => $record->effective_to?->toDateString(),
                'useful_life_years' => $record->useful_life_years,
                'straight_line_rate_percent' => $record->straight_line_rate_percent,
                'reducing_balance_rate_percent' => $record->reducing_balance_rate_percent,
                'allow_reducing_balance' => $record->allow_reducing_balance,
                'allow_straight_line' => $record->allow_straight_line,
                'depreciable' => $record->depreciable,
                'aktif' => $record->aktif,
            ])->values(),

        ]);
    }
}
