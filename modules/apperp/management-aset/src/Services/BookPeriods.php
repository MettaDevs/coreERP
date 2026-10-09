<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;

/**
 * Keadaan penyusutan satu buku aset yang menahan perubahan nilai bukunya pada sebuah tanggal: pelepasan
 * aset dan posting penyesuaian nilai.
 *
 * Aturannya sama untuk keduanya, dan padanannya urutan posting Business Central: penyusutan dihitung lebih
 * dulu sampai tanggal transaksi, lalu nilai buku pada tanggal itu yang dipakai.
 *
 * - **Usulan yang belum difinalkan menahan.** Nilainya dihitung dari nilai buku saat diusulkan; sesudah
 *   nilai buku berubah, nilai itu tidak benar lagi, dan usulan tidak dapat dibuang — hanya difinalkan.
 * - **Periode final yang berakhir sesudah tanggal transaksi menahan**, kecuali sudah dibalik. Penyusutan
 *   sesudah aset dilepas atau sesudah nilainya disesuaikan dihitung dari nilai buku yang salah.
 *
 * Periode yang berakhir pada atau sebelum tanggal transaksi tidak diperiksa apakah sudah di-post ke
 * finance: proses "Post penyusutan" tetap mengirimnya kemudian, bertanggal akhir periodenya, sehingga buku
 * besar akhirnya sama dengan register.
 */
final class BookPeriods
{
    /** Kalimat masalah untuk satu buku aset, atau `null` bila nilai bukunya boleh berubah pada `$date`. */
    public function problem(string $bukuAsetId, string $date, string $assetCode): ?string
    {
        $base = DepreciationPeriod::query()->where('buku_aset_id', $bukuAsetId)->whereNull('reverses_period_id')->whereNull('cancelled_at');

        if ((clone $base)->where('status', 'proposed')->exists()) {
            return sprintf(
                'Aset %s masih punya usulan penyusutan yang belum difinalkan. Finalkan dulu usulannya di layar Penyusutan aset.',
                $assetCode,
            );
        }

        $later = (clone $base)
            ->where('status', 'final')
            ->whereDate('period_ends_on', '>', $date)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('aset_tr_penyusutan_aset as pembalik')
                    ->whereColumn('pembalik.tenant_id', 'aset_tr_penyusutan_aset.tenant_id')
                    ->whereColumn('pembalik.reverses_period_id', 'aset_tr_penyusutan_aset.id');
            })
            ->max('period_ends_on');
        if ($later !== null) {
            return sprintf(
                'Aset %s sudah disusutkan sampai %s, sesudah tanggal %s. Balik dulu penyusutan sesudah tanggal itu, atau pakai tanggal yang lebih baru.',
                $assetCode,
                Carbon::parse((string) $later)->format('d/m/Y'),
                Carbon::parse($date)->format('d/m/Y'),
            );
        }

        return null;
    }
}
