<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;

/**
 * Hubungan model ke jenis aset disunting dari pemiliknya, yaitu jenis aset.
 * Model dan pabrikan tetap menjadi katalog pilihan; halaman ini tidak membuat
 * atau mengubah isi katalog tersebut.
 */
class JenisAsetModelController extends Controller
{
    public function replace(Request $request, string $jenisAsetId): JsonResponse
    {
        $permissions = $request->attributes->get('coreerp.permissions', []);
        abort_unless(in_array('management-aset.jenis-aset.update', $permissions, true), 403);
        abort_unless(in_array('management-aset.model-aset.read', $permissions, true), 403);

        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        abort_unless(JenisAset::query()->whereKey($jenisAsetId)->exists(), 404);

        $data = $request->validate([
            'model_ids' => ['present', 'array', 'max:100'],
            'model_ids.*' => [
                'required', 'distinct', 'ulid',
                Rule::exists('aset_m_model_aset', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
        ]);
        $modelIds = array_values($data['model_ids']);

        DB::transaction(function () use ($jenisAsetId, $modelIds): void {
            JenisAset::query()->whereKey($jenisAsetId)->lockForUpdate()->first();

            $models = ModelAset::query()
                ->whereKey($modelIds)
                ->lockForUpdate()
                ->get(['id', 'jenis_aset_id']);
            $conflict = $models->first(fn (ModelAset $model): bool => $model->jenis_aset_id !== null && $model->jenis_aset_id !== $jenisAsetId);
            if ($conflict) {
                throw ValidationException::withMessages([
                    'model_ids' => 'Salah satu model sudah dikaitkan dengan jenis aset lain.',
                ]);
            }

            // `withTrashed()`: model yang sudah diarsipkan ikut dilepas dari jenis ini.
            // Membiarkannya menunjuk ke sini membuat jenis aset terlihat masih dipakai
            // oleh baris yang tidak pernah muncul lagi di layar mana pun.
            ModelAset::query()->withTrashed()
                ->where('jenis_aset_id', $jenisAsetId)
                ->update(['jenis_aset_id' => null]);

            if ($modelIds !== []) {
                ModelAset::query()->whereKey($modelIds)->update(['jenis_aset_id' => $jenisAsetId]);
            }
        });

        return response()->json(['data' => ['model_ids' => $modelIds]]);
    }
}
