<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Query\Formula\Formula;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\FieldType;

/**
 * Satu kolom hasil query. `alias` adalah nama kolom di SQL (`d0`, `c0`, `m0`) dan tidak pernah keluar
 * dari server; yang dikirim adalah `key`, kunci field atau measure dataset. Bentuk JSON-nya ada di
 * `docs/todo/analitik/mesin-query.md` bagian *Bentuk hasil*.
 *
 * Dimensi yang berlabel membawa `labelKey` (`<kunci>__label`): pilihan, ya/tidak, rujukan module, dan
 * dimensi bersama. Label rujukan module ikut dipilih di SQL lewat join label (`labelAlias`, `d0_label`);
 * yang lain diterjemahkan {@see LabelResolver} sesudah query. Periode dan kolom tersirat tidak berlabel.
 *
 * Area 13 menambah dua jenis kolom nilai. **Rumus** berjenis `measure` dengan kuncinya sendiri dan tanpa agregat,
 * jadi dikirim sebagai teks desimal dan kosong di baris isian celah. **Kolom turunan** (`derived_from` dan
 * `derivation`) mengikuti satu measure atau rumus: nilai periode pembanding (`<kunci>__previous`), selisihnya
 * (`__change`), persen perubahannya (`__change_pct`), dan persen terhadap total (`__percent_of_total`). Nilai
 * pembanding dan selisih membawa agregat, format, dan mata uang measure asalnya; kedua persen berformat
 * `percent` tanpa mata uang.
 */
final readonly class ResultColumn
{
    public const DIMENSION = 'dimension';

    public const MEASURE = 'measure';

    public const PREVIOUS = 'previous';

    public const CHANGE = 'change';

    public const CHANGE_PERCENT = 'change_pct';

    public const PERCENT_OF_TOTAL = 'percent_of_total';

    /**
     * @param  'dimension'|'measure'  $kind
     */
    public function __construct(
        public string $alias,
        public string $key,
        public string $kind,
        public string $caption,
        public string $type,
        public ?string $format = null,
        public ?string $granularity = null,
        public ?string $labelKey = null,
        public ?string $currencyKey = null,
        public ?string $unitKey = null,
        public bool $implicit = false,
        public ?Aggregate $aggregate = null,
        public ?string $labelAlias = null,
        public ?string $derivedFrom = null,
        public ?string $derivation = null,
    ) {}

    public static function dimension(string $alias, Dimension $dimension, CompiledDataset $dataset): self
    {
        $field = $dataset->filterField($dimension->field);
        $period = $dimension->granularity !== null;
        $reference = ! $period && $dataset->reference($field->key) !== null;
        // Nilai mentahnya tetap dikirim di samping label, untuk saringan, slicer, dan drill.
        $labelled = ! $period && ($reference
            || in_array($field->type, [FieldType::Option, FieldType::Boolean], true)
            || $dataset->sharedDimension($field->key) !== null);

        return new self(
            alias: $alias,
            key: $field->key,
            kind: self::DIMENSION,
            caption: $field->caption,
            type: $period ? 'period' : $field->type->value,
            granularity: $dimension->granularity?->value,
            labelKey: $labelled ? $field->key.'__label' : null,
            labelAlias: $reference ? $alias.'_label' : null,
        );
    }

    /** Kolom mata uang atau satuan yang ikut dikelompokkan walau tidak dipilih (KA-22). */
    public static function implicit(string $alias, string $column, string $fallbackCaption, CompiledDataset $dataset): self
    {
        $field = $dataset->hasField($column) ? $dataset->filterField($column) : null;

        return new self(
            alias: $alias,
            key: $column,
            kind: self::DIMENSION,
            caption: $field === null ? $fallbackCaption : $field->caption,
            type: 'text',
            implicit: true,
        );
    }

    public static function measure(string $alias, string $key, CompiledDataset $dataset): self
    {
        $measure = $dataset->measure($key);

        return new self(
            alias: $alias,
            key: $measure->key,
            kind: self::MEASURE,
            caption: $measure->caption,
            type: 'number',
            format: $measure->format->value,
            currencyKey: $measure->currency,
            unitKey: $measure->unit,
            aggregate: $measure->aggregate,
        );
    }

    /**
     * Kolom satu rumus. Mata uang dan satuannya diwarisi dari measure yang dirujuk (dimensi tersirat yang sama),
     * tetapi hanya ditulis bila formatnya uang atau kuantitas: rasio dua nilai uang adalah angka biasa.
     */
    public static function formula(string $alias, Formula $formula, ?string $currencyKey, ?string $unitKey): self
    {
        $format = $formula->format();

        return new self(
            alias: $alias,
            key: $formula->key,
            kind: self::MEASURE,
            caption: $formula->caption(),
            type: 'number',
            format: $format->value,
            currencyKey: $format === MeasureFormat::Money ? $currencyKey : null,
            unitKey: $format === MeasureFormat::Quantity ? $unitKey : null,
        );
    }

    /**
     * Kolom turunan satu measure atau rumus: periode pembanding, selisih, persen perubahan, atau persen terhadap
     * total. `$period` nama periode pembanding untuk judul kolom ("tahun lalu").
     *
     * @param  'previous'|'change'|'change_pct'|'percent_of_total'  $derivation
     */
    public static function derived(string $alias, self $base, string $derivation, string $period = ''): self
    {
        $percent = $derivation === self::CHANGE_PERCENT || $derivation === self::PERCENT_OF_TOTAL;

        return new self(
            alias: $alias,
            key: $base->key.'__'.$derivation,
            kind: self::MEASURE,
            caption: $base->caption.' ('.match ($derivation) {
                self::PREVIOUS => $period,
                self::CHANGE => 'selisih dengan '.$period,
                self::CHANGE_PERCENT => 'perubahan % dari '.$period,
                self::PERCENT_OF_TOTAL => '% dari total',
            }.')',
            type: 'number',
            format: $percent ? MeasureFormat::Percent->value : $base->format,
            currencyKey: $percent ? null : $base->currencyKey,
            unitKey: $percent ? null : $base->unitKey,
            // Selisih jumlah baris tetap bilangan bulat; persen selalu teks desimal dan kosong di baris isian celah.
            aggregate: $percent ? null : $base->aggregate,
            derivedFrom: $base->key,
            derivation: $derivation,
        );
    }

    /** Measure jumlah baris, yang dikirim sebagai bilangan bulat; measure lain dikirim sebagai teks desimal. */
    public function counts(): bool
    {
        return in_array($this->aggregate, [Aggregate::Count, Aggregate::CountDistinct], true);
    }

    /**
     * Semua isian kolom, termasuk yang tidak pernah keluar dari server (`alias`, `labelAlias`, agregat), untuk
     * cache hasil area 9: kolom yang dibaca kembali dari cache sama persis dengan yang dibuat compiler.
     *
     * @return array{alias: string, key: string, kind: 'dimension'|'measure', caption: string, type: string, format: ?string, granularity: ?string, label_key: ?string, currency_key: ?string, unit_key: ?string, implicit: bool, aggregate: ?string, label_alias: ?string, derived_from: ?string, derivation: ?string}
     */
    public function toCache(): array
    {
        return [
            'alias' => $this->alias,
            'key' => $this->key,
            'kind' => $this->kind,
            'caption' => $this->caption,
            'type' => $this->type,
            'format' => $this->format,
            'granularity' => $this->granularity,
            'label_key' => $this->labelKey,
            'currency_key' => $this->currencyKey,
            'unit_key' => $this->unitKey,
            'implicit' => $this->implicit,
            'aggregate' => $this->aggregate?->value,
            'label_alias' => $this->labelAlias,
            'derived_from' => $this->derivedFrom,
            'derivation' => $this->derivation,
        ];
    }

    /**
     * Kebalikan {@see self::toCache()}.
     *
     * @param  array{alias: string, key: string, kind: 'dimension'|'measure', caption: string, type: string, format: ?string, granularity: ?string, label_key: ?string, currency_key: ?string, unit_key: ?string, implicit: bool, aggregate: ?string, label_alias: ?string, derived_from: ?string, derivation: ?string}  $data
     */
    public static function fromCache(array $data): self
    {
        return new self(
            alias: $data['alias'],
            key: $data['key'],
            kind: $data['kind'],
            caption: $data['caption'],
            type: $data['type'],
            format: $data['format'],
            granularity: $data['granularity'],
            labelKey: $data['label_key'],
            currencyKey: $data['currency_key'],
            unitKey: $data['unit_key'],
            implicit: $data['implicit'],
            aggregate: $data['aggregate'] === null ? null : Aggregate::from($data['aggregate']),
            labelAlias: $data['label_alias'],
            derivedFrom: $data['derived_from'],
            derivation: $data['derivation'],
        );
    }

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'kind' => $this->kind,
            'caption' => $this->caption,
            'type' => $this->type,
            'format' => $this->format,
            'granularity' => $this->granularity,
            'label_key' => $this->labelKey,
            'currency_key' => $this->currencyKey,
            'unit_key' => $this->unitKey,
            'implicit' => $this->implicit,
            'derived_from' => $this->derivedFrom,
            'derivation' => $this->derivation,
        ], static fn (string|bool|null $value): bool => $value !== null && $value !== false);
    }
}
