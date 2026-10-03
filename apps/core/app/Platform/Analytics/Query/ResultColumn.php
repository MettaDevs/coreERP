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
        public bool $counts = false,
    ) {}

    public static function dimension(string $alias, Dimension $dimension, CompiledDataset $dataset): self
    {
        $field = $dataset->filterField($dimension->field);

        return new self(
            alias: $alias,
            key: $field->key,
            kind: self::DIMENSION,
            caption: $field->caption,
            type: $dimension->granularity === null ? $field->type->value : 'period',
            granularity: $dimension->granularity?->value,
            // Label pilihan dikirim di kolom pendamping; nilai mentahnya tetap dikirim untuk saringan dan drill.
            labelKey: $field->type === FieldType::Option ? $field->key.'__label' : null,
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
            counts: in_array($measure->aggregate, [Aggregate::Count, Aggregate::CountDistinct], true),
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
