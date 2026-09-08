<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        abort_unless(
            DB::table('m_jenis_aset')
                ->where(['tenant_id' => $tenantId, 'id' => $jenisAsetId])
                ->whereNull('deleted_at')
                ->exists(),
            404,
        );

        $data = $request->validate([
            'model_ids' => ['present', 'array', 'max:100'],
            'model_ids.*' => [
                'required', 'distinct', 'ulid',
                Rule::exists('m_model_aset', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
        ]);
        $modelIds = array_values($data['model_ids']);

        DB::transaction(function () use ($tenantId, $jenisAsetId, $modelIds): void {
            DB::table('m_jenis_aset')
                ->where(['tenant_id' => $tenantId, 'id' => $jenisAsetId])
                ->lockForUpdate()
                ->first();

            $models = DB::table('m_model_aset')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $modelIds)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get(['id', 'jenis_aset_id']);
            $conflict = $models->first(fn (object $model): bool => $model->jenis_aset_id !== null && $model->jenis_aset_id !== $jenisAsetId);
            if ($conflict) {
                throw ValidationException::withMessages([
                    'model_ids' => 'Salah satu model sudah dikaitkan dengan jenis aset lain.',
                ]);
            }

            DB::table('m_model_aset')
                ->where(['tenant_id' => $tenantId, 'jenis_aset_id' => $jenisAsetId])
                ->update(['jenis_aset_id' => null, 'updated_at' => now()]);

            if ($modelIds !== []) {
                DB::table('m_model_aset')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $modelIds)
                    ->update(['jenis_aset_id' => $jenisAsetId, 'updated_at' => now()]);
            }
        });

        return response()->json(['data' => ['model_ids' => $modelIds]]);
    }
}
