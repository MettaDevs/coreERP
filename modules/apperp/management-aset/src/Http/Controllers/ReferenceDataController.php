<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Services\UnitOfMeasureClient;
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

    public function unitsOfMeasure(Request $request, UnitOfMeasureClient $units): JsonResponse
    {
        $held = $request->attributes->get('coreerp.permissions', []);
        abort_if(array_intersect(self::UNIT_READERS, $held) === [], 403);
        try {
            return response()->json(['data' => collect($units->active((string) $request->attributes->get('coreerp.tenant_id')))
                ->map(fn (array $unit): array => ['id' => $unit['id'], 'kode' => $unit['code'], 'nama' => $unit['name']])->values()]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'units_of_measure_unavailable', 'message' => $exception->getMessage()]], 503);
        }
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

        $page = $query->orderBy('effective_from')->orderBy('label')->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (KelompokHartaFiskal $record): array => [
                'id' => $record->id,
                'kode' => $record->template_key,
                'nama' => $record->label,
                'display_label' => $this->displayLabel($record),
                'template_key' => $record->template_key,
                'jurisdiction' => $record->jurisdiction,
                'label' => $record->label,
                'regulation_reference' => $record->regulation_reference,
                'effective_from' => $record->effective_from?->toDateString(),
                'effective_to' => $record->effective_to?->toDateString(),
                'useful_life_years' => $record->useful_life_years,
                'straight_line_rate_percent' => $record->straight_line_rate_percent,
                'reducing_balance_rate_percent' => $record->reducing_balance_rate_percent,
                'allow_reducing_balance' => $record->allow_reducing_balance,
                'depreciable' => $record->depreciable,
                'aktif' => $record->aktif,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    private function displayLabel(KelompokHartaFiskal $record): string
    {
        $effectiveFrom = $record->effective_from?->format('d/m/Y');

        return $effectiveFrom ? $record->label.' — berlaku '.$effectiveFrom : $record->label;
    }
}
