<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAsetAtribut;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeAssetType;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

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
 * Jumlah atribut tidak diperlakukan begitu. `aset_m_jenis_aset_atribut` adalah milik jenis aset
 * itu sendiri, disunting di dalam formnya, jadi izin jenis aset sudah cukup.
 */
class JenisAsetDetailController extends Controller
{
    public function __invoke(Request $request, string $jenisAsetId, OrganizationScope $scope): JsonResponse
    {
        $permissions = $request->attributes->get('coreerp.permissions', []);
        abort_unless(in_array('management-aset.jenis-aset.read', $permissions, true), 403);

        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        abort_unless(JenisAset::query()->whereKey($jenisAsetId)->exists(), 404);

        $mayReadModels = in_array('management-aset.model-aset.read', $permissions, true);
        $mayReadAssets = in_array('management-aset.aset.read', $permissions, true);

        return response()->json(['data' => [
            'atribut_count' => JenisAsetAtribut::query()
                ->where('jenis_aset_id', $jenisAsetId)
                ->count(),
            'maintenance_job_type_count' => MaintenanceJobTypeAssetType::query()
                ->where('jenis_aset_id', $jenisAsetId)
                ->count(),
            'model_count' => $mayReadModels
                ? ModelAset::query()
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
                    Asset::query()->where('jenis_aset_id', $jenisAsetId),
                    $request,
                )->count()
                : null,
        ]]);
    }

    /**
     * Daftar model, terurut menurut nama pabrikannya.
     *
     * Pabrikan tetap di-`join`, bukan dimuat sebagai relasi: urutannya ditentukan kolom
     * milik pabrikan, dan itu tidak dapat dilakukan setelah baris terlanjur terambil.
     * Tabel yang di-`join` berada di luar jangkauan scope tenant, jadi penyaringan tenant
     * dan soft delete pabrikan ditulis di klausa `join` — persis seperti sebelumnya.
     *
     * @return list<array<string, mixed>>
     */
    private function models(string $tenantId, ?string $jenisAsetId = null): array
    {
        return ModelAset::query()
            ->leftJoin('aset_m_pabrikan_aset as pabrikan', function ($join) use ($tenantId): void {
                $join->on('pabrikan.id', '=', 'aset_m_model_aset.pabrikan_aset_id')
                    ->where('pabrikan.tenant_id', $tenantId)
                    ->whereNull('pabrikan.deleted_at');
            })
            ->when(
                $jenisAsetId,
                fn ($query) => $query->where('aset_m_model_aset.jenis_aset_id', $jenisAsetId),
                fn ($query) => $query->whereNull('aset_m_model_aset.jenis_aset_id')->where('aset_m_model_aset.aktif', true),
            )
            ->orderBy('pabrikan.nama')
            ->orderBy('aset_m_model_aset.nama')
            ->get([
                'aset_m_model_aset.id',
                'pabrikan.nama as manufacturer',
                'aset_m_model_aset.nama as model',
                'aset_m_model_aset.model_number',
                'aset_m_model_aset.keterangan as description',
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
}
