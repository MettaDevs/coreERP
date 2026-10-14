<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Services\FixedAssetLedgerBackfill;
use Modules\Apperp\ManagementAset\Services\FixedAssetLedgerContext;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

class FixedAssetLedgerDatasetTest extends TestCase
{
    use ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.fixed-asset-ledger';
    }

    protected function readPermission(): string
    {
        return 'management-aset.penyusutan.read';
    }

    protected function listResource(): string
    {
        return 'aset';
    }

    protected function dimensions(): array
    {
        return ['asset_code'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 2, 'unitA' => 1, 'unitB' => 1];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        foreach ([$unitA, $unitB] as $index => $unit) {
            $asset = $this->asset($tenant, $legalEntity, $tag.'-'.$index, $unit, 'IDR', '1000', ['financial_dimension_org_unit_id' => $index === 0 ? $unitB : $unitA]);
            DB::table('aset_tr_buku_aset')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'aset_id' => $asset,
                'book_code' => 'KOM', 'acquisition_value' => '1000', 'net_book_value' => '1000',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_data_policy_grants_narrow_rows_exactly_like_the_module_list(): void
    {
        foreach ([$this->unitA, $this->unitB] as $index => $unit) {
            $user = $this->member($this->readPermission(), [[$this->legalEntity, $unit]]);
            $this->analyze($user, ['dataset' => $this->datasetCode(), 'records' => true, 'dimensions' => ['asset_code'], 'measures' => []])
                ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.asset_code', 'A-'.$index);
        }
        $this->analyze($this->member($this->readPermission()), ['dataset' => $this->datasetCode(), 'records' => true, 'dimensions' => ['asset_code'], 'measures' => []])->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_transactions_roll_back_and_retry_without_duplicate_entries(): void
    {
        $book = DB::table('aset_tr_buku_aset')->where('tenant_id', $this->tenantId)->first();
        $count = DB::table('aset_tr_ledger_aset')->count();
        DB::beginTransaction();
        FixedAssetLedgerContext::set('depreciation', '2026-09-30', 'depreciation-period', (string) Str::ulid());
        DB::table('aset_tr_buku_aset')->where('id', $book->id)->update(['accumulated_depreciation' => '100', 'net_book_value' => '900']);
        $this->assertSame($count + 1, DB::table('aset_tr_ledger_aset')->count());
        DB::rollBack();
        $this->assertSame($count, DB::table('aset_tr_ledger_aset')->count());
        FixedAssetLedgerContext::set('depreciation', '2026-09-30', 'depreciation-period', (string) Str::ulid());
        for ($index = 0; $index < 2; $index++) {
            DB::table('aset_tr_buku_aset')->where('id', $book->id)->update(['accumulated_depreciation' => '100', 'net_book_value' => '900']);
        }
        $this->assertSame($count + 1, DB::table('aset_tr_ledger_aset')->count());
        $this->assertSame('-100.00', DB::table('aset_tr_ledger_aset')->where('posting_type', 'depreciation')->value('amount'));
    }

    public function test_books_are_never_summed_together_and_drill_only_narrows_existing_filters(): void
    {
        $asset = $this->assetIds['A-0'];
        DB::table('aset_tr_buku_aset')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'aset_id' => $asset, 'book_code' => 'FISKAL', 'acquisition_value' => '1000', 'net_book_value' => '1000', 'created_at' => now(), 'updated_at' => now()]);
        $result = $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['sum_amount'], 'totals' => true])->assertOk();
        $result->assertJsonCount(2, 'rows')->assertJsonCount(2, 'totals');
        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'records' => true, 'dimensions' => ['asset_code'], 'measures' => []])
            ->assertOk()->assertJsonCount(3, 'rows');
        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'records' => true, 'dimensions' => ['asset_code'], 'measures' => [], 'filters' => ['asset_code' => 'A-0'], 'drill' => ['asset_code' => 'A-1']])
            ->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_ledger_cannot_be_edited_and_group_changes_keep_both_sides(): void
    {
        $asset = $this->assetIds['A-0'];
        $oldGroup = DB::table('aset_tr_aset')->where('id', $asset)->value('group_aset_id');
        $newGroup = (string) Str::ulid();
        DB::table('aset_m_group_aset')->insert(['id' => $newGroup, 'tenant_id' => $this->tenantId, 'creation_key' => (string) Str::ulid(), 'kode' => 'OTHER', 'nama' => 'Group lain', 'aktif' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('aset_tr_aset')->where('id', $asset)->update(['group_aset_id' => $newGroup]);
        $rows = DB::table('aset_tr_ledger_aset')->where('aset_id', $asset)->where('posting_type', 'reclassification')->get()->keyBy('group_aset_id');
        $this->assertSame('-1000.00', $rows[$oldGroup]->amount);
        $this->assertSame('1000.00', $rows[$newGroup]->amount);
        DB::beginTransaction();
        try {
            DB::table('aset_tr_ledger_aset')->where('aset_id', $asset)->update(['amount' => '999']);
            $this->fail('Ledger berhasil diubah.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('tidak dapat diubah', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_old_acquisition_and_final_depreciation_are_reconstructed_once(): void
    {
        $book = DB::table('aset_tr_buku_aset')->where('tenant_id', $this->tenantId)->first();
        DB::table('aset_tr_penyusutan_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'buku_aset_id' => $book->id,
            'legal_entity_id' => $this->legalEntity, 'usage_org_unit_id' => $this->unitA,
            'period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'amount' => '100', 'status' => 'final',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aset_tr_buku_aset')->where('id', $book->id)->update(['accumulated_depreciation' => '100', 'net_book_value' => '900']);
        // Meniru database lama yang belum mempunyai ledger; hanya database test milik test ini.
        DB::statement('truncate table aset_tr_ledger_aset');
        app(FixedAssetLedgerBackfill::class)->run();
        app(FixedAssetLedgerBackfill::class)->run();
        $this->assertSame(3, DB::table('aset_tr_ledger_aset')->count());
        $this->assertSame(0, DB::table('aset_tr_ledger_aset')->where('is_reconstructed', false)->count());
        $this->assertSame('1900.00', DB::table('aset_tr_ledger_aset')->sum('amount'));
    }
}
