<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Modules;

use App\Platform\Modules\Contracts\CompanyContextFilter;
use App\Platform\Modules\Contracts\DataPolicyFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompanyContextFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_company_narrows_both_unrestricted_and_multi_company_grants(): void
    {
        DB::statement('create temporary table company_context_rows (id text primary key, company_id text, unit_id text)');
        DB::table('company_context_rows')->insert([
            ['id' => 'a1', 'company_id' => 'a', 'unit_id' => 'one'],
            ['id' => 'a2', 'company_id' => 'a', 'unit_id' => 'two'],
            ['id' => 'b1', 'company_id' => 'b', 'unit_id' => 'one'],
        ]);

        $scopes = [
            ['all' => true, 'scope_grants' => []],
            ['all' => false, 'scope_grants' => [
                ['legal_entity_id' => 'a', 'operating_unit_ids' => ['one']],
                ['legal_entity_id' => 'b', 'operating_unit_ids' => ['one']],
            ]],
        ];
        foreach ($scopes as $scope) {
            foreach (['a', 'b', null] as $company) {
                $query = CompanyContextFilter::apply(DB::table('company_context_rows'), 'company_id', $company);
                DataPolicyFilter::apply($query, $scope, 'company_id', 'unit_id');
                $expected = $company === null ? [] : ($company === 'b' ? ['b1'] : ($scope['all'] ? ['a1', 'a2'] : ['a1']));
                $this->assertSame($expected, $query->orderBy('id')->pluck('id')->all());
            }
        }
    }

    public function test_active_company_does_not_expand_authorization(): void
    {
        DB::statement('create temporary table denied_company_rows (id text primary key, company_id text)');
        DB::table('denied_company_rows')->insert(['id' => 'a1', 'company_id' => 'a']);
        $query = CompanyContextFilter::apply(DB::table('denied_company_rows'), 'company_id', 'a');
        DataPolicyFilter::apply($query, ['all' => false, 'scope_grants' => [
            ['legal_entity_id' => 'b', 'operating_unit_ids' => []],
        ]], 'company_id', null);
        $this->assertSame([], $query->pluck('id')->all());
    }

    public function test_writing_another_company_is_rejected_even_when_the_caller_can_access_it(): void
    {
        $this->expectException(ValidationException::class);
        CompanyContextFilter::require('a', 'b');
    }

    public function test_writing_without_active_company_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        CompanyContextFilter::require(null, 'a');
    }

    public function test_writing_the_active_company_passes_the_context_check(): void
    {
        CompanyContextFilter::require('a', 'a');
        $this->addToAssertionCount(1);
    }
}
