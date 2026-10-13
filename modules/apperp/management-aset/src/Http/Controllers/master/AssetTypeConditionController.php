<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\AssetTypeCondition;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;

final class AssetTypeConditionController extends Controller
{
    public function index(Request $request, string $assetTypeId): JsonResponse
    {
        $this->permission($request, 'read');
        $assetType = JenisAset::query()->findOrFail($assetTypeId);

        return response()->json($this->transfer($assetTypeId, (int) $assetType->version));
    }

    public function replace(Request $request, string $assetTypeId): JsonResponse
    {
        $this->permission($request, 'update');
        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        JenisAset::query()->findOrFail($assetTypeId);
        $data = $request->validate([
            'condition_ids' => ['present', 'array', 'max:200'],
            'condition_ids.*' => ['required', 'distinct', 'ulid', Rule::exists('aset_m_kondisi_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
        ]);
        foreach ($data['condition_ids'] as $conditionId) {
            if (! KondisiAset::query()->whereKey($conditionId)->where('aktif', true)->exists() && ! AssetTypeCondition::query()->where('jenis_aset_id', $assetTypeId)->where('kondisi_aset_id', $conditionId)->exists()) {
                throw ValidationException::withMessages(['condition_ids' => 'Kondisi yang dipilih sudah tidak aktif.']);
            }
        }
        $expected = RowVersion::expected($request);

        DB::transaction(function () use ($assetTypeId, $data, $expected, $tenantId): void {
            RowVersion::claim(JenisAset::query()->findOrFail($assetTypeId), $expected);
            $current = AssetTypeCondition::query()->where('jenis_aset_id', $assetTypeId)->pluck('kondisi_aset_id')->map(strval(...))->all();
            AssetTypeCondition::query()->where('jenis_aset_id', $assetTypeId)
                ->whereNotIn('kondisi_aset_id', $data['condition_ids'])->delete();
            foreach (array_diff($data['condition_ids'], $current) as $conditionId) {
                AssetTypeCondition::query()->create([
                    'tenant_id' => $tenantId,
                    'jenis_aset_id' => $assetTypeId,
                    'kondisi_aset_id' => $conditionId,
                ]);
            }
        });

        return response()->json($this->transfer($assetTypeId, (int) JenisAset::query()->whereKey($assetTypeId)->value('version')));
    }

    /** @return array<string, mixed> */
    private function transfer(string $assetTypeId, int $version): array
    {
        $selected = AssetTypeCondition::query()->where('jenis_aset_id', $assetTypeId)->pluck('kondisi_aset_id')->map(strval(...))->all();
        $all = KondisiAset::query()->where(fn ($query) => $query->where('aktif', true)->orWhereIn('id', $selected))->orderBy('kode')->get(['id', 'kode', 'nama', 'aktif']);

        return ['data' => [
            'remaining' => $all->reject(fn (KondisiAset $item): bool => in_array((string) $item->id, $selected, true))->values(),
            'selected' => $all->filter(fn (KondisiAset $item): bool => in_array((string) $item->id, $selected, true))->values(),
        ], 'version' => $version];
    }

    private function permission(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.kondisi-aset.read', $request->attributes->get('coreerp.permissions', []), true), 403);
        abort_unless(in_array('management-aset.jenis-aset.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }
}
