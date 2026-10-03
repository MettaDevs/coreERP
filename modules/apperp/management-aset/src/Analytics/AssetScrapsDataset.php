<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use Modules\Apperp\ManagementAset\Services\DisposalPosting;

/**
 * Pemusnahan aset: dokumen pemusnahan, satu baris per dokumen. Permission `management-aset.pemusnahan-aset.read`;
 * kolom kebijakan dan alasan bersumber query di {@see DisposalDataset}.
 */
final class AssetScrapsDataset extends DisposalDataset
{
    protected function documentType(): string
    {
        return DisposalPosting::SCRAP;
    }

    protected function code(): string
    {
        return 'management-aset.asset-scraps';
    }

    protected function caption(): string
    {
        return 'Pemusnahan aset';
    }

    protected function description(): string
    {
        return 'Satu baris per dokumen pemusnahan aset: asetnya, tanggalnya, dan nilai perolehan aset yang dimusnahkan.';
    }

    protected function permission(): string
    {
        return 'management-aset.pemusnahan-aset.read';
    }

    protected function hasProceeds(): bool
    {
        return false;
    }
}
