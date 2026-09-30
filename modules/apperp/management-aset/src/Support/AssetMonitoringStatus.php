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
     * Hasil satu baris: cocok bila temuan pemeriksa sama dengan yang diharapkan register.
     *
     * Aset yang ditemukan ada wajib aktif **dan** tercatat di lokasi yang diperiksa: aset yang sudah
     * didekomisioning atau dilepas seharusnya tidak ada lagi, dan aset yang tercatat di lokasi lain
     * berarti catatan lokasinya salah (keputusan 3, diperluas 30 September 2026). Aset yang tidak
     * ditemukan dinilai menurut status siklus hidupnya saja: yang sudah didekomisioning atau dilepas
     * memang diharapkan tidak ada. Baris yang belum diperiksa belum punya hasil.
     */
    public static function result(?string $lifecycleState, ?bool $present, ?string $registeredLocationId, string $checkedLocationId): ?string
    {
        if ($present === null) {
            return null;
        }

        $expectedOnSite = StatusAset::expectedOnSite($lifecycleState);
        $match = $present
            ? $expectedOnSite && $registeredLocationId === $checkedLocationId
            : ! $expectedOnSite;

        return $match ? self::MATCH : self::MISMATCH;
    }

    /**
     * Keterangan otomatis untuk aset yang ditemukan di lokasi yang diperiksa padahal tercatat di tempat
     * lain; `null` bila lokasinya cocok atau asetnya tidak ditemukan. Pemeriksa tetap boleh menggantinya.
     */
    public static function locationNote(?bool $present, ?string $registeredLocationId, string $checkedLocationId, ?string $registeredLocationName): ?string
    {
        if ($present !== true || $registeredLocationId === $checkedLocationId) {
            return null;
        }

        return $registeredLocationId === null
            ? 'Belum tercatat di lokasi mana pun'
            : 'Tercatat di '.($registeredLocationName ?? 'lokasi lain');
    }
}
