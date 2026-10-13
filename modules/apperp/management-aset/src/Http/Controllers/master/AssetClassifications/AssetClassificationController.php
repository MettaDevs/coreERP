<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master\AssetClassifications;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

final class AssetClassificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize($request, 'read');
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'aktif' => ['nullable', Rule::in(['true', 'false'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = KelompokHartaFiskal::query()->where('tenant_id', $request->attributes->get('coreerp.tenant_id'));
        if (($search = trim($data['q'] ?? '')) !== '') {
            $query->whereRaw('LOWER(label) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }
        if (isset($data['aktif'])) {
            $query->where('aktif', $data['aktif'] === 'true');
        }
        $page = $query->orderBy('label')->orderBy('effective_from')->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($page->items())->map(fn (KelompokHartaFiskal $record) => $this->present($record)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize($request, 'update');
        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:150'],
            'regulation_reference' => ['sometimes', 'nullable', 'string', 'max:250'],
            'effective_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'useful_life_years' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'straight_line_rate_percent' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100'],
            'reducing_balance_rate_percent' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100'],
            'allow_reducing_balance' => ['sometimes', 'boolean'],
            'depreciable' => ['sometimes', 'boolean'],
            'aktif' => ['sometimes', 'boolean'],
        ]);
        $expected = RowVersion::expected($request);

        return DB::transaction(function () use ($request, $id, $data, $expected): JsonResponse {
            $record = KelompokHartaFiskal::query()
                ->where('tenant_id', $request->attributes->get('coreerp.tenant_id'))->findOrFail($id);
            RowVersion::claim($record, $expected);
            $record->refresh()->fill($data);
            validator($record->only(['effective_from', 'effective_to', 'useful_life_years', 'straight_line_rate_percent', 'reducing_balance_rate_percent']), [
                'effective_to' => ['nullable', 'after_or_equal:effective_from'],
                'useful_life_years' => $record->depreciable ? ['required'] : ['nullable'],
                'straight_line_rate_percent' => $record->depreciable ? ['required'] : ['nullable'],
                'reducing_balance_rate_percent' => $record->depreciable && $record->allow_reducing_balance ? ['required'] : ['nullable'],
            ], [
                'effective_to.after_or_equal' => 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.',
                'useful_life_years.required' => 'Isi masa manfaat untuk harta yang disusutkan.',
                'straight_line_rate_percent.required' => 'Isi tarif garis lurus untuk harta yang disusutkan.',
                'reducing_balance_rate_percent.required' => 'Isi tarif saldo menurun jika metode ini diperbolehkan.',
            ])->validate();
            $record->save();

            return response()->json(['data' => $this->present($record->refresh())]);
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorize($request, 'archive');
        $expected = RowVersion::expected($request);
        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');

        return DB::transaction(function () use ($id, $expected, $tenantId): JsonResponse {
            $record = KelompokHartaFiskal::query()->where('tenant_id', $tenantId)->findOrFail($id);
            RowVersion::claim($record, $expected);
            foreach ([GroupAset::class => 'group aset', Aset::class => 'aset'] as $model => $label) {
                if ($model::query()->where('tenant_id', $tenantId)->where('kelompok_harta_fiskal_id', $id)->exists()) {
                    throw new HttpResponseException(response()->json(['error' => [
                        'code' => 'referenced_by_children',
                        'message' => 'Kelompok harta ini masih dipakai '.$label.' yang belum diarsipkan. Arsipkan data turunannya lebih dahulu.',
                    ]], 409));
                }
            }
            $record->delete();

            return response()->json(status: 204);
        });
    }

    private function authorize(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.kelompok-harta.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    /** @return array<string, mixed> */
    private function present(KelompokHartaFiskal $record): array
    {
        return [...$record->only([
            'id', 'template_key', 'label', 'jurisdiction', 'regulation_reference', 'useful_life_years',
            'straight_line_rate_percent', 'reducing_balance_rate_percent',
            'allow_reducing_balance', 'depreciable', 'aktif', 'version',
        ]), 'effective_from' => $record->effective_from->toDateString(), 'effective_to' => $record->effective_to?->toDateString()];
    }
}
