<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Filter aset yang sama pada semua laporan aset: group, kelompok harta fiskal, jenis, lokasi, kondisi, aset,
 * dan buku penyusutan.
 *
 * Satu tempat untuk aturan validasinya, penerapannya pada query, dan namanya di kepala laporan,
 * supaya setiap laporan menulis "Elektronik", bukan id group-nya, dengan cara yang sama. Parameter
 * lain — periode, rentang tanggal — tetap milik laporannya masing-masing.
 *
 * **Filter master boleh banyak pilihan (K-28).** Group, kelompok harta fiskal, jenis, lokasi, dan kondisi
 * menerima daftar id; beberapa pilihan pada satu filter berarti *atau* (aset di Gudang A atau Gudang B), dan
 * filter yang berbeda tetap *dan*, seperti filter `A|B` pada satu field di Business Central. Satu nilai tanpa
 * daftar tetap diterima sebagai daftar berisi satu, supaya opsi dan tautan lama tetap berlaku.
 *
 * Aset dan buku penyusutan tetap satu pilihan. Buku hanya divalidasi dan dinamai di sini. Cara menyaringnya
 * berbeda per laporan: laporan penyusutan menyaring barisnya, sedangkan laporan dokumen memilih buku yang
 * nilainya ditampilkan tanpa menghilangkan dokumennya — dan dua buku sekaligus menjumlahkan aset yang sama
 * dua kali.
 */
final class AssetReportFilters
{
    /** Kolom aset per filter pilihan banyak, relatif terhadap alias tabel aset di query laporan. */
    private const LIST_COLUMNS = [
        'group_aset_id' => 'group_aset_id',
        'kelompok_harta_fiskal_id' => 'kelompok_harta_fiskal_id',
        'jenis_aset_id' => 'jenis_aset_id',
        'lokasi_aset_id' => 'lokasi_aset_id',
        'kondisi_aset_id' => 'kondisi_aset_id',
    ];

    /** Pilihan per filter paling banyak ini; lebih dari itu lebih cepat dicapai dengan tidak menyaring. */
    private const MAX_CHOICES = 100;

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        $rules = [];
        foreach (array_keys(self::LIST_COLUMNS) as $parameter) {
            $rules[$parameter] = ['nullable', 'array', 'max:'.self::MAX_CHOICES];
            $rules[$parameter.'.*'] = ['ulid'];
        }

        return [
            ...$rules,
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
            ['key' => 'filter_lokasi', 'label' => 'Filter lokasi', 'table' => null],
            ['key' => 'filter_kondisi', 'label' => 'Filter kondisi', 'table' => null],
            ['key' => 'filter_aset', 'label' => 'Filter aset', 'table' => null],
            ['key' => 'filter_buku', 'label' => 'Buku penyusutan', 'table' => null],
        ];
    }

    /**
     * Menyaring query pada group, kelompok harta fiskal, jenis, lokasi, kondisi, dan aset.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $parameters
     * @param  string  $asset  Alias tabel aset di query, misalnya `aset_tr_aset`.
     */
    public static function apply(Builder $query, array $parameters, string $asset): void
    {
        foreach (self::LIST_COLUMNS as $parameter => $column) {
            $ids = self::ids($parameters[$parameter] ?? null);
            if ($ids !== []) {
                $query->whereIn("{$asset}.{$column}", $ids);
            }
        }
        if (! empty($parameters['asset_id'])) {
            $query->where("{$asset}.id", $parameters['asset_id']);
        }
    }

    /**
     * Nama tiap filter untuk kepala laporan, bukan id-nya. Beberapa pilihan ditulis berurutan dipisah koma.
     *
     * Filter yang tidak diisi berbunyi "Semua"; buku yang tidak dipilih berarti buku komersial.
     * Id yang tidak ditemukan pada tenant ini ditulis "Tidak ditemukan", bukan id-nya.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{filter_group: string, filter_golongan: string, filter_jenis: string, filter_lokasi: string, filter_kondisi: string, filter_aset: string, filter_buku: string}
     */
    public static function names(array $parameters): array
    {
        return [
            'filter_group' => self::listNames($parameters['group_aset_id'] ?? null, static fn (array $ids): array => GroupAset::query()->whereKey($ids)->pluck('nama', 'id')->all()),
            'filter_golongan' => self::listNames($parameters['kelompok_harta_fiskal_id'] ?? null, static fn (array $ids): array => KelompokHartaFiskal::query()->whereKey($ids)->pluck('label', 'id')->all()),
            'filter_jenis' => self::listNames($parameters['jenis_aset_id'] ?? null, static fn (array $ids): array => JenisAset::query()->whereKey($ids)->pluck('nama', 'id')->all()),
            'filter_lokasi' => self::listNames($parameters['lokasi_aset_id'] ?? null, static fn (array $ids): array => LokasiAset::query()->whereKey($ids)->pluck('nama', 'id')->all()),
            'filter_kondisi' => self::listNames($parameters['kondisi_aset_id'] ?? null, static fn (array $ids): array => KondisiAset::query()->whereKey($ids)->pluck('nama', 'id')->all()),
            'filter_aset' => self::name($parameters['asset_id'] ?? null, 'Semua', static function (string $id): ?string {
                $aset = Aset::query()->whereKey($id)->first(['kode', 'nama']);

                return $aset === null ? null : "{$aset->kode} — {$aset->nama}";
            }),
            'filter_buku' => self::name($parameters['buku_id'] ?? null, 'Semua buku komersial', static fn (string $id): mixed => BukuPenyusutan::query()->whereKey($id)->value('nama')),
        ];
    }

    /** @return list<string> */
    private static function ids(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter($values, static fn (mixed $id): bool => is_string($id) && $id !== '')));
    }

    /**
     * Nama pilihan dalam urutan pilihannya.
     *
     * @param  callable(list<string>): array<array-key, mixed>  $lookup
     */
    private static function listNames(mixed $value, callable $lookup): string
    {
        $ids = self::ids($value);
        if ($ids === []) {
            return 'Semua';
        }
        $found = $lookup($ids);

        return implode(', ', array_map(
            static fn (string $id): string => is_string($found[$id] ?? null) && $found[$id] !== '' ? $found[$id] : 'Tidak ditemukan',
            $ids,
        ));
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
