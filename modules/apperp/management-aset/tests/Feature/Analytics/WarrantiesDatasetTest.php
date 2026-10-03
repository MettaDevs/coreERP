<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

/**
 * Dataset `management-aset.warranties` terhadap layar daftar garansi (`GET garansi-aset`): garansi tidak
 * punya unit sendiri, jadi kebijakannya mengikuti unit penanggung jawab **asetnya**.
 */
class WarrantiesDatasetTest extends TestCase
{
    use ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.warranties';
    }

    protected function readPermission(): string
    {
        return 'management-aset.garansi-aset.read';
    }

    protected function listResource(): string
    {
        return 'garansi-aset';
    }

    protected function dimensions(): array
    {
        return ['asset_id'];
    }

    /** @param  array<string, mixed>  $row */
    protected function listKey(array $row): string
    {
        return $this->assetIds[$row['aset_kode']];
    }

    protected function expectedRows(): array
    {
        return ['all' => 3, 'unitA' => 2, 'unitB' => 1];
    }

    /** @return array{all: int, unitA: int, unitB: int} */
    protected function expectedKeys(): array
    {
        return ['all' => 2, 'unitA' => 1, 'unitB' => 1];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        $assetOfA = $this->asset($tenant, $legalEntity, "{$tag}-GA", $unitA);
        $assetOfB = $this->asset($tenant, $legalEntity, "{$tag}-GB", $unitB);

        // Garansi tambahan dicatat sebagai baris baru: satu aset boleh punya beberapa garansi.
        $this->warranty($tenant, $assetOfA, 'penuh', '2026-01-01', '2026-12-31');
        $this->warranty($tenant, $assetOfA, 'sebagian', '2026-06-01', '2027-05-31');
        $this->warranty($tenant, $assetOfB, 'penuh', '2026-02-01', '2027-01-31');
    }

    public function test_one_asset_with_two_warranties_is_two_warranties_but_one_asset(): void
    {
        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['count', 'asset_count']])
            ->assertOk()
            ->assertJsonPath('rows.0.count', 3)
            ->assertJsonPath('rows.0.asset_count', 2);

        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'dimensions' => ['warranty_type'], 'measures' => ['count']])
            ->assertOk()
            ->assertJsonPath('rows.0.warranty_type', 'penuh')
            ->assertJsonPath('rows.0.count', 2);
    }

    private function warranty(string $tenant, string $assetId, string $type, string $from, string $until): void
    {
        DB::table('aset_tr_garansi_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'aset_id' => $assetId,
            'jenis_garansi' => $type, 'berlaku_mulai' => $from, 'berlaku_sampai' => $until, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
