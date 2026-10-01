<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Database\Query\Builder;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\FixedAssetSetup;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use stdClass;

/**
 * Nilai perolehan, akumulasi penyusutan, dan nilai buku sebuah aset bila satu aset hanya boleh punya
 * satu angka, misalnya pada pemeriksaan fisik dan ringkasan asuransi.
 *
 * Bukunya: buku penyusutan bawaan pada pengaturan aset tetap (*Default Depr. Book* BC) bila aset itu
 * memilikinya; selain itu buku komersial — buku tanpa master atau buku ber-lapisan posting `current` —
 * dan bila ada lebih dari satu, yang kodenya paling awal.
 *
 * Business Central membaca nilai asuransi dari *Insurance Depr. Book* pada *FA Setup*, yang bila
 * dikosongkan diisi *Default Depr. Book* (tabel `FA Setup`, validasi field 7). Modul ini belum punya
 * setelan buku asuransi tersendiri, jadi aturannya sama dengan bawaan BC itu.
 */
final class AssetBookValues
{
    /**
     * Satu baris per aset: `aset_id`, `acquisition_value`, `accumulated_depreciation`, `net_book_value`.
     * Untuk digabung (`leftJoinSub`) ke query aset.
     */
    public function query(): Builder
    {
        $default = FixedAssetSetup::defaultDepreciationBookId();

        return BukuAset::query()
            ->where(function ($query) use ($default): void {
                $query->whereNull('buku_id')->orWhereIn('buku_id', BukuPenyusutan::query()->where('posting_layer', 'current')->select('id'));
                if ($default !== null) {
                    $query->orWhere('buku_id', $default);
                }
            })
            ->selectRaw('distinct on (aset_id) aset_id, acquisition_value, accumulated_depreciation, net_book_value')
            ->orderBy('aset_id')
            ->when($default !== null, fn ($query) => $query->orderByRaw('buku_id is distinct from ?', [$default]))
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
