<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Actions;

use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\TimeGranularity;

/** Membaca pilihan kelompok yang datang dari request atau parameter ekspor tersimpan. */
final class DrillValues
{
    /**
     * @return list<array{field: string, value: scalar|null, granularity?: string|null}>
     */
    public static function parse(mixed $input, string $path = 'values'): array
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) > 20) {
            throw AnalyticsQueryException::invalidQuery($path, 'Pilihan baris tidak dapat dibaca. Muat ulang dasbor.');
        }

        $values = [];
        foreach ($input as $index => $item) {
            $field = is_array($item) ? ($item['field'] ?? null) : null;
            $value = is_array($item) ? ($item['value'] ?? null) : null;
            $granularity = is_array($item) ? ($item['granularity'] ?? null) : null;
            if (! is_array($item)
                || array_diff(array_keys($item), ['field', 'value', 'granularity']) !== []
                || ! is_string($field) || $field === '' || mb_strlen($field) > 64
                || ! array_key_exists('value', $item)
                || (! is_scalar($value) && $value !== null)
                || ($granularity !== null && (! is_string($granularity) || TimeGranularity::tryFrom($granularity) === null))) {
                throw AnalyticsQueryException::invalidQuery("{$path}.{$index}", 'Pilihan baris tidak dapat dibaca. Muat ulang dasbor.');
            }

            $parsed = ['field' => $field, 'value' => $value];
            if (array_key_exists('granularity', $item)) {
                $parsed['granularity'] = $granularity;
            }
            $values[] = $parsed;
        }

        return $values;
    }
}
