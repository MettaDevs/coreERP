<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\PersonalDataGate;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;

/**
 * Katalog dataset untuk layar: dataset yang boleh dibaca principal ini, lalu field dan measure yang boleh ia
 * pakai. Dipakai `GET api/v1/analytics/datasets` dan pemilih data di pembangun widget (area 8).
 *
 * Yang ditawarkan sama persis dengan yang diterima mesin query: module terpasang dan berlisensi lalu
 * permission baca resource dataset (`DatasetRegistry::forTenant()`, principal), dan field serta measure
 * data pribadi disaring `PersonalDataGate` dengan aturan yang sama dengan validator query. Katalog yang
 * menawarkan lebih dari itu membuat pengguna menyusun widget yang kemudian ditolak.
 */
final class DatasetCatalog
{
    public function __construct(
        private readonly DatasetRegistry $datasets,
        private readonly PersonalDataGate $personalData,
    ) {}

    /** @return list<CompiledDataset> */
    public function forPrincipal(AnalyticsPrincipal $principal): array
    {
        return array_values(array_filter(
            $this->datasets->forTenant($principal->tenantId()),
            static fn (CompiledDataset $dataset): bool => $principal->holdsPermission($dataset->moduleId, $dataset->permission),
        ));
    }

    /** @return array{code: string, caption: string, description: ?string, module_id: string, version: int} */
    public function summary(CompiledDataset $dataset): array
    {
        return [
            'code' => $dataset->code,
            'caption' => $dataset->caption,
            'description' => $dataset->description,
            'module_id' => $dataset->moduleId,
            'version' => $dataset->version,
        ];
    }

    /**
     * Isi dataset yang boleh dipakai principal ini. Kolom kebijakan dan kolom mata uang yang tidak dinyatakan
     * sebagai field tidak pernah muncul; field waktu ditandai `time`, dimensi bersama dengan kodenya.
     *
     * @return array<string, mixed>
     */
    public function describe(CompiledDataset $dataset, AnalyticsPrincipal $principal): array
    {
        $fields = [];
        foreach ($this->personalData->visibleFields($dataset, $principal) as $field) {
            $fields[] = $this->field($dataset, $field);
        }

        $measures = [];
        foreach ($this->personalData->visibleMeasures($dataset, $principal) as $measure) {
            $measures[] = array_filter([
                'key' => $measure->key,
                'caption' => $measure->caption,
                'aggregate' => $measure->aggregate->value,
                'format' => $measure->format->value,
                'currency_key' => $measure->currency,
                'unit_key' => $measure->unit,
            ], static fn (?string $value): bool => $value !== null);
        }

        $visible = array_column($fields, 'key');
        $hierarchies = [];
        foreach ($dataset->hierarchies() as $key => $levels) {
            $visibleLevels = array_values(array_intersect($levels, $visible));
            if (count($visibleLevels) > 1) {
                $hierarchies[$key] = $visibleLevels;
            }
        }

        return [
            ...$this->summary($dataset),
            'fields' => $fields,
            'measures' => $measures,
            'times' => array_values(array_intersect($dataset->times(), $visible)),
            'hierarchies' => $hierarchies,
            'default_time' => in_array($dataset->defaultTime(), $visible, true) ? $dataset->defaultTime() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function field(CompiledDataset $dataset, FilterField $field): array
    {
        $out = [
            'key' => $field->key,
            'caption' => $field->caption,
            'type' => $field->type->value,
            'time' => in_array($field->key, $dataset->times(), true),
        ];
        if ($field->type === FieldType::Option && $field->options !== []) {
            $out['options'] = array_map(
                static fn (string|int $value, string $label): array => ['value' => (string) $value, 'label' => $label],
                array_keys($field->options),
                array_values($field->options),
            );
        }
        if ($dataset->sharedDimension($field->key) !== null) {
            $out['shared_dimension'] = $dataset->sharedDimension($field->key)->value;
        }
        if ($field->lookup !== null) {
            $out['lookup'] = $field->lookup;
        }

        return $out;
    }
}
