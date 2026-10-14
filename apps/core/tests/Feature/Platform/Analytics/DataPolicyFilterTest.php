<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\CompanyContextPrincipal;
use App\Platform\Analytics\Security\DataPolicyScope;
use App\Platform\Modules\Contracts\CompanyContextFilter;
use App\Platform\Modules\Contracts\DataPolicyFilter;
use App\Platform\Modules\Contracts\FieldFilterExpression;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Tests\TestCase;

/**
 * Hak policy tetap mencakup semua grant; konteks perusahaan menyempitkan pekerjaan biasa.
 * Test menjaga paritas gabungan CompanyContextFilter + DataPolicyFilter dengan OrganizationScope,
 * termasuk pengguna A+B, grant penuh, dan perusahaan aktif yang belum dipilih.
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
            $this->assertSame($legalEntityOnly, $this->ids(DataPolicyFilter::apply($this->rows(), $engineScope, 'policy_parity_rows.legal_entity_id', null)), "{$case}: legal entity saja");

            foreach (['le-1', 'le-2', null] as $company) {
                $request->attributes->set('coreerp.legal_entity_id', $company);
                $expectedWithUnits = array_values(array_filter($withUnits, static fn (string $id): bool => $company !== null && self::ROWS[$id][0] === $company));
                $expectedLegalEntityOnly = array_values(array_filter($legalEntityOnly, static fn (string $id): bool => $company !== null && self::ROWS[$id][0] === $company));
                $engine = CompanyContextFilter::apply($this->rows(), 'policy_parity_rows.legal_entity_id', $company);
                DataPolicyFilter::apply($engine, $engineScope, 'policy_parity_rows.legal_entity_id', 'policy_parity_rows.unit_id');
                $this->assertSame($expectedWithUnits, $this->ids($engine), "{$case}: konteks perusahaan dan policy");
                $this->assertSame($expectedWithUnits, $this->ids($module->query($this->rows(), $request, 'policy_parity_rows.legal_entity_id', 'policy_parity_rows.unit_id')), "{$case}: legal entity + unit, OrganizationScope");
                $this->assertSame($expectedLegalEntityOnly, $this->ids($module->legalEntityQuery($this->rows(), $request, 'policy_parity_rows.legal_entity_id')), "{$case}: legal entity saja, OrganizationScope");
            }
        }
    }

    public function test_unit_only_policy_reads_granted_units_without_a_company_and_filters_only_narrow(): void
    {
        $scope = ['all' => false, 'scope_grants' => [
            ['legal_entity_id' => null, 'operating_unit_ids' => ['unit-a']],
            ['legal_entity_id' => null, 'operating_unit_ids' => ['unit-c']],
        ]];
        $query = DataPolicyFilter::apply($this->rows(), $scope, null, 'policy_parity_rows.unit_id');
        $this->assertSame(['r1', 'r3', 'r5', 'r7'], $this->ids($query));
        $principal = $this->createStub(CompanyContextPrincipal::class);
        $principal->method('activeLegalEntityId')->willReturn(null);
        $principal->method('policyScope')->willReturn($scope);
        $principal->method('lockedFilters')->willReturn([]);
        $dataset = new CompiledDataset(
            code: 'unit-example', caption: 'Unit', moduleId: 'example', version: 1,
            model: Model::class, table: 'policy_parity_rows', permission: 'example.read',
            policy: ['code' => 'example.unit-responsibility', 'legal_entity' => null, 'operating_unit' => 'unit_id'],
            fields: [], measures: [], times: [], defaultTime: null,
        );
        $ordinaryQuery = $this->rows();
        (new DataPolicyScope)->apply($ordinaryQuery, $dataset, $principal);
        $this->assertSame(['r1', 'r3', 'r5', 'r7'], $this->ids($ordinaryQuery));
        $outside = DataPolicyFilter::apply($this->rows(), $scope, null, 'policy_parity_rows.unit_id')->where('unit_id', 'unit-b');
        $this->assertSame([], $this->ids($outside));
        $this->assertSame([], $this->ids(DataPolicyFilter::apply($this->rows(), ['all' => false, 'scope_grants' => []], null, 'policy_parity_rows.unit_id')));
        $this->assertSame([], $this->ids(DataPolicyFilter::apply($this->rows(), ['all' => true, 'scope_grants' => []], null, null)));
        $this->assertSame(array_keys(self::ROWS), $this->ids(DataPolicyFilter::apply($this->rows(), ['all' => true, 'scope_grants' => []], null, 'policy_parity_rows.unit_id')));
    }

    public function test_public_field_aliases_cannot_replace_declared_company_or_policy_columns(): void
    {
        DB::statement('alter table policy_parity_rows add column financial_unit_id text, add column reporting_legal_entity_id text');
        foreach (self::ROWS as $id => [$legalEntity, $unit]) {
            DB::table('policy_parity_rows')->where('id', $id)->update([
                'financial_unit_id' => $unit === 'unit-a' ? 'unit-b' : 'unit-a',
                'reporting_legal_entity_id' => $legalEntity === 'le-1' ? 'le-2' : 'le-1',
            ]);
        }
        $principal = $this->createStub(CompanyContextPrincipal::class);
        $principal->method('activeLegalEntityId')->willReturn('le-1');
        $principal->method('policyScope')->willReturn(['all' => false, 'scope_grants' => [
            ['legal_entity_id' => 'le-1', 'operating_unit_ids' => ['unit-a']],
        ]]);
        $principal->method('lockedFilters')->willReturn([]);
        $dataset = new CompiledDataset(
            code: 'alias-example', caption: 'Alias', moduleId: 'example', version: 1,
            model: Model::class, table: 'policy_parity_rows', permission: 'example.read',
            policy: ['code' => 'example.responsibility', 'legal_entity' => 'legal_entity_id', 'operating_unit' => 'unit_id'],
            fields: [
                'unit_id' => new FilterField('unit_id', 'Unit keuangan', FieldType::Reference, 'policy_parity_rows.financial_unit_id'),
                'legal_entity_id' => new FilterField('legal_entity_id', 'Perusahaan laporan', FieldType::Reference, 'policy_parity_rows.reporting_legal_entity_id'),
            ], measures: [], times: [], defaultTime: null, companyColumn: 'legal_entity_id',
        );
        $query = $this->rows();
        (new DataPolicyScope)->apply($query, $dataset, $principal);
        $this->assertSame(['r1'], $this->ids(clone $query));
        $this->assertSame('policy_parity_rows.reporting_legal_entity_id', $dataset->qualified('legal_entity_id'));
        $this->assertSame('policy_parity_rows.legal_entity_id', $dataset->qualifiedColumn('legal_entity_id'));
        $narrowed = clone $query;
        FieldFilterExpression::apply($narrowed, $dataset->filterField('unit_id'), ['unit-a'], 'UTC');
        $this->assertSame([], $this->ids($narrowed));
        FieldFilterExpression::apply($query, $dataset->filterField('unit_id'), ['unit-b'], 'UTC');
        $this->assertSame(['r1'], $this->ids($query));
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
