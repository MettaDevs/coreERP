<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\SharedDimensionRegistry;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Modules\Contracts\FieldType;
use Closure;

/**
 * Label dimensi hasil, di kolom pendamping `<kunci>__label`. Nilai mentahnya tetap dikirim, karena
 * saringan, slicer, dan drill butuh id, bukan nama.
 *
 * | Jenis field | Sumber label |
 * | --- | --- |
 * | Rujukan module (`reference()`) | Join label di SQL, termasuk master yang sudah diarsipkan; {@see ResultSet} memetakan aliasnya |
 * | Pilihan | Peta nilai → label dari dataset |
 * | Ya/tidak | "Ya" / "Tidak" |
 * | Dimensi bersama (`shared()`) | Resolver di `SharedDimensionRegistry`, sekali per himpunan id setiap kolom |
 *
 * Label yang tidak dikenal — id yang tidak ada di tenant ini, atau nama orang yang ditahan — menjadi
 * kosong, dan layar menampilkan nilai mentahnya. Pilihan yang tidak dikenal memakai nilai mentahnya
 * sebagai label, seperti sejak area 0.
 */
final readonly class LabelResolver
{
    public function __construct(private SharedDimensionRegistry $shared) {}

    /**
     * @param  list<ResultColumn>  $columns
     * @param  list<array<string, scalar|null>>  $rows  baris yang sudah memakai kunci dataset
     * @return list<array<string, scalar|null>>
     */
    public function apply(CompiledDataset $dataset, array $columns, array $rows, AnalyticsPrincipal $principal): array
    {
        foreach ($columns as $column) {
            // Rujukan module sudah berlabel dari SQL.
            if ($column->labelKey === null || $column->labelAlias !== null) {
                continue;
            }

            $labels = $this->labelsFor($dataset, $column, $rows, $principal);
            foreach ($rows as $i => $row) {
                if (array_key_exists($column->key, $row)) {
                    $value = $row[$column->key];
                    $rows[$i][$column->labelKey] = $value === null ? null : $labels($value);
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, scalar|null>>  $rows
     * @return Closure(scalar): ?string
     */
    private function labelsFor(CompiledDataset $dataset, ResultColumn $column, array $rows, AnalyticsPrincipal $principal): Closure
    {
        $field = $dataset->filterField($column->key);

        if ($field->type === FieldType::Option) {
            return static fn (mixed $value): string => $field->options[(string) $value] ?? (string) $value;
        }
        if ($field->type === FieldType::Boolean) {
            return static fn (mixed $value): string => in_array($value, [true, 1, '1', 't', 'true'], true) ? 'Ya' : 'Tidak';
        }

        $dimension = $dataset->sharedDimension($column->key);
        $ids = [];
        foreach ($rows as $row) {
            $value = $row[$column->key] ?? null;
            if ($value !== null) {
                $ids[] = (string) $value;
            }
        }
        // Hak membaca data pribadi belum ada di principal (area 4 menambahkan `mayUsePersonalData()`):
        // sampai itu, label nama orang ditahan untuk semua, dan layar menampilkan id-nya saja.
        $labels = $dimension === null ? [] : $this->shared->labels($dimension, $principal->tenantId(), $ids, false);

        return static fn (mixed $value): ?string => $labels[(string) $value] ?? null;
    }
}
