<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\Controller;
use App\Support\OrganizationScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Berapa banyak data yang menggantung pada satu jenis aset, untuk kotak angka pada panel
 * detailnya.
 *
 * Berdiri sendiri, bukan bagian respons master, karena dua alasan yang keduanya hilang
 * kalau angka ini ikut menempel pada setiap record: daftar tidak perlu menghitung apa pun
 * (hanya record yang sedang dibuka yang butuh), dan setiap angka dapat menegakkan izinnya
 * masing-masing.
 *
 * Izin dinilai per resource, bukan sekali di depan. Jumlah model dan jumlah aset adalah
 * isi resource lain: yang hanya boleh membaca jenis aset tidak berhak menyimpulkan ada
 * berapa aset di tenant ini, jadi angkanya `null`, bukan 0 — 0 adalah pernyataan bahwa
 * tidak ada, dan itu pernyataan yang tidak boleh kita buat kepada yang tidak berhak
 * bertanya.
 *
 * Jumlah atribut tidak diperlakukan begitu. `m_jenis_aset_atribut` adalah milik jenis aset
 * itu sendiri, disunting di dalam formnya, jadi izin jenis aset sudah cukup.
 */
class JenisAsetDetailController extends Controller
{
    public function __invoke(Request $request, string $jenisAsetId, OrganizationScope $scope): JsonResponse
    {
        $permissions = $request->attributes->get('coreerp.permissions', []);
        abort_unless(in_array('management-aset.jenis-aset.read', $permissions, true), 403);

        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        abort_unless(
            $this->unarchived('m_jenis_aset')->where('tenant_id', $tenantId)->where('id', $jenisAsetId)->exists(),
            404,
        );

        $mayReadModels = in_array('management-aset.model-aset.read', $permissions, true);
        $mayReadAssets = in_array('management-aset.aset.read', $permissions, true);

        return response()->json(['data' => [
            'atribut_count' => $this->unarchived('m_jenis_aset_atribut')
                ->where('tenant_id', $tenantId)
                ->where('jenis_aset_id', $jenisAsetId)
                ->count(),
            'maintenance_job_type_count' => $this->unarchived('m_maintenance_job_type_asset_type')
                ->where('tenant_id', $tenantId)
                ->where('jenis_aset_id', $jenisAsetId)
                ->count(),
            'model_count' => $mayReadModels
                ? $this->unarchived('m_model_aset')
                    ->where('tenant_id', $tenantId)
                    ->where('jenis_aset_id', $jenisAsetId)
                    ->count()
                : null,
            'models' => $mayReadModels
                ? $this->models($tenantId, $jenisAsetId)
                : null,
            'available_models' => $mayReadModels
                ? $this->models($tenantId)
                : null,
            // Aset dibatasi tanggung jawab organisasi, bukan hanya tenant. Hitungannya
            // wajib melewati penyaring yang sama dengan jalur baca aset biasa; kalau tidak,
            // angka ini menjadi jalan pintas untuk mengetahui keberadaan aset di unit kerja
            // yang tidak boleh dilihat pemanggil.
            'asset_count' => $mayReadAssets
                ? $scope->assetQuery(
                    $this->unarchived('tr_penerimaan_aset')
                        ->where('tenant_id', $tenantId)
                        ->where('jenis_aset_id', $jenisAsetId),
                    $request,
                )->count()
                : null,
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private function models(string $tenantId, ?string $jenisAsetId = null): array
    {
        return $this->unarchived('m_model_aset')
            ->leftJoin('m_pabrikan_aset as pabrikan', function ($join) use ($tenantId): void {
                $join->on('pabrikan.id', '=', 'm_model_aset.pabrikan_aset_id')
                    ->where('pabrikan.tenant_id', $tenantId)
                    ->whereNull('pabrikan.deleted_at');
            })
            ->where('m_model_aset.tenant_id', $tenantId)
            ->when(
                $jenisAsetId,
                fn ($query) => $query->where('m_model_aset.jenis_aset_id', $jenisAsetId),
                fn ($query) => $query->whereNull('m_model_aset.jenis_aset_id')->where('m_model_aset.aktif', true),
            )
            ->orderBy('pabrikan.nama')
            ->orderBy('m_model_aset.nama')
            ->get([
                'm_model_aset.id',
                'pabrikan.nama as manufacturer',
                'm_model_aset.nama as model',
                'm_model_aset.model_number',
                'm_model_aset.keterangan as description',
            ])
            ->map(static fn (object $model): array => [
                'id' => (string) $model->id,
                'manufacturer' => $model->manufacturer,
                'model' => $model->model,
                'model_number' => $model->model_number,
                'description' => $model->description,
            ])
            ->all();
    }

    /**
     * Baris terarsip tidak ikut dihitung. Pemeriksaan kolomnya mengikuti pola
     * `MasterDataController::unarchivedChild()` supaya tabel yang belum mengenal soft
     * delete tidak membuat kueri ini gagal.
     */
    private function unarchived(string $table): Builder
    {
        $query = DB::table($table);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull($table.'.deleted_at');
        }

        return $query;
    }
}
