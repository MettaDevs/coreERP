<?php

namespace Modules\Apperp\ManagementAset\Services;

use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use RuntimeException;

/**
 * Unit organisasi yang menjadi dimensi keuangan aset, diwarisi dari lokasi fisiknya (K-08,
 * TODO 8.3). Padanan toggle "Update asset dimension" pada Functional location type di F&O.
 *
 * Lokasi tanpa pemetaan mewarisi pemetaan lokasi induk terdekat: satu poli bisa tersebar di
 * beberapa lantai dan ruang, dan cukup lantainya yang dipetakan. Memindahkan aset antar ruang di
 * bawah lantai yang sama karena itu tidak mengubah jurnalnya.
 *
 * Penerimaan dan mutasi sama-sama memanggil fungsi ini, supaya keduanya tidak pernah menjawab
 * berbeda untuk lokasi yang sama.
 */
final class LocationDimension
{
    /**
     * Batas pendakian. Pohon lokasi nyata jarang lebih dari lima tingkat (site › gedung › lantai ›
     * ruang › rak); batas ini hanya ada supaya data yang rusak tidak membuat permintaan berputar.
     */
    public const MAX_DEPTH = 32;

    /**
     * `null` bila tidak ada lokasi di jalur ke akar yang dipetakan. Aset lalu memakai unit
     * penggunanya sendiri.
     *
     * Lokasi yang sudah diarsipkan tetap mata rantai yang sah dan tetap membawa pemetaannya, sama
     * seperti sebelum pewarisan ada.
     */
    public function resolve(?string $locationId): ?string
    {
        $visited = [];
        $current = $locationId;
        for ($depth = 0; $current !== null && $current !== ''; $depth++) {
            if ($depth >= self::MAX_DEPTH || isset($visited[$current])) {
                // Penulisan lokasi sudah menolak siklus, jadi sampai di sini berarti datanya rusak.
                // Dilaporkan, tanpa menggagalkan penerimaan atau mutasi yang sedang berjalan.
                report(new RuntimeException(sprintf(
                    'Pohon lokasi aset berputar atau terlalu dalam di sekitar lokasi %s; dimensi tidak diwarisi.',
                    $current,
                )));

                return null;
            }
            $visited[$current] = true;

            $location = LokasiAset::withTrashed()->whereKey($current)->toBase()->first(['org_unit_id', 'parent_id']);
            if ($location === null) {
                return null;
            }
            if ($location->org_unit_id !== null) {
                return (string) $location->org_unit_id;
            }
            $current = $location->parent_id === null ? null : (string) $location->parent_id;
        }

        return null;
    }
}
