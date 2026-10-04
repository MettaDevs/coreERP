<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksTimeZoneBuckets;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

/**
 * Dataset `management-aset.downtime` terhadap layar daftar downtime (`GET downtime-aset`): catatan downtime
 * tidak punya unit sendiri, jadi kebijakannya mengikuti unit penanggung jawab **asetnya**.
 */
class DowntimeDatasetTest extends TestCase
{
    use ChecksTimeZoneBuckets, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.downtime';
    }

    protected function readPermission(): string
    {
        return 'management-aset.downtime-aset.read';
    }

    protected function listResource(): string
    {
        return 'downtime-aset';
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
        $assetOfA = $this->asset($tenant, $legalEntity, "{$tag}-W1", $unitA);
        $assetOfB = $this->asset($tenant, $legalEntity, "{$tag}-W2", $unitB);

        // Dua jam dan satu catatan yang masih terbuka pada aset unit A; empat jam pada aset unit B.
        $this->downtime($tenant, $assetOfA, '2026-09-01 08:00:00', '2026-09-01 10:00:00');
        $this->downtime($tenant, $assetOfA, '2026-09-02 08:00:00', null);
        // Empat jam pada aset unit B itu berasal dari alasan yang tidak dihitung dalam KPI.
        $reason = $this->master($tenant, 'aset_m_alasan_downtime', "ALS-{$tag}", ['masuk_kpi' => false]);
        $this->downtime($tenant, $assetOfB, '2026-09-03 08:00:00', '2026-09-03 12:00:00', $reason);
    }

    protected function timeField(): string
    {
        return 'started_at';
    }

    protected function timeKind(): string
    {
        return 'timestamp';
    }

    /** @return array<string, string|list<string>> */
    protected function insertBoundaryRow(string $value): array
    {
        $asset = $this->asset($this->tenantId, $this->legalEntity, 'A-BATAS', $this->unitA);
        $this->downtime($this->tenantId, $asset, $value, null);

        return ['asset_id' => [$asset]];
    }

    public function test_only_downtime_that_counts_in_the_kpi_is_summed_into_kpi_hours(): void
    {
        $row = $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['duration_hours', 'kpi_duration_hours', 'open_count']])
            ->assertOk()->json('rows.0');

        $this->assertSame(1, $row['open_count']);
        $this->assertDecimal('6', $row['duration_hours']);
        // Dua jam pada aset unit A masuk KPI (alasan kosong tetap dihitung); empat jam unit B tidak.
        $this->assertDecimal('2', $row['kpi_duration_hours']);
    }

    public function test_duration_counts_closed_records_only_and_open_ones_are_flagged(): void
    {
        $hours = $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['count', 'asset_count', 'duration_hours']])
            ->assertOk()->json('rows.0');

        $this->assertSame(3, $hours['count']);
        $this->assertSame(2, $hours['asset_count']);
        // 2 jam + 4 jam; catatan terbuka tidak punya lama yang tetap.
        $this->assertDecimal('6', $hours['duration_hours']);

        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['count'], 'filters' => ['is_open' => '1']])
            ->assertOk()->assertJsonPath('rows.0.count', 1);
    }

    private function downtime(string $tenant, string $assetId, string $start, ?string $end, ?string $reasonId = null): void
    {
        DB::table('aset_tr_downtime_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'aset_id' => $assetId,
            'mulai' => $start, 'selesai' => $end, 'alasan_downtime_id' => $reasonId, 'sumber' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
