<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\CompanyContextPrincipal;
use App\Platform\Analytics\Security\CrossCompanyPrincipal;
use App\Platform\Analytics\Security\DataPolicyScope;
use App\Platform\Analytics\Security\ScopeFingerprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyContextTest extends TestCase
{
    public function test_missing_company_fails_closed_without_restricting_shared_datasets(): void
    {
        $principal = $this->createStub(CompanyContextPrincipal::class);
        $principal->method('activeLegalEntityId')->willReturn(null);
        $principal->method('lockedFilters')->willReturn([]);
        $scope = new DataPolicyScope;
        $query = DB::table('example_rows');
        $scope->apply($query, $this->dataset('legal_entity_id'), $principal);
        $this->assertStringContainsString('1 = 0', $query->toSql());

        $shared = DB::table('example_rows');
        $scope->apply($shared, $this->dataset(null), $principal);
        $this->assertStringNotContainsString('where', $shared->toSql());
    }

    public function test_company_column_is_qualified_for_model_and_query_sources(): void
    {
        $principal = $this->createStub(CompanyContextPrincipal::class);
        $principal->method('activeLegalEntityId')->willReturn('company-a');
        $principal->method('lockedFilters')->willReturn([]);
        foreach ([false, true] as $querySource) {
            $query = DB::table('example_rows');
            (new DataPolicyScope)->apply($query, $this->dataset('legal_entity_id', $querySource), $principal);
            $this->assertStringContainsString($querySource ? '"base"."legal_entity_id"' : '"example_rows"."legal_entity_id"', $query->toSql());
            $this->assertSame(['company-a'], $query->getBindings());
        }
    }

    public function test_a_principal_without_an_explicit_company_mode_is_denied_company_data(): void
    {
        $principal = $this->createStub(AnalyticsPrincipal::class);
        $principal->method('lockedFilters')->willReturn([]);
        $query = DB::table('example_rows');
        (new DataPolicyScope)->apply($query, $this->dataset('legal_entity_id'), $principal);
        $this->assertStringContainsString('1 = 0', $query->toSql());
        $shared = DB::table('example_rows');
        (new DataPolicyScope)->apply($shared, $this->dataset(null), $principal);
        $this->assertStringNotContainsString('where', $shared->toSql());
    }

    public function test_an_explicit_cross_company_principal_still_applies_its_policy(): void
    {
        $principal = $this->createStub(CrossCompanyPrincipal::class);
        $principal->method('lockedFilters')->willReturn([]);
        $principal->method('policyScope')->willReturn(['all' => false, 'scope_grants' => [
            ['legal_entity_id' => 'company-b', 'operating_unit_ids' => []],
        ]]);
        $query = DB::table('example_rows');
        (new DataPolicyScope)->apply($query, $this->dataset('legal_entity_id', policy: ['code' => 'example.policy', 'legal_entity' => 'legal_entity_id', 'operating_unit' => null]), $principal);
        $this->assertStringNotContainsString('1 = 0', $query->toSql());
        $this->assertStringContainsString('"example_rows"."legal_entity_id" in (?)', $query->toSql());
        $this->assertSame(['company-b'], $query->getBindings());
    }

    public function test_company_mode_is_part_of_the_fingerprint(): void
    {
        $missing = $this->createStub(AnalyticsPrincipal::class);
        $cross = $this->createStub(CrossCompanyPrincipal::class);
        foreach ([$missing, $cross] as $principal) {
            $principal->method('tenantId')->willReturn('tenant-a');
            $principal->method('lockedFilters')->willReturn([]);
            $principal->method('mayUsePersonalData')->willReturn(false);
        }
        $this->assertNotSame(ScopeFingerprint::of($missing, 'example', null, 'legal_entity_id'), ScopeFingerprint::of($cross, 'example', null, 'legal_entity_id'));
        $this->assertSame(ScopeFingerprint::of($missing, 'shared', null), ScopeFingerprint::of($cross, 'shared', null));
    }

    public function test_company_context_separates_fingerprints_only_for_company_datasets(): void
    {
        $first = $this->createStub(CompanyContextPrincipal::class);
        $second = $this->createStub(CompanyContextPrincipal::class);
        foreach ([$first, $second] as $principal) {
            $principal->method('tenantId')->willReturn('tenant-a');
            $principal->method('lockedFilters')->willReturn([]);
            $principal->method('mayUsePersonalData')->willReturn(false);
        }
        $first->method('activeLegalEntityId')->willReturn('company-a');
        $second->method('activeLegalEntityId')->willReturn('company-b');
        $this->assertNotSame(ScopeFingerprint::of($first, 'example', null, 'legal_entity_id'), ScopeFingerprint::of($second, 'example', null, 'legal_entity_id'));
        $this->assertSame(ScopeFingerprint::of($first, 'shared', null), ScopeFingerprint::of($second, 'shared', null));
    }

    /** @param array{code: string, legal_entity: ?string, operating_unit: ?string}|null $policy */
    private function dataset(?string $companyColumn, bool $querySource = false, ?array $policy = null): CompiledDataset
    {
        return new CompiledDataset(
            code: 'example', caption: 'Example', moduleId: 'example', version: 1,
            model: Model::class, table: $querySource ? CompiledDataset::SOURCE_ALIAS : 'example_rows', permission: 'example.read', policy: $policy,
            fields: [], measures: [], times: [], defaultTime: null,
            source: $querySource ? static fn () => (new class extends Model {})->newQuery() : null,
            companyColumn: $companyColumn,
        );
    }
}
