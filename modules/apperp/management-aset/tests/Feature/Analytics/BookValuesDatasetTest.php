<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksMoneyPerCurrency;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

/**
 * Dataset `management-aset.book-values` terhadap daftar buku di layar penyusutan (`GET penyusutan/buku`):
 * kebijakan data pada legal entity dan unit **penanggung jawab asetnya**, karena buku tidak punya unit.
 */
class BookValuesDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.book-values';
    }

    protected function readPermission(): string
    {
        return 'management-aset.penyusutan.read';
    }

    protected function listResource(): string
    {
        return 'penyusutan/buku';
    }

    protected function dimensions(): array
    {
        return ['asset_id'];
    }

    /** @param  array<string, mixed>  $row */
    protected function listKey(array $row): string
    {
        return $this->assetIds[$row['aset_code']];
    }

    protected function moneyMeasure(): string
    {
        return 'net_book_value';
    }

    protected function expectedMoney(): array
    {
        return ['IDR' => '11000000', 'USD' => '2000'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 3, 'unitA' => 1, 'unitB' => 2];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        $profile = $this->master($tenant, 'aset_m_profil_penyusutan', 'PRF-ANL', ['method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'actual']);

        $this->book($tenant, $profile, $this->asset($tenant, $legalEntity, "{$tag}-V1", $unitA, 'IDR', '10000000'), '10000000', '9000000');
        $this->book($tenant, $profile, $this->asset($tenant, $legalEntity, "{$tag}-V2", $unitB, 'USD', '2500'), '2500', '2000');
        $this->book($tenant, $profile, $this->asset($tenant, $legalEntity, "{$tag}-V3", $unitB, 'IDR', '2000000'), '2000000', '2000000');
    }

    public function test_the_book_carries_current_balances_and_the_assets_currency(): void
    {
        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'dimensions' => ['asset_id'],
            'measures' => ['acquisition_value', 'accumulated_depreciation', 'net_book_value'],
            'filters' => ['currency_code' => 'IDR'],
        ])->assertOk()->json('rows');

        $byAsset = [];
        foreach ($rows as $row) {
            $byAsset[$row['asset_id']] = $row;
        }

        $v1 = $byAsset[$this->assetIds['A-V1']];
        $this->assertDecimal('10000000', $v1['acquisition_value']);
        $this->assertDecimal('9000000', $v1['net_book_value']);
        $this->assertDecimal('1000000', $v1['accumulated_depreciation']);
    }

    private function book(string $tenant, string $profile, string $assetId, string $acquisition, string $netBookValue): void
    {
        DB::table('aset_tr_buku_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'aset_id' => $assetId, 'depreciation_profile_id' => $profile,
            'book_code' => 'KOM', 'acquisition_value' => $acquisition, 'net_book_value' => $netBookValue,
            'accumulated_depreciation' => (string) BigDecimal::of($acquisition)->minus($netBookValue), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
