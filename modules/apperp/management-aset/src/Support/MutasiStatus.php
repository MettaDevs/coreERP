<?php

namespace Modules\Apperp\ManagementAset\Support;

/**
 * Status dokumen mutasi aset.
 *
 * Hanya dua, dan itu disengaja. Mutasi tidak melewati persetujuan pada versi ini —
 * kendalinya adalah tanda tangan pada berita acara, sama seperti F&O yang tidak meminta
 * approval untuk memasang aset pada functional location. Draf adalah dokumen yang sedang
 * disiapkan; selesai berarti serah terimanya sudah terjadi dan penempatan aset sudah
 * berpindah.
 */
final class MutasiStatus
{
    public const DRAFT = 'draft';

    public const SELESAI = 'selesai';

    /** @return list<string> */
    public static function semua(): array
    {
        return [self::DRAFT, self::SELESAI];
    }

    /**
     * Dokumen yang masih boleh diubah isinya.
     *
     * Dokumen selesai tidak dapat disunting sama sekali: penempatan aset sudah terlanjur
     * berpindah karenanya, dan mengubah barisnya membuat riwayat penempatan menunjuk
     * dokumen yang sudah tidak menyebut aset itu lagi. Koreksi dikerjakan dengan mutasi
     * balik, bukan dengan menyunting bukti.
     */
    public static function dapatDisunting(string $status): bool
    {
        return $status === self::DRAFT;
    }
}
