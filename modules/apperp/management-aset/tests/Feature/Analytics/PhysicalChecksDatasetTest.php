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
 * Dataset `management-aset.physical-checks` terhadap layar daftar monitoring aset (`GET monitoring-aset`):
 * kebijakan data pada unit penanggung jawab milik **header** pemeriksaan. Unit di header boleh kosong, dan
 * pemeriksaan tanpa unit hanya terlihat bagi yang menjangkau seluruh organisasi.
 */
class PhysicalChecksDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ChecksTimeZoneBuckets, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.physical-checks';
    }

    protected function readPermission(): string
    {
        return 'management-aset.monitoring-aset.read';
    }

    protected function listResource(): string
    {
        return 'monitoring-aset';
    }

    protected function dimensions(): array
    {
        return ['document_number'];
    }

    protected function moneyMeasure(): string
    {
        return 'book_value';
    }

    protected function expectedMoney(): array
    {
        return ['IDR' => '1100000', 'USD' => '90'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 4, 'unitA' => 2, 'unitB' => 1];
    }

    /** @return array{all: int, unitA: int, unitB: int} */
    protected function expectedKeys(): array
    {
        return ['all' => 3, 'unitA' => 1, 'unitB' => 1];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        $location = $this->master($tenant, 'aset_m_lokasi_aset', "LOK-{$tag}");
        $a1 = $this->asset($tenant, $legalEntity, "{$tag}-P1", $unitA);
        $a2 = $this->asset($tenant, $legalEntity, "{$tag}-P2", $unitA);
        $b1 = $this->asset($tenant, $legalEntity, "{$tag}-P3", $unitB, 'USD', '120');
        $n1 = $this->asset($tenant, $legalEntity, "{$tag}-P4", null);

        $this->check($tenant, $legalEntity, "{$tag}-MON1", $unitA, $location, [[$a1, true, 'sesuai', '800000'], [$a2, false, 'tidak_sesuai', '200000']]);
        $this->check($tenant, $legalEntity, "{$tag}-MON2", $unitB, $location, [[$b1, true, 'sesuai', '90']]);
        // Pemeriksaan tanpa unit: hanya terlihat bagi yang menjangkau seluruh organisasi.
        $this->check($tenant, $legalEntity, "{$tag}-MON3", null, $location, [[$n1, true, 'sesuai', '100000']]);
    }

    protected function timeField(): string
    {
        return 'check_date';
    }

    protected function timeKind(): string
    {
        return 'date';
    }

    /** @return array<string, string|list<string>> */
    protected function insertBoundaryRow(string $value): array
    {
        $location = (string) DB::table('aset_m_lokasi_aset')->where('tenant_id', $this->tenantId)->value('id');
        $asset = $this->asset($this->tenantId, $this->legalEntity, 'A-BATAS', $this->unitA);
        $this->check($this->tenantId, $this->legalEntity, 'BATAS', $this->unitA, $location, [[$asset, true, 'sesuai', '1000']], $value);

        return ['document_number' => 'BATAS'];
    }

    public function test_findings_that_do_not_match_and_assets_not_found_are_counted_apart(): void
    {
        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['count', 'mismatch', 'absent']])
            ->assertOk()
            ->assertJsonPath('rows.0.count', 4)
            ->assertJsonPath('rows.0.mismatch', 1)
            ->assertJsonPath('rows.0.absent', 1);
    }

    public function test_mismatching_findings_can_be_told_apart_from_matching_ones(): void
    {
        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'dimensions' => ['result'], 'measures' => ['count', 'asset_count'],
        ])->assertOk()->json('rows');
        $byResult = array_column($rows, 'count', 'result');

        $this->assertSame(['sesuai' => 3, 'tidak_sesuai' => 1], $byResult);

        $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'measures' => ['count'], 'filters' => ['is_present' => '0'],
        ])->assertOk()->assertJsonPath('rows.0.count', 1);
    }

    /** @param list<array{0: string, 1: bool, 2: string, 3: string}> $lines aset, ada, hasil, nilai buku */
    private function check(string $tenant, string $legalEntity, string $code, ?string $unit, string $locationId, array $lines, string $date = '2026-09-28'): void
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_monitoring_aset')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $code, 'legal_entity_id' => $legalEntity,
            'responsible_org_unit_id' => $unit, 'lokasi_aset_id' => $locationId, 'tanggal' => $date, 'status' => 'selesai',
            'diselesaikan_pada' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $i => [$assetId, $present, $result, $bookValue]) {
            DB::table('aset_tr_monitoring_aset_details')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'monitoring_aset_id' => $id, 'line_number' => $i + 1,
                'aset_id' => $assetId, 'ada' => $present, 'hasil' => $result, 'nilai_buku' => $bookValue, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
