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
 * Dataset `management-aset.value-adjustments` terhadap layar daftar penyesuaian nilai
 * (`GET penyesuaian-nilai-aset`): kebijakan data pada unit penanggung jawab milik **header** dokumen,
 * sedangkan nilainya ada di baris dan mata uangnya dari aset.
 */
class ValueAdjustmentsDatasetTest extends TestCase
{
    use ChecksMoneyPerCurrency, ChecksTimeZoneBuckets, ProbesAssetDatasets, RefreshDatabase;

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
        // Kenaikan nilai di unit B masih draf: belum mengubah nilai buku.
        $this->adjustment($tenant, $legalEntity, "{$tag}-ADJ2", $unitB, 'appreciation', $book, [
            [$this->asset($tenant, $legalEntity, "{$tag}-N3", $unitB, 'USD', '2000'), '100'],
        ], 'draft');
    }

    protected function timeField(): string
    {
        return 'document_date';
    }

    protected function timeKind(): string
    {
        return 'date';
    }

    /** @return array<string, string|list<string>> */
    protected function insertBoundaryRow(string $value): array
    {
        $book = (string) DB::table('aset_m_buku_penyusutan')->where('tenant_id', $this->tenantId)->value('id');
        $this->adjustment($this->tenantId, $this->legalEntity, 'BATAS', $this->unitA, 'write_down', $book, [
            [$this->asset($this->tenantId, $this->legalEntity, 'A-BATAS', $this->unitA), '1000'],
        ], 'posted', $value);

        return ['document_number' => 'BATAS'];
    }

    public function test_only_posted_adjustments_change_the_book_value_and_each_direction_has_its_own_amount(): void
    {
        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'measures' => ['write_down_amount', 'appreciation_amount', 'posted_net_effect', 'net_effect'],
        ])->assertOk()->json('rows');
        $byCurrency = array_column($rows, null, 'currency_code');

        $this->assertDecimal('1500000', $byCurrency['IDR']['write_down_amount']);
        $this->assertDecimal('0', $byCurrency['IDR']['appreciation_amount']);
        $this->assertDecimal('-1500000', $byCurrency['IDR']['posted_net_effect']);
        // Kenaikan USD masih draf: ada di nilai kenaikan dan dampak bersih, tetapi belum di dampak yang diposting.
        $this->assertDecimal('100', $byCurrency['USD']['appreciation_amount']);
        $this->assertDecimal('100', $byCurrency['USD']['net_effect']);
        $this->assertDecimal('0', $byCurrency['USD']['posted_net_effect']);
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
    private function adjustment(string $tenant, string $legalEntity, string $code, string $unit, string $kind, string $bookId, array $lines, string $status = 'posted', string $date = '2026-09-25'): void
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_penyesuaian_nilai_aset')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $code, 'legal_entity_id' => $legalEntity,
            'responsible_org_unit_id' => $unit, 'jenis' => $kind, 'buku_id' => $bookId, 'tanggal' => $date,
            'keterangan' => 'Penilaian ulang', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $i => [$assetId, $amount]) {
            DB::table('aset_tr_penyesuaian_nilai_aset_details')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'penyesuaian_nilai_aset_id' => $id, 'line_number' => $i + 1,
                'aset_id' => $assetId, 'nilai' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
