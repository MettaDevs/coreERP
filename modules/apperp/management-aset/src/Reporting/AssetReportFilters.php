<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Filter aset yang sama pada semua laporan aset: group, kelompok harta fiskal, jenis, aset, dan
 * buku penyusutan.
 *
 * Satu tempat untuk aturan validasinya, penerapannya pada query, dan namanya di kepala laporan,
 * supaya setiap laporan menulis "Elektronik", bukan id group-nya, dengan cara yang sama. Parameter
 * lain — periode, rentang tanggal — tetap milik laporannya masing-masing.
 *
 * Buku penyusutan hanya divalidasi dan dinamai di sini. Cara menyaringnya berbeda per laporan:
 * laporan penyusutan menyaring barisnya, sedangkan laporan dokumen memilih buku yang nilainya
 * ditampilkan tanpa menghilangkan dokumennya.
 */
final class AssetReportFilters
{
    /** Kolom aset per parameter, relatif terhadap alias tabel aset di query laporan. */
    private const COLUMNS = [
        'group_aset_id' => 'group_aset_id',
        'kelompok_harta_fiskal_id' => 'kelompok_harta_fiskal_id',
        'jenis_aset_id' => 'jenis_aset_id',
        'asset_id' => 'id',
    ];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'group_aset_id' => ['nullable', 'ulid'],
            'kelompok_harta_fiskal_id' => ['nullable', 'ulid'],
            'jenis_aset_id' => ['nullable', 'ulid'],
            'asset_id' => ['nullable', 'ulid'],
            'buku_id' => ['nullable', 'ulid'],
        ];
    }

    /**
     * Placeholder kepala laporan untuk filter-filter ini.
     *
     * @return list<array{key: string, label: string, table: ?string}>
     */
    public static function fields(): array
    {
        return [
            ['key' => 'filter_group', 'label' => 'Filter group aset', 'table' => null],
            ['key' => 'filter_golongan', 'label' => 'Filter kelompok harta fiskal', 'table' => null],
            ['key' => 'filter_jenis', 'label' => 'Filter jenis aset', 'table' => null],
            ['key' => 'filter_aset', 'label' => 'Filter aset', 'table' => null],
            ['key' => 'filter_buku', 'label' => 'Buku penyusutan', 'table' => null],
        ];
    }

    /**
     * Menyaring query pada group, kelompok harta fiskal, jenis, dan aset.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $parameters
     * @param  string  $asset  Alias tabel aset di query, misalnya `aset_tr_aset`.
     */
    public static function apply(Builder $query, array $parameters, string $asset): void
    {
        foreach (self::COLUMNS as $parameter => $column) {
            if (! empty($parameters[$parameter])) {
                $query->where("{$asset}.{$column}", $parameters[$parameter]);
            }
        }
    }

    /**
     * Nama tiap filter untuk kepala laporan, bukan id-nya.
     *
     * Filter yang tidak diisi berbunyi "Semua"; buku yang tidak dipilih berarti buku komersial.
     * Id yang tidak ditemukan pada tenant ini ditulis "Tidak ditemukan", bukan id-nya.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{filter_group: string, filter_golongan: string, filter_jenis: string, filter_aset: string, filter_buku: string}
     */
    public static function names(array $parameters): array
    {
        return [
            'filter_group' => self::name($parameters['group_aset_id'] ?? null, 'Semua', static fn (string $id): mixed => GroupAset::query()->whereKey($id)->value('nama')),
            'filter_golongan' => self::name($parameters['kelompok_harta_fiskal_id'] ?? null, 'Semua', static fn (string $id): mixed => KelompokHartaFiskal::query()->whereKey($id)->value('label')),
            'filter_jenis' => self::name($parameters['jenis_aset_id'] ?? null, 'Semua', static fn (string $id): mixed => JenisAset::query()->whereKey($id)->value('nama')),
            'filter_aset' => self::name($parameters['asset_id'] ?? null, 'Semua', static function (string $id): ?string {
                $aset = Aset::query()->whereKey($id)->first(['kode', 'nama']);

                return $aset === null ? null : "{$aset->kode} — {$aset->nama}";
            }),
            'filter_buku' => self::name($parameters['buku_id'] ?? null, 'Semua buku komersial', static fn (string $id): mixed => BukuPenyusutan::query()->whereKey($id)->value('nama')),
        ];
    }

    /** @param callable(string): mixed $lookup */
    private static function name(mixed $id, string $none, callable $lookup): string
    {
        if (! is_string($id) || $id === '') {
            return $none;
        }
        $name = $lookup($id);

        return is_string($name) && $name !== '' ? $name : 'Tidak ditemukan';
    }
}
