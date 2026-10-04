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
 * Dataset `management-aset.depreciation-entries` terhadap layar daftar penyusutan (`GET penyusutan`):
 * kebijakan data pada `legal_entity_id` dan **`usage_org_unit_id`** milik periode, bukan unit penanggung
 * jawab asetnya. Aset D1 sengaja dipakai dua unit pada dua periode yang berbeda, supaya kebijakan yang
 * memakai unit aset terlihat sebagai baris yang salah.
 */
class DepreciationEntriesDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.depreciation-entries';
    }

    protected function readPermission(): string
    {
        return 'management-aset.penyusutan.read';
    }

    protected function listResource(): string
    {
        return 'penyusutan';
    }

    protected function dimensions(): array
    {
        return ['asset_id', 'period_ends_on'];
    }

    /** @param  array<string, mixed>  $row */
    protected function listKey(array $row): string
    {
        return $this->assetIds[$row['aset_code']].'|'.$row['period_ends_on'];
    }

    protected function moneyMeasure(): string
    {
        return 'amount';
    }

    protected function expectedMoney(): array
    {
        return ['IDR' => '2000000', 'USD' => '200'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 4, 'unitA' => 2, 'unitB' => 2];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        // Unit penanggung jawab asetnya A dan B; yang menentukan baris terlihat adalah unit pengguna periodenya.
        $d1 = $this->asset($tenant, $legalEntity, "{$tag}-D1", $unitA, 'IDR');
        $d2 = $this->asset($tenant, $legalEntity, "{$tag}-D2", $unitB, 'USD');
        $book1 = $this->book($tenant, $d1);
        $book2 = $this->book($tenant, $d2);

        $this->period($tenant, $legalEntity, $book1, $unitA, '2026-09-30', '1000000');
        $this->period($tenant, $legalEntity, $book1, $unitB, '2026-10-31', '1000000');
        $this->period($tenant, $legalEntity, $book2, $unitB, '2026-09-30', '150');
        $this->period($tenant, $legalEntity, $book2, $unitA, '2026-10-31', '50');
    }

    public function test_a_reversal_row_nets_out_the_period_it_reverses(): void
    {
        $book = DB::table('aset_tr_buku_aset')->where('aset_id', $this->assetIds['A-D1'])->value('id');
        $original = DB::table('aset_tr_penyusutan_aset')->where('buku_aset_id', $book)->where('period_ends_on', '2026-09-30')->first();
        DB::table('aset_tr_penyusutan_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'buku_aset_id' => $book, 'legal_entity_id' => $this->legalEntity,
            'usage_org_unit_id' => $this->unitA, 'period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30',
            'amount' => '-1000000', 'status' => 'final', 'reverses_period_id' => $original->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'dimensions' => ['is_reversal'], 'measures' => ['count', 'amount'],
            'filters' => ['currency_code' => 'IDR'],
        ])->assertOk()->json('rows');
        $byFlag = [];
        foreach ($rows as $row) {
            $byFlag[(int) $row['is_reversal']] = $row;
        }

        $this->assertSame(2, $byFlag[0]['count']);
        $this->assertSame(1, $byFlag[1]['count']);
        $this->assertDecimal('-1000000', $byFlag[1]['amount']);
        // Tanpa dimensi pembalikan, jumlahnya sudah bersih dari periode yang dibalik.
        $net = $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['amount'], 'filters' => ['currency_code' => 'IDR']])
            ->assertOk()->json('rows.0.amount');
        $this->assertDecimal('1000000', $net);
    }

    private function book(string $tenant, string $assetId): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_buku_aset')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'aset_id' => $assetId, 'book_code' => 'KOM', 'acquisition_value' => '1000000',
            'net_book_value' => '1000000', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function period(string $tenant, string $legalEntity, string $bookId, string $usageUnit, string $endsOn, string $amount): void
    {
        DB::table('aset_tr_penyusutan_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'buku_aset_id' => $bookId, 'legal_entity_id' => $legalEntity,
            'usage_org_unit_id' => $usageUnit, 'period_starts_on' => substr($endsOn, 0, 8).'01', 'period_ends_on' => $endsOn,
            'amount' => $amount, 'status' => 'final', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
