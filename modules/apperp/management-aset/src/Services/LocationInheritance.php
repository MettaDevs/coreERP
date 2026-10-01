<?php

namespace Modules\Apperp\ManagementAset\Services;

use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use RuntimeException;
use stdClass;

/**
 * Alamat dan unit kerja bawaan yang diwarisi sebuah lokasi aset dari lokasi induknya.
 *
 * Padanan Address pada functional location Dynamics 365: sub-lokasi tanpa alamat memakai alamat lokasi
 * induknya. Aturan yang sama dipakai untuk unit kerja bawaan, supaya cukup
 * lantainya yang diisi dan ruang-ruang di bawahnya ikut.
 *
 * Polanya sama dengan {@see LocationDimension} (dimensi keuangan): nilai milik lokasi itu sendiri lebih
 * dulu, lalu leluhur terdekat yang mengisinya. Lokasi yang diarsipkan tetap mata rantai yang sah. Kelas
 * itu sengaja tidak diubah; keduanya menjawab pertanyaan berbeda.
 *
 * Nilainya dihitung saat dibaca, tidak disalin ke lokasi anak: memindahkan lokasi ke induk lain atau
 * mengubah alamat induk langsung berlaku bagi seluruh anaknya.
 */
final class LocationInheritance
{
    public const ADDRESS = 'alamat_id';

    public const DEFAULT_DEPARTMENT = 'departemen_bawaan_id';

    private const COLUMNS = [self::ADDRESS, self::DEFAULT_DEPARTMENT];

    /**
     * Nilai efektif `$column` untuk sebuah lokasi beserta lokasi pemiliknya, atau `null` bila tidak ada
     * lokasi di jalur ke akar yang mengisinya.
     *
     * `$tree` opsional: peta seluruh lokasi tenant dari {@see self::tree()}, dipakai layar daftar supaya
     * dua puluh baris tidak memanjat pohon dengan dua puluh rangkaian query.
     *
     * @param  array<string, stdClass>|null  $tree
     * @return array{value: string, location_id: string}|null
     */
    public function nearest(?string $locationId, string $column, ?array $tree = null): ?array
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new RuntimeException("Kolom lokasi `{$column}` tidak diwariskan.");
        }

        $visited = [];
        $current = $locationId;
        for ($depth = 0; $current !== null && $current !== ''; $depth++) {
            if ($depth >= LocationDimension::MAX_DEPTH || isset($visited[$current])) {
                // Penulisan lokasi menolak siklus, jadi sampai di sini berarti datanya rusak.
                report(new RuntimeException(sprintf(
                    'Pohon lokasi aset berputar atau terlalu dalam di sekitar lokasi %s; %s tidak diwarisi.',
                    $current,
                    $column,
                )));

                return null;
            }
            $visited[$current] = true;

            $location = $tree === null
                ? LokasiAset::withTrashed()->whereKey($current)->toBase()->first(['parent_id', $column])
                : ($tree[$current] ?? null);
            if ($location === null) {
                return null;
            }
            if ($location->{$column} !== null) {
                return ['value' => (string) $location->{$column}, 'location_id' => $current];
            }
            $current = $location->parent_id === null ? null : (string) $location->parent_id;
        }

        return null;
    }

    /** Unit kerja bawaan efektif sebuah lokasi; `null` bila tidak ada yang mengisinya. */
    public function defaultDepartment(?string $locationId): ?string
    {
        return $this->nearest($locationId, self::DEFAULT_DEPARTMENT)['value'] ?? null;
    }

    /**
     * Seluruh lokasi tenant aktif, termasuk yang diarsipkan, berkunci id. Satu query untuk satu layar.
     *
     * Tiap baris membawa `kode`, `nama`, `parent_id`, `alamat_id`, dan `departemen_bawaan_id`.
     *
     * @return array<string, stdClass>
     */
    public function tree(): array
    {
        $tree = [];
        foreach (LokasiAset::withTrashed()->toBase()->get(['id', 'kode', 'nama', 'parent_id', self::ADDRESS, self::DEFAULT_DEPARTMENT]) as $row) {
            $tree[(string) $row->id] = $row;
        }

        return $tree;
    }
}
