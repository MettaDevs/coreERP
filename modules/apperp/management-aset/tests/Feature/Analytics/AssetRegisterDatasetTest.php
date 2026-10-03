<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksMoneyPerCurrency;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

/**
 * Dataset `management-aset.asset-register` terhadap layar daftar aset (`GET aset`): kebijakan data pada
 * unit **penanggung jawab**, bukan unit dimensi keuangan.
 */
class AssetRegisterDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.asset-register';
    }

    protected function readPermission(): string
    {
        return 'management-aset.aset.read';
    }

    protected function listResource(): string
    {
        return 'aset';
    }

    protected function dimensions(): array
    {
        return ['kode'];
    }

    protected function moneyMeasure(): string
    {
        return 'acquisition_value';
    }

    protected function expectedMoney(): array
    {
        return ['IDR' => '350000000.50', 'USD' => '1750.25'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 5, 'unitA' => 2, 'unitB' => 3];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        // Unit dimensi keuangan sengaja berlawanan dengan unit penanggung jawab pada sebagian aset.
        $this->asset($tenant, $legalEntity, "{$tag}-A1", $unitA, 'IDR', '100000000', ['financial_dimension_org_unit_id' => $unitA]);
        $this->asset($tenant, $legalEntity, "{$tag}-A2", $unitA, 'IDR', '50000000.50', ['financial_dimension_org_unit_id' => $unitB]);
        $this->asset($tenant, $legalEntity, "{$tag}-B1", $unitB, 'IDR', '200000000', ['financial_dimension_org_unit_id' => $unitA]);
        $this->asset($tenant, $legalEntity, "{$tag}-B2", $unitB, 'USD', '1500.25', ['financial_dimension_org_unit_id' => $unitB]);
        $this->asset($tenant, $legalEntity, "{$tag}-B3", $unitB, 'USD', '250', ['financial_dimension_org_unit_id' => $unitA]);
    }

    public function test_assets_can_be_grouped_by_the_responsible_unit_and_averaged_per_currency(): void
    {
        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'dimensions' => ['responsible_org_unit_id'], 'measures' => ['count', 'average_acquisition_value'],
            'filters' => ['currency_code' => 'IDR'],
        ])->assertOk()->json('rows');

        $perUnit = [];
        foreach ($rows as $row) {
            $perUnit[$row['responsible_org_unit_id']] = [$row['count'], $row['average_acquisition_value']];
        }

        $this->assertCount(2, $perUnit);
        $this->assertSame(2, $perUnit[$this->unitA][0]);
        $this->assertDecimal('75000000.25', $perUnit[$this->unitA][1]);
        $this->assertSame(1, $perUnit[$this->unitB][0]);
        $this->assertDecimal('200000000', $perUnit[$this->unitB][1]);
    }
}
