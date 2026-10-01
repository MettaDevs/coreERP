<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting;

use App\Platform\Modules\Contracts\FieldFilterExpression;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;
use App\Platform\Modules\Contracts\InvalidFilterExpression;
use Illuminate\Contracts\Database\Query\Builder;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeVariant;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\master\PabrikanAset;
use Modules\Apperp\ManagementAset\Models\master\ProfilPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\SebabKerusakan;
use Modules\Apperp\ManagementAset\Models\master\TindakanPerbaikan;
use Modules\Apperp\ManagementAset\Models\master\TingkatLayanan;
use Modules\Apperp\ManagementAset\Models\master\TipeWorkOrder;
use Modules\Apperp\ManagementAset\Models\master\Trade;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;

/**
 * Filter tambahan pengguna pada kolom data item laporan (K-30), padanan "+ Filter" di request page BC.
 *
 * Bentuk parameternya `filters[<data item>][<kolom>]`: ekspresi BC (`100..200`, `A|B`, `*teks*`) untuk teks,
 * angka, dan tanggal, atau daftar nilai untuk pilihan, ya/tidak, dan rujukan. Setiap definisi laporan
 * memanggil {@see apply()} pada query data item-nya; filter hanya mempersempit, jadi kebijakan data organisasi
 * dan batasan laporan tetap berlaku. Kolom yang tidak ada di katalog tabelnya ditolak, bukan diabaikan.
 */
final class AdditionalFilters
{
    public const PARAMETER = 'filters';

    /** Placeholder kepala laporan yang menyebut filter tambahan, ditambahkan otomatis ke laporan ber-data item. */
    public const HEADER_FIELD = 'filter_tambahan';

    /** Model dan kolom nama pilihan untuk kepala laporan, per resource pemilih rujukan. */
    private const LOOKUP_MODELS = [
        'group-aset' => [GroupAset::class, 'nama'],
        'jenis-aset' => [JenisAset::class, 'nama'],
        'lokasi-aset' => [LokasiAset::class, 'nama'],
        'kondisi-aset' => [KondisiAset::class, 'nama'],
        'reference-data/kelompok-harta-fiskal' => [KelompokHartaFiskal::class, 'label'],
        'pabrikan-aset' => [PabrikanAset::class, 'nama'],
        'model-aset' => [ModelAset::class, 'nama'],
        'aset' => [Aset::class, 'nama'],
        'buku-penyusutan' => [BukuPenyusutan::class, 'nama'],
        'profil-penyusutan' => [ProfilPenyusutan::class, 'nama'],
        'tipe-work-order' => [TipeWorkOrder::class, 'nama'],
        'tingkat-layanan' => [TingkatLayanan::class, 'nama'],
        'maintenance-job-types' => [MaintenanceJobType::class, 'nama'],
        'maintenance-job-type-variants' => [MaintenanceJobTypeVariant::class, 'nama'],
        'trade' => [Trade::class, 'nama'],
        'sebab-kerusakan' => [SebabKerusakan::class, 'nama'],
        'tindakan-perbaikan' => [TindakanPerbaikan::class, 'nama'],
    ];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            self::PARAMETER => ['nullable', 'array'],
            self::PARAMETER.'.*' => ['array', 'max:50'],
        ];
    }

    /**
     * Menerapkan filter tambahan satu data item ke query-nya.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidFilterExpression
     */
    public static function apply(Builder $query, ReportDataItem $item, array $parameters, ReportContext $context): void
    {
        $fields = $item->fields();
        foreach (self::valuesFor($item, $parameters) as $column => $value) {
            FieldFilterExpression::apply($query, $fields[$column], $value, $context->timezone);
        }
    }

    /**
     * Apakah pengguna mengisi filter tambahan pada data item ini. Laporan yang mencetak satu baris per
     * dokumen memakainya untuk menyaring dokumen lewat barisnya hanya bila baris memang difilter, supaya
     * dokumen tanpa baris tidak hilang saat filter baris kosong.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidFilterExpression
     */
    public static function active(ReportDataItem $item, array $parameters): bool
    {
        return self::valuesFor($item, $parameters) !== [];
    }

    /**
     * Memeriksa bahwa setiap data item dan kolom yang difilter dikenal laporan, sebelum datanya dibaca.
     *
     * @param  list<ReportDataItem>  $items
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidFilterExpression
     */
    public static function assertKnown(array $items, array $parameters): void
    {
        $filters = $parameters[self::PARAMETER] ?? [];
        if (! is_array($filters)) {
            throw new InvalidFilterExpression('Filter tambahan tidak dapat dibaca.');
        }
        $keys = array_map(static fn (ReportDataItem $item): string => $item->key, $items);
        foreach (array_keys($filters) as $key) {
            if (! in_array((string) $key, $keys, true)) {
                throw new InvalidFilterExpression("Laporan ini tidak punya bagian filter \"{$key}\".");
            }
        }
        foreach ($items as $item) {
            self::valuesFor($item, $parameters);
        }
    }

    /**
     * Isi kepala laporan: filter tambahan per data item dengan nama kolom dan nilainya, misalnya
     * "Aset — Lokasi: Gudang A, Gudang B; Nilai perolehan: >1000000". Kosong berarti "Tidak ada".
     *
     * @param  list<ReportDataItem>  $items
     * @param  array<string, mixed>  $parameters
     */
    public static function describe(array $items, array $parameters, ReportContext $context): string
    {
        $parts = [];
        foreach ($items as $item) {
            $fields = $item->fields();
            $conditions = [];
            foreach (self::valuesFor($item, $parameters) as $column => $value) {
                $conditions[] = $fields[$column]->caption.': '.self::valueText($fields[$column], $value, $context);
            }
            if ($conditions !== []) {
                $parts[] = $item->caption.' — '.implode('; ', $conditions);
            }
        }

        return $parts === [] ? 'Tidak ada' : implode(' · ', $parts);
    }

    /**
     * Data item dan katalog kolomnya untuk layar, lewat definisi laporan ke Core.
     *
     * @param  list<ReportDataItem>  $items
     * @return list<array{key: string, caption: string, default_fields: list<string>, fields: list<array{key: string, caption: string, type: string, options?: list<array{value: string, label: string}>, lookup?: string}>}>
     */
    public static function catalog(array $items): array
    {
        return array_map(static fn (ReportDataItem $item): array => [
            'key' => $item->key,
            'caption' => $item->caption,
            'default_fields' => $item->defaultFields,
            'fields' => array_values(array_map(static function (FilterField $field): array {
                $row = ['key' => $field->key, 'caption' => $field->caption, 'type' => $field->type->value];
                if ($field->type === FieldType::Option) {
                    $row['options'] = array_map(
                        static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                        array_keys($field->options),
                        array_values($field->options),
                    );
                }
                if ($field->lookup !== null) {
                    $row['lookup'] = $field->lookup;
                }

                return $row;
            }, $item->fields())),
        ], $items);
    }

    /**
     * Nilai filter yang terisi untuk satu data item, berkunci kolom yang dikenal katalognya.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, string|list<string>>
     *
     * @throws InvalidFilterExpression
     */
    private static function valuesFor(ReportDataItem $item, array $parameters): array
    {
        $values = $parameters[self::PARAMETER][$item->key] ?? [];
        if (! is_array($values)) {
            throw new InvalidFilterExpression("Filter bagian {$item->caption} tidak dapat dibaca.");
        }
        $fields = $item->fields();
        $filled = [];
        foreach ($values as $column => $value) {
            $column = (string) $column;
            if (! isset($fields[$column])) {
                throw new InvalidFilterExpression("Kolom \"{$column}\" tidak dapat difilter pada bagian {$item->caption}.");
            }
            if (is_array($value)) {
                $value = array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => $item !== ''));
                if ($value !== []) {
                    $filled[$column] = $value;
                }
            } elseif (is_scalar($value) && trim((string) $value) !== '') {
                $filled[$column] = trim((string) $value);
            }
        }

        return $filled;
    }

    /** @param string|list<string> $value */
    private static function valueText(FilterField $field, string|array $value, ReportContext $context): string
    {
        if (is_string($value)) {
            return $value;
        }

        return implode(', ', match ($field->type) {
            FieldType::Option => array_map(static fn (string $option): string => $field->options[$option] ?? $option, $value),
            FieldType::Boolean => array_map(static fn (string $flag): string => in_array($flag, ['1', 'true'], true) ? 'Ya' : 'Tidak', $value),
            FieldType::Reference => self::referenceNames((string) $field->lookup, $value, $context),
            default => $value,
        });
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function referenceNames(string $lookup, array $ids, ReportContext $context): array
    {
        $directory = app(AssetOrganizationDirectory::class);
        [$model, $column] = self::LOOKUP_MODELS[$lookup] ?? [null, null];
        $names = $model === null ? [] : $model::query()->whereKey($ids)->pluck($column, 'id')->all();

        return array_map(static function (string $id) use ($lookup, $names, $directory, $context): string {
            $name = match ($lookup) {
                'reference-data/unit-kerja' => $directory->unitName($context->tenantId, $id),
                'reference-data/anggota' => $directory->personName($context->tenantId, $id),
                default => $names[$id] ?? null,
            };

            return is_string($name) && $name !== '' ? $name : 'Tidak ditemukan';
        }, $ids);
    }
}
