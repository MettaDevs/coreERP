<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\VendorDirectory;
use Illuminate\Database\Query\JoinClause;
use Modules\Apperp\ManagementAset\Models\transaksi\ServiceContract\ServiceContractLine;
use Modules\Apperp\ManagementAset\Models\transaksi\Warranty\AssetWarranty;

/**
 * Garansi dan kontrak servis yang berlaku untuk sejumlah aset pada satu tanggal.
 *
 * Dipakai work order untuk pemberitahuan, padanan notifikasi warranty agreement di F&O saat work order
 * dibuat untuk aset bergaransi dengan tanggal mulai di dalam masa garansi. Hanya informasi: work order
 * tetap boleh dibuat dan dikerjakan sendiri.
 */
final class ActiveWarranties
{
    public function __construct(private readonly VendorDirectory $vendors) {}

    /**
     * @param  list<string>  $asetIds
     * @return list<array{aset_id: string, jenis: string, referensi: ?string, vendor_nama: ?string, jenis_garansi: ?string, cakupan: ?string, berlaku_sampai: string}>
     */
    public function forAssets(string $tenantId, array $asetIds, string $date): array
    {
        if ($asetIds === []) {
            return [];
        }

        $items = [];
        $warranties = AssetWarranty::query()
            ->whereIn('aset_id', $asetIds)
            ->where('berlaku_mulai', '<=', $date)
            ->where('berlaku_sampai', '>=', $date)
            ->orderBy('berlaku_sampai')
            ->get();
        foreach ($warranties as $warranty) {
            $items[] = [
                'aset_id' => $warranty->aset_id,
                'jenis' => 'garansi',
                'referensi' => $warranty->nomor_referensi,
                'vendor_nama' => $this->vendorName($tenantId, $warranty->vendor_id),
                'jenis_garansi' => $warranty->jenis_garansi,
                'cakupan' => $warranty->catatan,
                'berlaku_sampai' => $warranty->berlaku_sampai->toDateString(),
            ];
        }

        $contracts = ServiceContractLine::query()
            ->join('aset_tr_kontrak_servis as kontrak', function (JoinClause $join): void {
                $join->on('kontrak.id', '=', 'aset_tr_kontrak_servis_aset.kontrak_servis_id')
                    ->on('kontrak.tenant_id', '=', 'aset_tr_kontrak_servis_aset.tenant_id');
            })
            ->whereNull('kontrak.deleted_at')
            ->whereIn('aset_tr_kontrak_servis_aset.aset_id', $asetIds)
            ->where('kontrak.berlaku_mulai', '<=', $date)
            ->where('kontrak.berlaku_sampai', '>=', $date)
            ->orderBy('kontrak.berlaku_sampai')
            ->toBase()
            ->get(['aset_tr_kontrak_servis_aset.aset_id', 'kontrak.kode', 'kontrak.nomor_kontrak', 'kontrak.vendor_id', 'kontrak.cakupan', 'kontrak.berlaku_sampai']);
        foreach ($contracts as $contract) {
            $items[] = [
                'aset_id' => (string) $contract->aset_id,
                'jenis' => 'kontrak_servis',
                'referensi' => (string) $contract->nomor_kontrak,
                'vendor_nama' => $this->vendorName($tenantId, (string) $contract->vendor_id),
                'jenis_garansi' => null,
                'cakupan' => $contract->cakupan === null ? null : (string) $contract->cakupan,
                'berlaku_sampai' => (string) $contract->berlaku_sampai,
            ];
        }

        return $items;
    }

    private function vendorName(string $tenantId, ?string $vendorId): ?string
    {
        return $vendorId === null ? null : ($this->vendors->find($tenantId, $vendorId)['name'] ?? null);
    }
}
