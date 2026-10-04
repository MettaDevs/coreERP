<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Modules\Contracts\DataPolicyFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Tests\TestCase;

/**
 * Paritas `DataPolicyFilter` dengan `OrganizationScope` module aset (area 4.7): analitik dan layar module
 * menyaring kebijakan data dengan dua salinan aturan, dan test ini yang membuktikan keduanya memulangkan
 * baris yang sama — untuk mode legal entity + unit (`OrganizationScope::query()`) maupun legal entity saja
 * (`legalEntityQuery()`). `OrganizationScope` tidak diubah di area ini; memindahkan module ke
 * `DataPolicyFilter` adalah pekerjaan module (area 23), dan sampai itu test ini menjaga keduanya sama.
 *
 * Setiap kasus juga menyebut hasil yang diharapkan, supaya dua salinan yang sama-sama salah tidak lulus
 * berdua. Barisnya di tabel sementara di dalam transaksi test, bukan di tabel module: yang diuji aturan
 * penyaringnya, bukan skema aset.
 */
class DataPolicyFilterTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = 'management-aset.asset-responsibility';

    /** id => [legal entity, unit] */
    private const ROWS = [
        'r1' => ['le-1', 'unit-a'],
        'r2' => ['le-1', 'unit-b'],
        'r3' => ['le-1', 'unit-c'],
        'r4' => ['le-1', null],
        'r5' => ['le-2', 'unit-a'],
        'r6' => ['le-2', 'unit-b'],
        'r7' => [null, 'unit-a'],
        'r8' => [null, null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('create temporary table policy_parity_rows (id text primary key, legal_entity_id text null, unit_id text null)');
        foreach (self::ROWS as $id => [$legalEntity, $unit]) {
            DB::table('policy_parity_rows')->insert(['id' => $id, 'legal_entity_id' => $legalEntity, 'unit_id' => $unit]);
        }
    }

    public function test_data_policy_filter_returns_the_same_rows_as_the_asset_module_organization_scope(): void
    {
        $all = array_keys(self::ROWS);
        $legalEntity1 = ['r1', 'r2', 'r3', 'r4'];

        // [hibah, harapan mode legal entity + unit, harapan mode legal entity saja]
        $cases = [
            'jangkauan penuh' => [['all' => true, 'scope_grants' => []], $all, $all],
            'kebijakan tidak diberikan sama sekali' => [null, [], []],
            'tanpa hibah' => [['all' => false, 'scope_grants' => []], [], []],
            'satu unit' => [['all' => false, 'scope_grants' => [['legal_entity_id' => 'le-1', 'operating_unit_ids' => ['unit-a']]]], ['r1'], $legalEntity1],
            'unit beserta turunannya' => [['all' => false, 'scope_grants' => [['legal_entity_id' => 'le-1', 'operating_unit_ids' => ['unit-a', 'unit-c']]]], ['r1', 'r3'], $legalEntity1],
            'dua legal entity' => [['all' => false, 'scope_grants' => [
                ['legal_entity_id' => 'le-1', 'operating_unit_ids' => ['unit-a']],
                ['legal_entity_id' => 'le-2', 'operating_unit_ids' => ['unit-b']],
            ]], ['r1', 'r6'], ['r1', 'r2', 'r3', 'r4', 'r5', 'r6']],
            'unit tanpa legal entity' => [['all' => false, 'scope_grants' => [['legal_entity_id' => null, 'operating_unit_ids' => ['unit-a']]]], [], []],
            'legal entity tanpa unit' => [['all' => false, 'scope_grants' => [['legal_entity_id' => 'le-1', 'operating_unit_ids' => []]]], [], $legalEntity1],
            'penanda penuh yang bukan true' => [['all' => 'ya', 'scope_grants' => []], [], []],
            'daftar hibah yang bukan daftar' => [['all' => false, 'scope_grants' => 'le-1'], [], []],
            'isi hibah yang rusak' => [['all' => false, 'scope_grants' => ['le-1', ['legal_entity_id' => 7, 'operating_unit_ids' => ['unit-a', 5, null]]]], [], []],
        ];

        foreach ($cases as $case => [$scope, $withUnits, $legalEntityOnly]) {
            $request = Request::create('/');
            $request->attributes->set('coreerp.data_policies', $scope === null ? [] : [self::POLICY => $scope]);
            $engineScope = $scope ?? ['all' => false, 'scope_grants' => []];
            $module = new OrganizationScope;

            $this->assertSame($withUnits, $this->ids(DataPolicyFilter::apply($this->rows(), $engineScope, 'policy_parity_rows.legal_entity_id', 'policy_parity_rows.unit_id')), "{$case}: legal entity + unit");
            $this->assertSame($withUnits, $this->ids($module->query($this->rows(), $request, 'policy_parity_rows.legal_entity_id', 'policy_parity_rows.unit_id')), "{$case}: legal entity + unit, OrganizationScope");

            $this->assertSame($legalEntityOnly, $this->ids(DataPolicyFilter::apply($this->rows(), $engineScope, 'policy_parity_rows.legal_entity_id', null)), "{$case}: legal entity saja");
            $this->assertSame($legalEntityOnly, $this->ids($module->legalEntityQuery($this->rows(), $request, 'policy_parity_rows.legal_entity_id')), "{$case}: legal entity saja, OrganizationScope");
        }
    }

    private function rows(): Builder
    {
        return DB::table('policy_parity_rows');
    }

    /** @return list<string> */
    private function ids(mixed $query): array
    {
        return $query->orderBy('id')->pluck('id')->map(strval(...))->all();
    }
}
