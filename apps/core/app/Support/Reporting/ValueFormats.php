<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Support\Finance\MoneyPrecision;
use App\Support\Reporting\Rendering\RenderException;

/**
 * Format tiap placeholder bertipe pada sebuah laporan, untuk satu tenant.
 *
 * Tipe dibaca dari `fields()` definisi laporan (`'type' => 'money'`). Presisi uang dibaca
 * dari setelan mata uang tenant lewat {@see MoneyPrecision} — sumber yang sama dengan yang
 * membulatkan jurnal — jadi mengubah presisi IDR di Data referensi ikut mengubah laporan,
 * tanpa satu pun definisi laporan disentuh.
 */
final class ValueFormats
{
    public function __construct(private readonly MoneyPrecision $precision) {}

    /**
     * @param  list<array<string, mixed>>  $fields  `fields` dari definisi laporan.
     * @return array<string, ValueFormat> Per placeholder (`total`, `baris.nilai`) yang menyatakan tipe.
     *
     * @throws RenderException Tipe yang tidak dikenal: definisi laporannya yang salah, dan
     *                         kesalahannya harus terlihat saat dicoba, bukan tercetak diam-diam.
     */
    public function forFields(string $tenantId, array $fields): array
    {
        $formats = [];
        foreach ($fields as $field) {
            $type = $field['type'] ?? null;
            if ($type === null) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            if (! is_string($type) || ! in_array($type, ValueFormat::TYPES, true)) {
                throw new RenderException(sprintf(
                    'Tipe kolom `%s` pada `%s` tidak dikenal mesin laporan. Yang dikenal: %s.',
                    is_scalar($type) ? (string) $type : gettype($type),
                    $key,
                    implode(', ', ValueFormat::TYPES),
                ));
            }
            $formats[$key] = $type === ValueFormat::MONEY ? $this->money($tenantId) : new ValueFormat($type);
        }

        return $formats;
    }

    /**
     * Dataset yang nilai bertipenya sudah diganti teks tampilnya, untuk layar pratinjau.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}  $dataset
     * @return array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}
     */
    public function display(string $tenantId, array $fields, array $dataset): array
    {
        $formats = $this->forFields($tenantId, $fields);
        if ($formats === []) {
            return $dataset;
        }

        foreach ($dataset['fields'] as $key => $value) {
            if (isset($formats[$key]) && $this->scalar($value)) {
                $dataset['fields'][$key] = $formats[$key]->text($value);
            }
        }
        foreach ($dataset['tables'] as $table => $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($row as $column => $value) {
                    $format = $formats[$table.'.'.$column] ?? null;
                    if ($format !== null && $this->scalar($value)) {
                        $rows[$index][$column] = $format->text($value);
                    }
                }
            }
            $dataset['tables'][$table] = $rows;
        }

        return $dataset;
    }

    private function money(string $tenantId): ValueFormat
    {
        // Fase ini hanya IDR (K-19). Presisinya tetap dibaca dari setelan tenant; yang tetap di
        // konfigurasi hanya mata uang mana yang dimaksud dan simbolnya.
        $currency = (string) config('reporting.currency');
        $symbols = (array) config('reporting.currency_symbols');

        return new ValueFormat(
            ValueFormat::MONEY,
            $this->precision->amountDecimals($tenantId, $currency),
            (string) ($symbols[$currency] ?? $currency),
        );
    }

    /** @phpstan-assert-if-true string|int|float|null $value */
    private function scalar(mixed $value): bool
    {
        return $value === null || is_string($value) || is_int($value) || is_float($value);
    }
}
