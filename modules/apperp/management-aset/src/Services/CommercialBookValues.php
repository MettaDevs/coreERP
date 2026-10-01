<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Database\Query\Builder;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use stdClass;

/**
 * Nilai perolehan, akumulasi penyusutan, dan nilai buku sebuah aset menurut buku komersialnya.
 *
 * Buku komersial adalah buku tanpa master atau buku ber-lapisan posting `current`; bila ada lebih dari
 * satu, yang kodenya paling awal, supaya satu aset selalu menghasilkan satu angka. Aturan ini dipakai
 * pemeriksaan fisik (nilai yang dibekukan) dan ringkasan asuransi (pembanding nilai pertanggungan).
 *
 * Business Central membaca nilai asuransi dari satu buku yang dipilih di *FA Setup* (*Insurance Depr.
 * Book*). Modul ini belum punya setelan itu; sampai ada, buku komersial yang dipakai.
 */
final class CommercialBookValues
{
    /**
     * Satu baris per aset: `aset_id`, `acquisition_value`, `accumulated_depreciation`, `net_book_value`.
     * Untuk digabung (`leftJoinSub`) ke query aset.
     */
    public function query(): Builder
    {
        return BukuAset::query()
            ->where(fn ($query) => $query->whereNull('buku_id')->orWhereIn('buku_id', BukuPenyusutan::query()->where('posting_layer', 'current')->select('id')))
            ->selectRaw('distinct on (aset_id) aset_id, acquisition_value, accumulated_depreciation, net_book_value')
            ->orderBy('aset_id')
            ->orderBy('book_code')
            ->toBase();
    }

    /**
     * @param  list<string>  $asetIds
     * @return array<string, stdClass> per aset: `acquisition_value`, `accumulated_depreciation`, `net_book_value`
     */
    public function forAssets(array $asetIds): array
    {
        if ($asetIds === []) {
            return [];
        }

        $books = [];
        foreach ($this->query()->whereIn('aset_id', $asetIds)->get() as $book) {
            $books[(string) $book->aset_id] = $book;
        }

        return $books;
    }
}
