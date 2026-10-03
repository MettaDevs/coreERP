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
 * Dataset `management-aset.asset-scraps` terhadap layar daftar pemusnahan aset (`GET pemusnahan-aset`):
 * kebijakan data pada unit penanggung jawab milik dokumen, dan hanya dokumen berjenis pemusnahan. Pemusnahan
 * tidak membawa hasil penjualan, jadi measure uangnya nilai perolehan aset yang dimusnahkan.
 */
class AssetScrapsDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.asset-scraps';
    }

    protected function readPermission(): string
    {
        return 'management-aset.pemusnahan-aset.read';
    }

    protected function listResource(): string
    {
        return 'pemusnahan-aset';
    }

    protected function dimensions(): array
    {
        return ['document_number'];
    }

    protected function moneyMeasure(): string
    {
        return 'acquisition_value';
    }

    protected function expectedMoney(): array
    {
        return ['IDR' => '1500000', 'USD' => '300'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 3, 'unitA' => 1, 'unitB' => 2];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        $this->document($tenant, $legalEntity, 'pemusnahan-aset', "{$tag}-SCRAP1", $unitA, $this->asset($tenant, $legalEntity, "{$tag}-K1", $unitA, 'IDR', '1000000'));
        $this->document($tenant, $legalEntity, 'pemusnahan-aset', "{$tag}-SCRAP2", $unitB, $this->asset($tenant, $legalEntity, "{$tag}-K2", $unitB, 'IDR', '500000'));
        $this->document($tenant, $legalEntity, 'pemusnahan-aset', "{$tag}-SCRAP3", $unitB, $this->asset($tenant, $legalEntity, "{$tag}-K3", $unitB, 'USD', '300'));
        // Penjualan memakai tabel yang sama, tetapi bukan bagian dataset pemusnahan.
        $this->document($tenant, $legalEntity, 'penjualan-aset', "{$tag}-SALE1", $unitA, $this->asset($tenant, $legalEntity, "{$tag}-K4", $unitA, 'IDR', '9000000'));
    }

    private function document(string $tenant, string $legalEntity, string $type, string $code, string $unit, string $assetId): void
    {
        DB::table('aset_tr_dokumen_siklus_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'jenis_dokumen' => $type,
            'kode' => $code, 'legal_entity_id' => $legalEntity, 'responsible_org_unit_id' => $unit, 'aset_id' => $assetId,
            'tanggal' => '2026-09-20', 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
