<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksMoneyPerCurrency;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksTimeZoneBuckets;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

/**
 * Dataset `management-aset.asset-receipts` terhadap layar daftar penerimaan (`GET penerimaan-aset`):
 * kebijakan data pada unit penanggung jawab **header** dokumen, dan nilai per baris dihitung dari
 * `jumlah × nilai_per_unit` dengan mata uang header.
 */
class AssetReceiptsDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ChecksTimeZoneBuckets, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.asset-receipts';
    }

    protected function readPermission(): string
    {
        return 'management-aset.penerimaan-aset.read';
    }

    protected function listResource(): string
    {
        return 'penerimaan-aset';
    }

    protected function dimensions(): array
    {
        return ['receipt_number'];
    }

    protected function moneyMeasure(): string
    {
        return 'receipt_value';
    }

    protected function expectedMoney(): array
    {
        return ['IDR' => '19000000', 'USD' => '301.50'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 4, 'unitA' => 3, 'unitB' => 1];
    }

    /** @return array{all: int, unitA: int, unitB: int} */
    protected function expectedKeys(): array
    {
        return ['all' => 3, 'unitA' => 2, 'unitB' => 1];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        $this->receipt($tenant, $legalEntity, "{$tag}-R1", $unitA, 'IDR', [[2, '5000000'], [1, '2000000']]);
        $this->receipt($tenant, $legalEntity, "{$tag}-R2", $unitA, 'USD', [[3, '100.50']]);
        $this->receipt($tenant, $legalEntity, "{$tag}-R3", $unitB, 'IDR', [[1, '7000000']], 'draft');
    }

    protected function timeField(): string
    {
        return 'received_on';
    }

    protected function timeKind(): string
    {
        return 'date';
    }

    /** @return array<string, string|list<string>> */
    protected function insertBoundaryRow(string $value): array
    {
        $this->receipt($this->tenantId, $this->legalEntity, 'BATAS', $this->unitA, 'IDR', [[1, '1000']], 'selesai', $value);

        return ['receipt_number' => 'BATAS'];
    }

    public function test_only_completed_receipts_count_towards_the_completed_value(): void
    {
        // R3 masih draf: nilainya ikut nilai penerimaan, tetapi tidak ikut nilai yang sudah selesai.
        $rows = $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['receipt_value', 'completed_value']])
            ->assertOk()->json('rows');
        $byCurrency = array_column($rows, null, 'currency_code');

        $this->assertDecimal('19000000', $byCurrency['IDR']['receipt_value']);
        $this->assertDecimal('12000000', $byCurrency['IDR']['completed_value']);
        $this->assertDecimal('301.50', $byCurrency['USD']['completed_value']);
    }

    public function test_a_receipt_is_counted_once_and_its_value_is_quantity_times_unit_value(): void
    {
        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'dimensions' => ['receipt_number'],
            'measures' => ['count', 'receipt_count', 'quantity', 'receipt_value'],
        ])->assertOk()->json('rows');

        $byNumber = [];
        foreach ($rows as $row) {
            $byNumber[$row['receipt_number']] = $row;
        }

        // R1 punya dua baris: dua baris dihitung, satu dokumen, tiga unit, dan 2 × 5.000.000 + 1 × 2.000.000.
        $this->assertSame(2, $byNumber['A-R1']['count']);
        $this->assertSame(1, $byNumber['A-R1']['receipt_count']);
        $this->assertEquals(3, $byNumber['A-R1']['quantity']);
        $this->assertDecimal('12000000', $byNumber['A-R1']['receipt_value']);
        $this->assertDecimal('301.50', $byNumber['A-R2']['receipt_value']);
    }

    /** @param list<array{0: int, 1: string}> $lines jumlah dan nilai per unit */
    private function receipt(string $tenant, string $legalEntity, string $code, string $unit, string $currency, array $lines, string $status = 'selesai', string $date = '2026-09-15'): void
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_penerimaan_aset')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $code,
            'legal_entity_id' => $legalEntity, 'responsible_org_unit_id' => $unit, 'tanggal' => $date,
            'currency_code' => $currency, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $masters = $this->masters($tenant);
        foreach ($lines as $i => [$quantity, $unitValue]) {
            DB::table('aset_tr_penerimaan_aset_details')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'penerimaan_aset_id' => $id, 'line_number' => $i + 1,
                'nama' => 'Barang '.$code, 'group_aset_id' => $masters['group'], 'jenis_aset_id' => $masters['jenis'],
                'jumlah' => $quantity, 'nilai_per_unit' => $unitValue, 'residu_per_unit' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
