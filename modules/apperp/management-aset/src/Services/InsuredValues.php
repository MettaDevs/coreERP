<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Modules\Apperp\ManagementAset\Models\transaksi\Insurance\InsuranceCoverage;

/**
 * Nilai yang ditanggung asuransi pada satu tanggal; padanan FlowField *Total Value Insured* Business
 * Central (jumlah *Ins. Coverage Ledger Entry*).
 *
 * Satu pertanggungan terhitung pada tanggal D bila periodenya mencakup D **dan** polisnya berlaku pada D
 * serta tidak diarsipkan. Pertanggungan tanpa tanggal akhir mengikuti tanggal akhir polisnya. Polis yang
 * diblokir tetap menanggung; blokir hanya menolak pertanggungan baru, seperti *Blocked* di BC.
 */
final class InsuredValues
{
    /**
     * Total per aset: `aset_id`, `total` — untuk digabung ke query aset.
     */
    public function perAssetQuery(string $date): Builder
    {
        return $this->active($date)
            ->groupBy('aset_tr_pertanggungan_asuransi.aset_id')
            ->selectRaw('aset_tr_pertanggungan_asuransi.aset_id, sum(aset_tr_pertanggungan_asuransi.nilai_pertanggungan) as total');
    }

    /**
     * @param  list<string>  $policyIds
     * @return array<string, string> id polis => total
     */
    public function perPolicy(array $policyIds, string $date): array
    {
        if ($policyIds === []) {
            return [];
        }

        $totals = [];
        $rows = $this->active($date)
            ->whereIn('aset_tr_pertanggungan_asuransi.polis_asuransi_id', $policyIds)
            ->groupBy('aset_tr_pertanggungan_asuransi.polis_asuransi_id')
            ->selectRaw('aset_tr_pertanggungan_asuransi.polis_asuransi_id, sum(aset_tr_pertanggungan_asuransi.nilai_pertanggungan) as total')
            ->get();
        foreach ($rows as $row) {
            $totals[(string) $row->polis_asuransi_id] = number_format((float) $row->total, 2, '.', '');
        }

        return $totals;
    }

    private function active(string $date): Builder
    {
        return InsuranceCoverage::query()
            ->join('aset_m_polis_asuransi as polis', function (JoinClause $join): void {
                $join->on('polis.id', '=', 'aset_tr_pertanggungan_asuransi.polis_asuransi_id')
                    ->on('polis.tenant_id', '=', 'aset_tr_pertanggungan_asuransi.tenant_id');
            })
            ->whereNull('polis.deleted_at')
            ->where('polis.berlaku_mulai', '<=', $date)
            ->where(fn ($query) => $query->whereNull('polis.berlaku_sampai')->orWhere('polis.berlaku_sampai', '>=', $date))
            ->where('aset_tr_pertanggungan_asuransi.berlaku_mulai', '<=', $date)
            ->where(fn ($query) => $query->whereNull('aset_tr_pertanggungan_asuransi.berlaku_sampai')->orWhere('aset_tr_pertanggungan_asuransi.berlaku_sampai', '>=', $date))
            ->toBase();
    }
}
