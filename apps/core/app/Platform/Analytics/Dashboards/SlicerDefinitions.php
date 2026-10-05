<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Dashboards;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetCatalog;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\PersonalDataGate;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\FieldFilterExpression;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;
use App\Platform\Modules\Contracts\InvalidFilterExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Validasi definisi slicer dan terjemahannya menjadi filter terkunci per permintaan. */
final class SlicerDefinitions
{
    public const MAX_SLICERS = 12;

    public function __construct(
        private readonly DatasetRegistry $datasets,
        private readonly DatasetCatalog $catalog,
        private readonly DatasetAccess $access,
        private readonly PersonalDataGate $personalData,
    ) {}

    /**
     * @return list<array{key: string, title: string, source: array{type: 'field', dataset: string, field: string}|array{type: 'shared', dimension: string}, control: 'multi_select'|'date_range'|'expression', default_value: string|list<string>|null}>
     */
    public function validate(mixed $input, AnalyticsPrincipal $principal): array
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) > self::MAX_SLICERS) {
            throw ValidationException::withMessages(['slicers' => ['Dasbor dapat memiliki paling banyak '.self::MAX_SLICERS.' saringan.']]);
        }

        $out = [];
        $keys = [];
        foreach ($input as $index => $raw) {
            $path = "slicers.{$index}";
            if (! is_array($raw) || array_diff(array_keys($raw), ['key', 'title', 'source', 'control', 'default_value']) !== []) {
                throw ValidationException::withMessages([$path => ['Bentuk saringan tidak dikenal.']]);
            }

            $key = $raw['key'] ?? null;
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || isset($keys[$key])) {
                throw ValidationException::withMessages(["{$path}.key" => ['Kunci saringan harus unik, diawali huruf kecil, dan hanya berisi huruf, angka, atau garis bawah.']]);
            }
            $keys[$key] = true;

            $title = $raw['title'] ?? null;
            if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 80) {
                throw ValidationException::withMessages(["{$path}.title" => ['Beri nama saringan, maksimal 80 karakter.']]);
            }

            $source = $this->source($raw['source'] ?? null, $principal, "{$path}.source");
            $control = $raw['control'] ?? null;
            if (! in_array($control, ['multi_select', 'date_range', 'expression'], true)) {
                throw ValidationException::withMessages(["{$path}.control" => ['Pilih jenis saringan yang tersedia.']]);
            }

            $candidates = $this->sourceFields($source, $principal);
            if ($candidates === []) {
                throw ValidationException::withMessages(["{$path}.source" => ['Kolom ini tidak tersedia untuk saringan.']]);
            }
            foreach ($candidates as [$dataset, $field]) {
                if (! $this->supports($control, $field, $dataset, $source)) {
                    throw ValidationException::withMessages(["{$path}.control" => ['Jenis saringan ini tidak cocok dengan kolom yang dipilih.']]);
                }
            }

            $default = $this->defaultValue($raw['default_value'] ?? null, $control, "{$path}.default_value");
            if ($default !== null) {
                foreach ($candidates as [, $field]) {
                    $this->assertReadableFilter($field, $default, $principal->timezone(), "{$path}.default_value");
                }
            }

            $out[] = ['key' => $key, 'title' => trim($title), 'source' => $source, 'control' => $control, 'default_value' => $default];
        }

        return $out;
    }

    /**
     * Resolve slicer values and temporary cross-filters for one widget. Untrusted filters are validated
     * before they enter the principal, because principal filters are intentionally applied before query filters.
     *
     * @param  array<string, mixed>  $widgetQuery
     * @return array<string, string|list<string>>
     */
    public function filtersForWidget(mixed $definitions, mixed $values, mixed $crossFilters, CompiledDataset $dataset, array $widgetQuery, AnalyticsPrincipal $principal): array
    {
        $definitions = $this->storedDefinitions($definitions);
        $values = $this->valueMap($values, 's');
        $crossFilters = $this->valueMap($crossFilters, 'c');
        $known = array_fill_keys(array_column($definitions, 'key'), true);
        foreach (array_keys($values) as $key) {
            if (! isset($known[$key])) {
                throw AnalyticsQueryException::invalidQuery('s.'.$key, 'Saringan ini tidak tersedia di dasbor. Muat ulang halaman.');
            }
        }

        $filters = [];
        foreach ($definitions as $definition) {
            $fieldKey = $this->fieldFor($definition['source'], $dataset, $widgetQuery);
            if ($fieldKey === null) {
                continue;
            }
            $value = array_key_exists($definition['key'], $values)
                ? $this->activeValue($values[$definition['key']], $definition['control'], $definition['key'])
                : $definition['default_value'];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $filters[$fieldKey] = $this->merge($filters[$fieldKey] ?? null, $value, $fieldKey);
        }

        foreach ($crossFilters as $fieldKey => $value) {
            if (! $dataset->hasField($fieldKey)) {
                throw AnalyticsQueryException::fieldUnknown('cross_filters.'.$fieldKey, $fieldKey);
            }
            if ($value === '' || $value === []) {
                continue;
            }
            $filters[$fieldKey] = $this->merge($filters[$fieldKey] ?? null, $value, $fieldKey);
        }

        $this->validateLockedFilters($dataset, $filters, $principal);

        return $filters;
    }

    /** @param array<string, string|list<string>> $filters */
    public function validateLockedFilters(CompiledDataset $dataset, array $filters, AnalyticsPrincipal $principal): void
    {
        foreach ($filters as $fieldKey => $value) {
            if (! $dataset->hasField($fieldKey)) {
                throw AnalyticsQueryException::fieldUnknown('filters.'.$fieldKey, $fieldKey);
            }
            $this->assertPersonalDataAllowed($dataset, $principal, $fieldKey, $fieldKey);
            $this->assertReadableFilter($dataset->filterField($fieldKey), $value, $principal->timezone(), 'filters.'.$fieldKey);
        }
    }

    /**
     * @param  array{type: 'field', dataset: string, field: string}|array{type: 'shared', dimension: string}  $source
     * @param  array<string, mixed>  $query
     */
    public function fieldFor(array $source, CompiledDataset $dataset, array $query): ?string
    {
        if ($source['type'] === 'field') {
            return $source['dataset'] === $dataset->code && $dataset->hasField($source['field'])
                ? $source['field']
                : null;
        }

        $matching = [];
        foreach ($dataset->fields() as $key => $_field) {
            if ($dataset->sharedDimension($key)?->value === $source['dimension']) {
                $matching[] = $key;
            }
        }
        if (count($matching) <= 1) {
            return $matching[0] ?? null;
        }

        $used = $this->queryFields($query);
        $selected = array_values(array_intersect($matching, $used));

        return count($selected) === 1 ? $selected[0] : null;
    }

    /** @param array{type: 'field', dataset: string, field: string}|array{type: 'shared', dimension: string} $source
     * @return list<array{0: CompiledDataset, 1: FilterField}>
     */
    private function sourceFields(array $source, AnalyticsPrincipal $principal): array
    {
        if ($source['type'] === 'field') {
            $dataset = $this->datasets->find($source['dataset']);
            if ($dataset === null || ! $this->access->allows($principal, $dataset) || ! $dataset->hasField($source['field'])) {
                return [];
            }

            return [[$dataset, $dataset->filterField($source['field'])]];
        }

        $out = [];
        foreach ($this->catalog->forPrincipal($principal) as $dataset) {
            foreach ($dataset->fields() as $key => $field) {
                if ($dataset->sharedDimension($key)?->value === $source['dimension']) {
                    $out[] = [$dataset, $field];
                }
            }
        }

        return $out;
    }

    /**
     * @return array{type: 'field', dataset: string, field: string}|array{type: 'shared', dimension: string}
     */
    private function source(mixed $input, AnalyticsPrincipal $principal, string $path): array
    {
        $source = $this->sourceShape($input);
        if ($source !== null && $this->sourceFields($source, $principal) !== []) {
            return $source;
        }

        throw ValidationException::withMessages([$path => ['Sumber saringan tidak tersedia untuk data yang boleh Anda baca.']]);
    }

    /**
     * @return array{type: 'field', dataset: string, field: string}|array{type: 'shared', dimension: string}|null
     */
    private function sourceShape(mixed $input): ?array
    {
        if (! is_array($input) || ! is_string($input['type'] ?? null)) {
            return null;
        }
        if ($input['type'] === 'field' && array_diff(array_keys($input), ['type', 'dataset', 'field']) === []) {
            $dataset = $input['dataset'] ?? null;
            $field = $input['field'] ?? null;

            return is_string($dataset) && is_string($field)
                ? ['type' => 'field', 'dataset' => $dataset, 'field' => $field]
                : null;
        }
        if ($input['type'] === 'shared' && array_diff(array_keys($input), ['type', 'dimension']) === []) {
            $dimension = $input['dimension'] ?? null;

            return is_string($dimension) && SharedDimension::tryFrom($dimension) !== null
                ? ['type' => 'shared', 'dimension' => $dimension]
                : null;
        }

        return null;
    }

    /**
     * @return list<array{key: string, title: string, source: array{type: 'field', dataset: string, field: string}|array{type: 'shared', dimension: string}, control: 'multi_select'|'date_range'|'expression', default_value: string|list<string>|null}>
     */
    private function storedDefinitions(mixed $input): array
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) > self::MAX_SLICERS) {
            throw AnalyticsQueryException::invalidQuery('slicers', 'Saringan dasbor tidak dapat dibaca. Muat ulang dasbor.');
        }

        $out = [];
        $keys = [];
        foreach ($input as $index => $raw) {
            if (! is_array($raw) || array_diff(array_keys($raw), ['key', 'title', 'source', 'control', 'default_value']) !== []) {
                throw AnalyticsQueryException::invalidQuery("slicers.{$index}", 'Saringan dasbor tidak dapat dibaca. Muat ulang dasbor.');
            }
            $key = $raw['key'] ?? null;
            $title = $raw['title'] ?? null;
            $source = $this->sourceShape($raw['source'] ?? null);
            $control = $raw['control'] ?? null;
            $default = $raw['default_value'] ?? null;
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || isset($keys[$key])
                || ! is_string($title) || trim($title) === '' || mb_strlen($title) > 80
                || $source === null
                || ! is_string($control) || ! in_array($control, ['multi_select', 'date_range', 'expression'], true)
                || ($default !== null && ! is_string($default) && (! is_array($default) || ! array_is_list($default) || array_filter($default, 'is_string') !== $default))) {
                throw AnalyticsQueryException::invalidQuery("slicers.{$index}", 'Saringan dasbor tidak dapat dibaca. Muat ulang dasbor.');
            }
            $keys[$key] = true;

            $out[] = [
                'key' => $key,
                'title' => $title,
                'source' => $source,
                'control' => $control,
                'default_value' => $default,
            ];
        }

        return $out;
    }

    /** @param 'multi_select'|'date_range'|'expression' $control
     * @param  array{type: 'field', dataset: string, field: string}|array{type: 'shared', dimension: string}  $source
     */
    private function supports(string $control, FilterField $field, CompiledDataset $dataset, array $source): bool
    {
        return match ($control) {
            'date_range' => in_array($field->type, [FieldType::Date, FieldType::DateTime], true)
                && in_array($field->key, $dataset->times(), true)
                && $source['type'] === 'field',
            'multi_select' => in_array($field->type, [FieldType::Boolean, FieldType::Option], true)
                || ($field->type === FieldType::Reference && ($field->lookup !== null
                    || in_array($dataset->sharedDimension($field->key), [SharedDimension::LegalEntity, SharedDimension::OperatingUnit], true))),
            default => in_array($field->type, [FieldType::Text, FieldType::Number, FieldType::Date, FieldType::DateTime], true),
        };
    }

    /** @return string|list<string>|null */
    private function defaultValue(mixed $value, string $control, string $path): string|array|null
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if ($control === 'multi_select') {
            if (is_string($value)) {
                $value = [$value];
            }
            if (! is_array($value) || ! array_is_list($value) || count($value) > FieldFilterExpression::MAX_SELECTIONS) {
                throw ValidationException::withMessages([$path => ['Nilai bawaan pilihan harus berupa daftar.']]);
            }
            foreach ($value as $item) {
                if (! is_string($item) || mb_strlen($item) > FieldFilterExpression::MAX_REFERENCE_LENGTH) {
                    throw ValidationException::withMessages([$path => ['Setiap nilai pilihan harus berupa teks pendek.']]);
                }
            }

            return array_values(array_unique($value));
        }
        if (! is_string($value) || mb_strlen($value) > FieldFilterExpression::MAX_LENGTH) {
            throw ValidationException::withMessages([$path => ['Nilai bawaan harus berupa teks filter yang singkat.']]);
        }

        return trim($value);
    }

    /** @return array<string, string|list<string>> */
    private function valueMap(mixed $input, string $parameter): array
    {
        if ($input === null) {
            return [];
        }
        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            throw AnalyticsQueryException::invalidQuery($parameter, 'Saringan harus berupa pasangan kunci dan nilai.');
        }
        $out = [];
        foreach ($input as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
                throw AnalyticsQueryException::invalidQuery($parameter, 'Kunci saringan tidak dikenal.');
            }
            if ($value === null || $value === '') {
                $out[$key] = '';

                continue;
            }
            if (is_string($value) && mb_strlen($value) <= FieldFilterExpression::MAX_LENGTH) {
                $out[$key] = $value;

                continue;
            }
            if (is_array($value) && array_is_list($value) && count($value) <= FieldFilterExpression::MAX_SELECTIONS) {
                foreach ($value as $item) {
                    if (! is_string($item) || mb_strlen($item) > FieldFilterExpression::MAX_REFERENCE_LENGTH) {
                        throw AnalyticsQueryException::invalidQuery($parameter.'.'.$key, 'Daftar pilihan saringan tidak sah.');
                    }
                }
                $out[$key] = $value;

                continue;
            }
            throw AnalyticsQueryException::invalidQuery($parameter.'.'.$key, 'Nilai saringan tidak sah.');
        }

        return $out;
    }

    /**
     * @return string|list<string>
     */
    private function activeValue(mixed $input, string $control, string $key): string|array
    {
        if ($input === '') {
            return '';
        }
        if ($control === 'multi_select') {
            $values = is_string($input) ? [$input] : $input;
            if (! is_array($values) || ! array_is_list($values)) {
                throw AnalyticsQueryException::invalidQuery('s.'.$key, 'Pilih nilai dari daftar.');
            }

            return array_values(array_unique($values));
        }
        if (! is_string($input)) {
            throw AnalyticsQueryException::invalidQuery('s.'.$key, 'Isian saringan harus berupa teks.');
        }

        return $input;
    }

    /** @param string|list<string>|null $existing
     * @param  string|list<string>  $value
     * @return string|list<string>
     */
    private function merge(string|array|null $existing, string|array $value, string $field): string|array
    {
        if ($existing === null || $existing === $value) {
            return $value;
        }
        if (is_array($existing) && is_array($value)) {
            return array_values(array_intersect($existing, $value));
        }

        throw AnalyticsQueryException::invalidQuery('filters.'.$field, 'Saringan yang sama saling bertentangan. Hapus salah satunya.');
    }

    /** @param array<string, mixed> $query
     * @return list<string>
     */
    private function queryFields(array $query): array
    {
        $fields = [];
        foreach (($query['dimensions'] ?? []) as $dimension) {
            $field = is_array($dimension) ? ($dimension['field'] ?? null) : $dimension;
            if (is_string($field)) {
                $fields[] = $field;
            }
        }
        foreach (array_keys(is_array($query['filters'] ?? null) ? $query['filters'] : []) as $field) {
            if (is_string($field)) {
                $fields[] = $field;
            }
        }
        if (is_array($query['time_range'] ?? null) && is_string($query['time_range']['field'] ?? null)) {
            $fields[] = $query['time_range']['field'];
        }

        return array_values(array_unique($fields));
    }

    /** @param string|list<string> $value */
    private function assertReadableFilter(FilterField $field, string|array $value, string $timezone, string $path): void
    {
        try {
            FieldFilterExpression::apply(DB::query(), $field, $value, $timezone);
        } catch (InvalidFilterExpression $exception) {
            throw AnalyticsQueryException::invalidFilter($path, $exception->getMessage(), $exception);
        }
    }

    private function assertPersonalDataAllowed(CompiledDataset $dataset, AnalyticsPrincipal $principal, string $key, string $path): void
    {
        $this->personalData->assertUsable($dataset, $principal, [$path => $key]);
    }
}
