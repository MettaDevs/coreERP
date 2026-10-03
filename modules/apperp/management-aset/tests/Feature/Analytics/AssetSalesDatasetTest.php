<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksMoneyPerCurrency;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

/**
 * Dataset `management-aset.asset-sales` terhadap layar daftar penjualan aset (`GET penjualan-aset`):
 * kebijakan data pada unit penanggung jawab milik dokumen, dan hanya dokumen berjenis penjualan — satu
 * tabel yang sama juga memuat pemusnahan, yang dijaga permission lain.
 */
class AssetSalesDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.asset-sales';
    }

    protected function readPermission(): string
    {
        return 'management-aset.penjualan-aset.read';
    }

    protected function listResource(): string
    {
        return 'penjualan-aset';
    }

    protected function dimensions(): array
    {
        return ['document_number'];
    }

    protected function moneyMeasure(): string
    {
        return 'proceeds';
    }

    protected function expectedMoney(): array
    {
        return ['IDR' => '8000000', 'USD' => '900'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 2, 'unitA' => 1, 'unitB' => 1];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        $sold = $this->asset($tenant, $legalEntity, "{$tag}-S1", $unitA, 'IDR', '10000000');
        $soldAbroad = $this->asset($tenant, $legalEntity, "{$tag}-S2", $unitB, 'USD', '1500');
        $scrapped = $this->asset($tenant, $legalEntity, "{$tag}-S3", $unitA, 'IDR', '500000');

        $this->document($tenant, $legalEntity, 'penjualan-aset', "{$tag}-SALE1", $unitA, $sold, '8000000');
        $this->document($tenant, $legalEntity, 'penjualan-aset', "{$tag}-SALE2", $unitB, $soldAbroad, '900');
        // Pemusnahan memakai tabel yang sama, tetapi bukan bagian dataset penjualan.
        $this->document($tenant, $legalEntity, 'pemusnahan-aset', "{$tag}-SCRAP1", $unitA, $scrapped, null);
    }

    public function test_a_user_who_may_read_sales_but_not_scraps_never_sees_scrap_documents(): void
    {
        $salesOnly = $this->member('management-aset.penjualan-aset.read', [[$this->legalEntity, $this->unitA]]);

        $this->analyze($salesOnly, ['dataset' => $this->datasetCode(), 'measures' => ['count']])->assertOk()->assertJsonPath('rows.0.count', 1);
        $this->analyze($salesOnly, ['dataset' => 'management-aset.asset-scraps', 'measures' => ['count']])->assertForbidden();
    }

    private function document(string $tenant, string $legalEntity, string $type, string $code, string $unit, string $assetId, ?string $value): void
    {
        DB::table('aset_tr_dokumen_siklus_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'jenis_dokumen' => $type,
            'kode' => $code, 'legal_entity_id' => $legalEntity, 'responsible_org_unit_id' => $unit, 'aset_id' => $assetId,
            'tanggal' => '2026-09-20', 'status' => 'posted', 'nilai' => $value, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
