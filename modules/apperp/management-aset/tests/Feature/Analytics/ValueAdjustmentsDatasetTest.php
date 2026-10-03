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
 * Dataset `management-aset.value-adjustments` terhadap layar daftar penyesuaian nilai
 * (`GET penyesuaian-nilai-aset`): kebijakan data pada unit penanggung jawab milik **header** dokumen,
 * sedangkan nilainya ada di baris dan mata uangnya dari aset.
 */
class ValueAdjustmentsDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.value-adjustments';
    }

    protected function readPermission(): string
    {
        return 'management-aset.penyesuaian-nilai-aset.read';
    }

    protected function listResource(): string
    {
        return 'penyesuaian-nilai-aset';
    }

    protected function dimensions(): array
    {
        return ['document_number'];
    }

    protected function moneyMeasure(): string
    {
        return 'amount';
    }

    protected function expectedMoney(): array
    {
        return ['IDR' => '1500000', 'USD' => '100'];
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
        $book = $this->master($tenant, 'aset_m_buku_penyusutan', "BK-{$tag}");

        $this->adjustment($tenant, $legalEntity, "{$tag}-ADJ1", $unitA, 'write_down', $book, [
            [$this->asset($tenant, $legalEntity, "{$tag}-N1", $unitA), '1000000'],
            [$this->asset($tenant, $legalEntity, "{$tag}-N2", $unitA), '500000'],
        ]);
        $this->adjustment($tenant, $legalEntity, "{$tag}-ADJ2", $unitB, 'appreciation', $book, [
            [$this->asset($tenant, $legalEntity, "{$tag}-N3", $unitB, 'USD', '2000'), '100'],
        ]);
    }

    public function test_a_write_down_lowers_book_value_and_an_appreciation_raises_it(): void
    {
        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'dimensions' => ['kind'], 'measures' => ['amount', 'net_effect'],
        ])->assertOk()->json('rows');
        $byKind = [];
        foreach ($rows as $row) {
            $byKind[$row['kind']] = $row;
        }

        // Nilai di baris selalu positif; `net_effect` memberinya tanda menurut jenis dokumennya.
        $this->assertDecimal('1500000', $byKind['write_down']['amount']);
        $this->assertDecimal('-1500000', $byKind['write_down']['net_effect']);
        $this->assertDecimal('100', $byKind['appreciation']['amount']);
        $this->assertDecimal('100', $byKind['appreciation']['net_effect']);
    }

    /** @param list<array{0: string, 1: string}> $lines aset dan nilainya */
    private function adjustment(string $tenant, string $legalEntity, string $code, string $unit, string $kind, string $bookId, array $lines): void
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_penyesuaian_nilai_aset')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $code, 'legal_entity_id' => $legalEntity,
            'responsible_org_unit_id' => $unit, 'jenis' => $kind, 'buku_id' => $bookId, 'tanggal' => '2026-09-25',
            'keterangan' => 'Penilaian ulang', 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $i => [$assetId, $amount]) {
            DB::table('aset_tr_penyesuaian_nilai_aset_details')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'penyesuaian_nilai_aset_id' => $id, 'line_number' => $i + 1,
                'aset_id' => $assetId, 'nilai' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
