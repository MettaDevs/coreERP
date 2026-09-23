<?php

namespace Modules\Apperp\ManagementAset\Services;

use InvalidArgumentException;
use Modules\Apperp\ManagementAset\Models\master\AssetPostingGroup;
use Modules\Apperp\ManagementAset\Support\AcquisitionMethod;

/**
 * Akun jurnal aset dari posting group, dibaca menurut tanggal posting (TODO 8.1, 8.2).
 *
 * Penerimaan, saldo awal, dan "Post penyusutan" (area 9–11) membaca akunnya dari sini, bukan
 * langsung dari tabel posting group, supaya aturan tanggal berlaku dan titik perluasan cara
 * perolehan hanya ditulis sekali.
 */
final class AssetPostingAccounts
{
    /**
     * Baris posting group yang berlaku bagi `$groupAsetId` pada tanggal posting (`Y-m-d`): baris
     * dengan `effective_from` terbesar yang tidak melewati tanggal itu. `null` bila group belum
     * punya baris yang berlaku, dan posting yang membutuhkannya tertahan di Core (K-18).
     */
    public function effective(string $groupAsetId, string $postingDate): ?AssetPostingGroup
    {
        return AssetPostingGroup::query()
            ->where('group_aset_id', $groupAsetId)
            ->whereDate('effective_from', '<=', $postingDate)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Akun harga perolehan untuk satu cara perolehan.
     *
     * Hari ini ketiga cara memakai kolom yang sama (K-12). Bila kelak hibah atau saldo awal perlu
     * akun sendiri, kolomnya ditambahkan di posting group lalu dipilih di cabang cara itu di sini,
     * tanpa mengubah penerbit mana pun.
     */
    public function acquisitionAccount(AssetPostingGroup $postingGroup, string $method): ?string
    {
        if (! in_array($method, AcquisitionMethod::ALL, true)) {
            throw new InvalidArgumentException(sprintf('Cara perolehan "%s" tidak dikenal.', $method));
        }

        return $postingGroup->acquisition_account_id;
    }
}
