<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use Modules\Apperp\ManagementAset\Services\DisposalPosting;

/**
 * Penjualan aset: dokumen penjualan, satu baris per dokumen. Permission `management-aset.penjualan-aset.read`;
 * kolom kebijakan dan alasan bersumber query di {@see DisposalDataset}.
 */
final class AssetSalesDataset extends DisposalDataset
{
    protected function documentType(): string
    {
        return DisposalPosting::SALE;
    }

    protected function code(): string
    {
        return 'management-aset.asset-sales';
    }

    protected function caption(): string
    {
        return 'Penjualan aset';
    }

    protected function description(): string
    {
        return 'Satu baris per dokumen penjualan aset: asetnya, tanggalnya, dan hasil penjualannya.';
    }

    protected function permission(): string
    {
        return 'management-aset.penjualan-aset.read';
    }

    protected function hasProceeds(): bool
    {
        return true;
    }
}
