<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksMoneyPerCurrency;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksTimeZoneBuckets;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

/**
 * Dataset `management-aset.asset-register` terhadap layar daftar aset (`GET aset`): kebijakan data pada
 * unit **penanggung jawab**, bukan unit dimensi keuangan.
 */
class AssetRegisterDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ChecksTimeZoneBuckets, ProbesAssetDatasets, RefreshDatabase;

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
        $this->asset($tenant, $legalEntity, "{$tag}-A2", $unitA, 'IDR', '50000000.50', ['financial_dimension_org_unit_id' => $unitB, 'lifecycle_state' => 'disposed']);
        $this->asset($tenant, $legalEntity, "{$tag}-B1", $unitB, 'IDR', '200000000', ['financial_dimension_org_unit_id' => $unitA]);
        $this->asset($tenant, $legalEntity, "{$tag}-B2", $unitB, 'USD', '1500.25', ['financial_dimension_org_unit_id' => $unitB, 'lifecycle_state' => 'decommissioned']);
        $this->asset($tenant, $legalEntity, "{$tag}-B3", $unitB, 'USD', '250', ['financial_dimension_org_unit_id' => $unitA, 'lifecycle_state' => 'disposed']);
    }

    protected function timeField(): string
    {
        return 'acquired_on';
    }

    protected function timeKind(): string
    {
        return 'date';
    }

    /** @return array<string, string|list<string>> */
    protected function insertBoundaryRow(string $value): array
    {
        $this->asset($this->tenantId, $this->legalEntity, 'BATAS', $this->unitA, 'IDR', '1000', ['acquired_on' => $value]);

        return ['kode' => 'BATAS'];
    }

    public function test_assets_that_left_the_register_are_counted_by_their_status(): void
    {
        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['count', 'disposed', 'decommissioned']])
            ->assertOk()
            ->assertJsonPath('rows.0.count', 5)
            ->assertJsonPath('rows.0.disposed', 2)
            ->assertJsonPath('rows.0.decommissioned', 1);

        // Satu aset yang dilepas ada di tiap unit; hibah satu unit hanya menghitung milik unitnya.
        $unitA = $this->member($this->readPermission(), [[$this->legalEntity, $this->unitA]]);
        $unitB = $this->member($this->readPermission(), [[$this->legalEntity, $this->unitB]]);
        $this->analyze($unitA, ['dataset' => $this->datasetCode(), 'measures' => ['disposed']])->assertOk()->assertJsonPath('rows.0.disposed', 1);
        $this->analyze($unitB, ['dataset' => $this->datasetCode(), 'measures' => ['disposed', 'decommissioned']])->assertOk()
            ->assertJsonPath('rows.0.disposed', 1)->assertJsonPath('rows.0.decommissioned', 1);
    }

    public function test_masters_organization_and_labels_come_back_next_to_their_ids(): void
    {
        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'dimensions' => ['responsible_org_unit_id', 'group_aset_id'], 'measures' => ['count'],
        ])->assertOk()->json('rows');

        $perUnit = [];
        foreach ($rows as $row) {
            $perUnit[$row['responsible_org_unit_id__label']] = [$row['count'], $row['group_aset_id__label']];
        }
        ksort($perUnit);

        // Unit kerja dari tabel organisasi Core; group dari master module (label dari nama group).
        $this->assertSame(['Unit A' => [2, 'GRP-ANL'], 'Unit B' => [3, 'GRP-ANL']], $perUnit);
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
