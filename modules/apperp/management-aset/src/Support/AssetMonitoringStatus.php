<?php

namespace Modules\Apperp\ManagementAset\Support;

/**
 * Status dokumen monitoring aset dan hasil tiap barisnya.
 *
 * Statusnya dua, sama seperti mutasi: draf sedang disusun dan diperiksa, selesai berarti temuannya
 * sudah dibekukan. Tidak ada persetujuan, karena menyelesaikan pemeriksaan tidak mengubah register
 * aset sama sekali.
 *
 * Hasil baris dihitung sistem, tidak dipilih pemeriksa: pemeriksa hanya mencatat apa yang ia lihat
 * (ada atau tidak ada), dan kecocokan dengan register adalah perbandingan yang sama untuk semua
 * orang. Membiarkan orang memilih "sesuai" sendiri berarti laporan yang isinya pendapat.
 */
final class AssetMonitoringStatus
{
    public const DRAFT = 'draft';

    public const COMPLETED = 'selesai';

    public const MATCH = 'sesuai';

    public const MISMATCH = 'tidak_sesuai';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::COMPLETED];
    }

    /** @return list<string> */
    public static function results(): array
    {
        return [self::MATCH, self::MISMATCH];
    }

    /**
     * Dokumen yang masih boleh diubah isinya. Dokumen selesai dikunci: koreksinya pemeriksaan baru,
     * bukan menyunting temuan yang sudah menjadi dasar laporan.
     */
    public static function editable(string $status): bool
    {
        return $status === self::DRAFT;
    }

    /**
     * Hasil satu baris: cocok bila keberadaan fisiknya sama dengan yang diharapkan register.
     *
     * Aset yang sudah didekomisioning atau dilepas diharapkan tidak ada lagi; aset lain diharapkan
     * ada. Baris yang belum diperiksa belum punya hasil.
     */
    public static function result(?string $lifecycleState, ?bool $present): ?string
    {
        if ($present === null) {
            return null;
        }

        return StatusAset::expectedOnSite($lifecycleState) === $present ? self::MATCH : self::MISMATCH;
    }
}
