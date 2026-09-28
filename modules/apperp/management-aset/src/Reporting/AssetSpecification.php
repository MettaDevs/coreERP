<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting;

/**
 * Kolom "Spesifikasi" pada laporan aset: model, nomor model, dan nomor seri dalam satu teks.
 *
 * Ini aturan aset, bukan aturan tampilan umum, jadi ia tinggal di module. Rupiah, tanggal,
 * dan nama bulan tidak di sini: nilai seperti itu dikirim mentah dan diformat Core
 * (`type` pada `fields()`).
 */
final class AssetSpecification
{
    /** Misalnya "Latitude 5440 D5440 (SN: 7XK2)"; "—" bila ketiganya kosong. */
    public static function describe(?string $model, ?string $modelNumber, ?string $serialNumber): string
    {
        $parts = array_filter([$model, $modelNumber], static fn (?string $part): bool => $part !== null && trim($part) !== '');
        $text = implode(' ', $parts);

        if ($serialNumber !== null && trim($serialNumber) !== '') {
            $text = $text === '' ? "SN: {$serialNumber}" : "{$text} (SN: {$serialNumber})";
        }

        return $text === '' ? '—' : $text;
    }
}
