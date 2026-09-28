<?php

namespace Modules\Apperp\ManagementAset\Support;

/**
 * Status dokumen penerimaan aset.
 *
 * Dua saja, sama seperti mutasi. Draf adalah dokumen yang sedang disiapkan dan belum
 * melahirkan aset apa pun; selesai berarti asetnya sudah terdaftar, bernomor, dan mulai
 * disusutkan.
 *
 * Batasnya sengaja tajam: tidak ada keadaan setengah jalan di mana sebagian baris sudah
 * melahirkan aset dan sebagian belum. Yang membuat batas itu penting adalah nomor aset —
 * ia diambil dari number sequence dan tidak dapat dikembalikan, jadi ia baru boleh
 * diambil ketika seluruh dokumen sudah benar.
 */
final class PenerimaanStatus
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
     * Dokumen selesai tidak dapat disunting: asetnya sudah ada, sudah punya kode, dan
     * mungkin sudah dimutasi atau dipelihara. Salah jumlah dikoreksi dengan melepas aset
     * yang kelebihan, salah nilai dikoreksi lewat layar aset — bukan dengan menyunting
     * bukti penerimaannya.
     */
    public static function dapatDisunting(string $status): bool
    {
        return $status === self::DRAFT;
    }
}
