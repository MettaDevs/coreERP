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
 * Dataset `management-aset.reclassifications` terhadap layar daftar reklasifikasi (`GET reklasifikasi-aset`):
 * kebijakan data pada unit penanggung jawab milik **header** dokumen, sedangkan nilai yang dipindah ada di
 * baris dan mata uangnya dari aset asal.
 */
class ReclassificationsDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.reclassifications';
    }

    protected function readPermission(): string
    {
        return 'management-aset.reklasifikasi-aset.read';
    }

    protected function listResource(): string
    {
        return 'reklasifikasi-aset';
    }

    protected function dimensions(): array
    {
        return ['document_number'];
    }

    protected function moneyMeasure(): string
    {
        return 'moved_value';
    }

    protected function expectedMoney(): array
    {
        return ['IDR' => '1000000', 'USD' => '75'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 3, 'unitA' => 1, 'unitB' => 2];
    }

    /** @return array{all: int, unitA: int, unitB: int} */
    protected function expectedKeys(): array
    {
        return ['all' => 2, 'unitA' => 1, 'unitB' => 1];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        $this->reclassification($tenant, $legalEntity, "{$tag}-RK1", $unitA, 'pindah_group', [
            [$this->asset($tenant, $legalEntity, "{$tag}-G1", $unitA), '1000000'],
        ]);
        $this->reclassification($tenant, $legalEntity, "{$tag}-RK2", $unitB, 'pecah', [
            [$this->asset($tenant, $legalEntity, "{$tag}-G2", $unitB, 'USD', '500'), '50'],
            [$this->asset($tenant, $legalEntity, "{$tag}-G3", $unitB, 'USD', '250'), '25'],
        ]);
    }

    public function test_a_split_counts_one_document_and_one_row_per_source_asset(): void
    {
        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'dimensions' => ['kind'], 'measures' => ['count', 'document_count'],
        ])->assertOk()->json('rows');
        $byKind = [];
        foreach ($rows as $row) {
            $byKind[$row['kind']] = $row;
        }

        $this->assertSame(1, $byKind['pindah_group']['count']);
        $this->assertSame(2, $byKind['pecah']['count']);
        $this->assertSame(1, $byKind['pecah']['document_count']);
    }

    /** @param list<array{0: string, 1: string}> $lines aset asal dan nilai perolehan yang dipindah */
    private function reclassification(string $tenant, string $legalEntity, string $code, string $unit, string $kind, array $lines): void
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_reklasifikasi_aset')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $code, 'legal_entity_id' => $legalEntity,
            'responsible_org_unit_id' => $unit, 'jenis' => $kind, 'tanggal' => '2026-09-25', 'keterangan' => 'Penataan group',
            'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $i => [$assetId, $moved]) {
            DB::table('aset_tr_reklasifikasi_aset_details')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'reklasifikasi_aset_id' => $id, 'line_number' => $i + 1,
                'aset_id' => $assetId, 'nilai_perolehan_dipindah' => $moved, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
