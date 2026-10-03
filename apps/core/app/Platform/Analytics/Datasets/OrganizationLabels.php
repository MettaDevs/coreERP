<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\Analytics\SharedDimensionResolver;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Organization\Models\Organization;
use LogicException;

/**
 * Nama entitas legal atau unit kerja, dari tabel organisasi tenant. Yang sudah tidak aktif ikut, supaya
 * data lama tetap bernama; id dengan klasifikasi lain (unit kerja yang ditanyakan sebagai entitas legal)
 * tidak berlabel.
 */
final readonly class OrganizationLabels implements SharedDimensionResolver
{
    private string $classification;

    public function __construct(private SharedDimension $dimension)
    {
        $this->classification = match ($dimension) {
            SharedDimension::LegalEntity => 'legal_entity',
            SharedDimension::OperatingUnit => 'operating_unit',
            default => throw new LogicException("Dimensi {$dimension->value} bukan organisasi."),
        };
    }

    public function dimension(): SharedDimension
    {
        return $this->dimension;
    }

    public function labelClassification(): DataClass
    {
        return DataClass::OrganizationIdentifiableInformation;
    }

    public function labels(string $tenantId, array $ids): array
    {
        $labels = [];
        foreach (Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('classification', $this->classification)
            ->whereIn('id', $ids)
            ->get(['id', 'name']) as $organization) {
            $labels[(string) $organization->id] = (string) $organization->getAttribute('name');
        }

        return $labels;
    }
}
