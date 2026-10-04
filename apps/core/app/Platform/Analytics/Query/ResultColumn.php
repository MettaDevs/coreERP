<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\FieldType;

/**
 * Satu kolom hasil query. `alias` adalah nama kolom di SQL (`d0`, `c0`, `m0`) dan tidak pernah keluar
 * dari server; yang dikirim adalah `key`, kunci field atau measure dataset. Bentuk JSON-nya ada di
 * `docs/todo/analitik/mesin-query.md` bagian *Bentuk hasil*.
 *
 * Dimensi yang berlabel membawa `labelKey` (`<kunci>__label`): pilihan, ya/tidak, rujukan module, dan
 * dimensi bersama. Label rujukan module ikut dipilih di SQL lewat join label (`labelAlias`, `d0_label`);
 * yang lain diterjemahkan {@see LabelResolver} sesudah query. Periode dan kolom tersirat tidak berlabel.
 */
final readonly class ResultColumn
{
    public const DIMENSION = 'dimension';

    public const MEASURE = 'measure';

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

    /** Measure jumlah baris, yang dikirim sebagai bilangan bulat; measure lain dikirim sebagai teks desimal. */
    public function counts(): bool
    {
        return in_array($this->aggregate, [Aggregate::Count, Aggregate::CountDistinct], true);
    }

    /**
     * Semua isian kolom, termasuk yang tidak pernah keluar dari server (`alias`, `labelAlias`, agregat), untuk
     * cache hasil area 9: kolom yang dibaca kembali dari cache sama persis dengan yang dibuat compiler.
     *
     * @return array{alias: string, key: string, kind: 'dimension'|'measure', caption: string, type: string, format: ?string, granularity: ?string, label_key: ?string, currency_key: ?string, unit_key: ?string, implicit: bool, aggregate: ?string, label_alias: ?string}
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
        ];
    }

    /**
     * Kebalikan {@see self::toCache()}.
     *
     * @param  array{alias: string, key: string, kind: 'dimension'|'measure', caption: string, type: string, format: ?string, granularity: ?string, label_key: ?string, currency_key: ?string, unit_key: ?string, implicit: bool, aggregate: ?string, label_alias: ?string}  $data
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
        ], static fn (string|bool|null $value): bool => $value !== null && $value !== false);
    }
}
